<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Raintyyek\Ocr\Models\OcrCall;
use Raintyyek\Ocr\OcrService;

/**
 * Executes a scheduled {@see OcrCall} in the background.
 *
 * The job carries only the call's primary key — never the image bytes — so the
 * queue payload stays tiny and the source is re-resolved from the stored
 * reference (path, URL, S3, or spooled disk file) at run time.
 *
 * Retries and backoff come from `ocr.scheduling.queue`. After the service
 * records a failure on the call, the job re-throws so the queue can retry; once
 * attempts are exhausted, {@see failed()} leaves a final note on the record.
 */
final class ProcessOcrCall implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int|string $callId,
    ) {
    }

    /**
     * Number of attempts, sourced from config so operators tune it in one place.
     */
    public function tries(): int
    {
        return (int) config('ocr.scheduling.queue.tries', 3);
    }

    /**
     * Seconds to wait between attempts.
     */
    public function backoff(): int
    {
        return (int) config('ocr.scheduling.queue.backoff', 30);
    }

    public function handle(OcrService $service): void
    {
        $call = OcrCall::find($this->callId);

        // The record may have been pruned/cancelled between dispatch and run.
        if ($call === null) {
            return;
        }

        $call = $service->process($call);

        // Surface failures to the queue so its retry policy kicks in.
        $call->throwIfFailed();
    }

    /**
     * Called when every attempt has failed. Records the terminal error, in case
     * the last attempt died before the service could persist it.
     */
    public function failed(\Throwable $e): void
    {
        $call = OcrCall::find($this->callId);

        $call?->markFailed('Job exhausted retries: ' . $e->getMessage());
    }
}
