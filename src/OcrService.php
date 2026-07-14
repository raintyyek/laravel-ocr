<?php

declare(strict_types=1);

namespace Raintyyek\Ocr;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Raintyyek\Ocr\Contracts\OcrEngine;
use Raintyyek\Ocr\Cost\CostCalculator;
use Raintyyek\Ocr\Documents\ExtractedDocument;
use Raintyyek\Ocr\DTO\OcrResult;
use Raintyyek\Ocr\Enums\OcrStatus;
use Raintyyek\Ocr\Exceptions\OcrConfigurationException;
use Raintyyek\Ocr\Jobs\ProcessOcrCall;
use Raintyyek\Ocr\Models\OcrCall;
use Raintyyek\Ocr\Support\ImageSource;
use Throwable;

/**
 * The high-level entry point of the library (the {@see \Raintyyek\Ocr\Facades\Ocr}
 * facade resolves to this).
 *
 * It orchestrates the full lifecycle around the low-level engines:
 *
 *   1. Normalize any accepted input into an {@see ImageSource}.
 *   2. Record the call ({@see OcrCall}) for auditing and cost tracking.
 *   3. Either run it inline or hand it off to the background, per config.
 *   4. On execution, invoke the engine, compute cost, and persist the result.
 *
 * Two entry points serve different needs:
 *   - {@see run()}       — the full, persisted, cost-tracked, schedulable flow.
 *   - {@see recognize()} — a stateless one-shot returning a raw {@see OcrResult}.
 */
class OcrService
{
    public function __construct(
        private readonly OcrManager $engines,
        private readonly CostCalculator $cost,
        private readonly Config $config,
        private readonly ExtractorManager $extractors,
    ) {
    }

    /**
     * Resolve a raw engine (bypassing persistence). Handy for testing or when
     * you deliberately want no side effects.
     */
    public function engine(?string $name = null): OcrEngine
    {
        return $this->engines->engine($name);
    }

    /**
     * Extract structured data (invoice/receipt/… fields) from a document.
     *
     * Routes to the paid provider extractor (AWS AnalyzeExpense / Google Document
     * AI) when enabled for the engine via `ocr.extraction`, otherwise uses the
     * free heuristic extractor over standard OCR. Pass `['extractor' => …]` to
     * force a specific one.
     *
     * @param  ImageSource|string   $source  An ImageSource, path, URL, or "s3://…".
     * @param  array<string, mixed> $options e.g. `engine`, `extractor`, `as`, `date_locale`.
     */
    public function extract(ImageSource|string $source, array $options = []): ExtractedDocument
    {
        $engine = (string) ($options['engine'] ?? $this->config->get('ocr.default', 'google'));
        $image  = $this->normalizeSource($source);

        return $this->extractors->for($engine, $options)->extract($image, $options);
    }

    /**
     * Stateless recognition: run the given source through an engine and return
     * the raw result. Nothing is stored and cost is not computed.
     *
     * @param  ImageSource|string   $source  An ImageSource, path, URL, or "s3://…".
     * @param  array<string, mixed> $options Per-call options; `engine` selects the driver.
     */
    public function recognize(ImageSource|string $source, array $options = []): OcrResult
    {
        [$engine, $options] = $this->extractEngine($options);
        $image = $this->normalizeSource($source);

        return $this->engine($engine)->recognize(
            $image,
            $this->applyS3Passthrough($engine, $image, $options),
        );
    }

    /**
     * The full flow: record the call, then run it now or schedule it for later
     * depending on `ocr.scheduling.enabled`. Always returns the {@see OcrCall}.
     *
     * When scheduling is off, the returned call is already Completed (or Failed)
     * and carries the result and cost. When on, it is returned Pending and the
     * work runs in the background.
     *
     * @param  ImageSource|string   $source  An ImageSource, path, URL, or "s3://…".
     * @param  array<string, mixed> $options Per-call options; `engine` selects the driver.
     */
    public function run(ImageSource|string $source, array $options = []): OcrCall
    {
        if (! $this->databaseEnabled()) {
            throw new OcrConfigurationException(
                'Ocr::run() requires ocr.database.enabled = true. Use Ocr::recognize() for a stateless call.'
            );
        }

        [$engine, $options] = $this->extractEngine($options);
        $image     = $this->normalizeSource($source);
        $scheduled = $this->schedulingEnabled();

        // Scheduled calls execute later in another process, so any in-memory
        // bytes must be spooled to a disk the worker can read.
        if ($scheduled) {
            $image = $this->spoolIfNeeded($image);
        }

        $call = $this->createCall($engine, $image, $options, $scheduled);

        if (! $scheduled) {
            return $this->process($call, $image);
        }

        $this->handOff($call);

        return $call;
    }

    /**
     * Execute a recorded call against its engine and persist the outcome. This
     * is the single execution path shared by inline runs, the queued job, and
     * the cron command — so behaviour is identical however a call is triggered.
     *
     * Failures are recorded on the call (status → Failed) rather than thrown, so
     * the caller can inspect the record; the queued job re-throws afterwards to
     * trigger its retry policy.
     *
     * @param OcrCall          $call  The (persisted) call to run.
     * @param ImageSource|null $image A live source to use instead of rebuilding
     *                                from the stored reference (inline runs).
     */
    public function process(OcrCall $call, ?ImageSource $image = null): OcrCall
    {
        $call->markProcessing();

        try {
            $image ??= $call->toImageSource();
            $options = $this->applyS3Passthrough($call->engine, $image, $call->options ?? []);

            $result = $this->engine($call->engine)->recognize($image, $options);
            $cost   = $this->cost->forResult($call->engine, $result);

            $call->markCompleted($result, $cost);
        } catch (Throwable $e) {
            $call->markFailed($e->getMessage());
        }

        return $call;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Coerce accepted input into an {@see ImageSource}. Strings are auto-typed
     * (s3://, http(s)://, existing path, else raw bytes); S3 objects resolve
     * against the disk configured in `ocr.s3.disk`.
     */
    private function normalizeSource(ImageSource|string $source): ImageSource
    {
        return $source instanceof ImageSource
            ? $source
            : ImageSource::make($source);
    }

    /**
     * Pull the `engine` key out of the options, defaulting to the configured
     * engine. Returned separately so it never leaks into the provider payload.
     *
     * @param  array<string, mixed> $options
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function extractEngine(array $options): array
    {
        $engine = (string) ($options['engine'] ?? $this->config->get('ocr.default', 'google'));
        unset($options['engine']);

        return [$engine, $options];
    }

    /**
     * Let AWS Textract read an S3 object in place instead of downloading it,
     * by translating our source into Textract's S3Object option. No-op for
     * other engines or non-S3 sources, and never overrides an explicit option.
     *
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function applyS3Passthrough(string $engine, ImageSource $image, array $options): array
    {
        if ($engine !== 'aws' || isset($options['s3']) || ($s3 = $image->s3()) === null) {
            return $options;
        }

        $options['s3'] = [
            'bucket'  => $s3['bucket'],
            'name'    => $s3['key'],
            'version' => $s3['version'],
        ];

        return $options;
    }

    /**
     * Persist bytes-in-memory to the spool disk so a scheduled worker can read
     * them later, returning a Storage-backed source. Other source kinds already
     * reference durable locations and pass through untouched.
     */
    private function spoolIfNeeded(ImageSource $image): ImageSource
    {
        if (! $image->isInMemory()) {
            return $image;
        }

        $disk = (string) $this->config->get('ocr.scheduling.spool.disk', 'local');
        $dir  = trim((string) $this->config->get('ocr.scheduling.spool.path', 'ocr-spool'), '/');
        $path = $dir . '/' . Str::uuid()->toString();

        Storage::disk($disk)->put($path, $image->bytes());

        return ImageSource::fromStorage($disk, $path);
    }

    /**
     * Create and persist the pending call record.
     *
     * @param array<string, mixed> $options
     */
    private function createCall(string $engine, ImageSource $image, array $options, bool $scheduled): OcrCall
    {
        $call = new OcrCall();
        $call->engine      = $engine;
        $call->options     = $options ?: null;
        $call->source_type = $image->type()->value;
        // In-memory bytes are not retained for non-scheduled calls; store just
        // the kind so the record still reflects how the call was made.
        $call->source      = $image->isInMemory() ? ['type' => $image->type()->value] : $image->describe();
        $call->scheduled   = $scheduled;
        $call->status      = OcrStatus::Pending;
        $call->appendLog('info', $scheduled ? 'Call scheduled.' : 'Call created.');
        $call->save();

        return $call;
    }

    /**
     * Hand a scheduled call off to the configured background driver.
     */
    private function handOff(OcrCall $call): void
    {
        $driver = (string) $this->config->get('ocr.scheduling.driver', 'queue');

        if ($driver === 'queue') {
            $queue = (array) $this->config->get('ocr.scheduling.queue', []);

            ProcessOcrCall::dispatch($call->getKey())
                ->onConnection($queue['connection'] ?? null)
                ->onQueue($queue['queue'] ?? null);

            $call->appendLog('info', 'Dispatched to queue.')->save();

            return;
        }

        // "cron" driver: leave the call pending for `ocr:process-pending`.
        $call->appendLog('info', 'Awaiting cron processing (ocr:process-pending).')->save();
    }

    private function databaseEnabled(): bool
    {
        return (bool) $this->config->get('ocr.database.enabled', true);
    }

    private function schedulingEnabled(): bool
    {
        return (bool) $this->config->get('ocr.scheduling.enabled', false);
    }
}
