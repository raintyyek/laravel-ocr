<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Raintyyek\Ocr\Cost\CostEstimate;
use Raintyyek\Ocr\DTO\BoundingBox;
use Raintyyek\Ocr\DTO\OcrResult;
use Raintyyek\Ocr\DTO\Point;
use Raintyyek\Ocr\DTO\TextBlock;
use Raintyyek\Ocr\Enums\BlockType;
use Raintyyek\Ocr\Enums\OcrStatus;
use Raintyyek\Ocr\Exceptions\OcrProcessingException;
use Raintyyek\Ocr\Support\ImageSource;

/**
 * A durable record of one OCR call: its source, options, lifecycle status,
 * per-step logs, result and computed cost.
 *
 * The model is the audit trail *and* the work item — scheduled calls are stored
 * "pending" and later executed in place, so the same row travels from request
 * to result. Rich helpers ({@see markProcessing()}, {@see markCompleted()},
 * {@see appendLog()}) keep that lifecycle logic in one place.
 *
 * @property int         $id
 * @property string      $uuid
 * @property string      $engine
 * @property OcrStatus   $status
 * @property string|null $source_type
 * @property array|null  $source
 * @property array|null  $options
 * @property string|null $text
 * @property array|null  $blocks
 * @property array|null  $meta
 * @property int|null    $pages
 * @property float|null  $average_confidence
 * @property float|null  $cost
 * @property string|null $cost_currency
 * @property int|null    $cost_units
 * @property string|null $error
 * @property array       $logs
 * @property bool        $scheduled
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property int|null    $duration_ms
 */
class OcrCall extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status'             => OcrStatus::class,
            'source'             => 'array',
            'options'            => 'array',
            'blocks'             => 'array',
            'meta'               => 'array',
            'logs'               => 'array',
            'pages'              => 'integer',
            'average_confidence' => 'float',
            'cost'               => 'float',
            'cost_units'         => 'integer',
            'scheduled'          => 'boolean',
            'duration_ms'        => 'integer',
            'started_at'         => 'datetime',
            'completed_at'       => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Assign a UUID and a sane default status on creation.
        static::creating(function (OcrCall $call): void {
            $call->uuid ??= (string) Str::uuid();
            $call->status ??= OcrStatus::Pending;
            $call->logs ??= [];
        });
    }

    public function getTable(): string
    {
        return (string) config('ocr.database.table', 'ocr_calls');
    }

    public function getConnectionName(): ?string
    {
        return config('ocr.database.connection') ?? parent::getConnectionName();
    }

    // ---------------------------------------------------------------------
    // Lifecycle transitions
    // ---------------------------------------------------------------------

    /** Mark the call as in-flight and stamp the start time. */
    public function markProcessing(): self
    {
        $this->status     = OcrStatus::Processing;
        $this->started_at = $this->freshTimestamp();
        $this->appendLog('info', 'Processing started.');
        $this->save();

        return $this;
    }

    /** Persist a successful result together with its computed cost. */
    public function markCompleted(OcrResult $result, CostEstimate $cost): self
    {
        $this->status             = OcrStatus::Completed;
        $this->text               = $result->text;
        $this->blocks             = array_map(static fn (TextBlock $b) => $b->toArray(), $result->blocks);
        $this->meta               = $result->meta;
        $this->pages              = (int) ($result->meta['pages'] ?? 1);
        $this->average_confidence = $result->averageConfidence();
        $this->cost               = $cost->amount;
        $this->cost_currency      = $cost->currency;
        $this->cost_units         = $cost->units;
        $this->completed_at       = $this->freshTimestamp();
        $this->duration_ms        = $this->elapsedMs();
        $this->appendLog('info', sprintf(
            'Completed: %d block(s), cost %s %.6f.',
            count($result->blocks),
            $cost->currency,
            $cost->amount,
        ));
        $this->save();

        return $this;
    }

    /** Record a failure with its message for later inspection / retry. */
    public function markFailed(string $message): self
    {
        $this->status       = OcrStatus::Failed;
        $this->error        = $message;
        $this->completed_at = $this->freshTimestamp();
        $this->duration_ms  = $this->elapsedMs();
        $this->appendLog('error', $message);
        $this->save();

        return $this;
    }

    /**
     * Append a structured entry to the call's log trail. Kept in-memory until
     * the next save() so callers can batch multiple entries per transition.
     */
    public function appendLog(string $level, string $message): self
    {
        $logs   = $this->logs ?? [];
        $logs[] = [
            'at'      => $this->freshTimestamp()->toIso8601String(),
            'level'   => $level,
            'message' => $message,
        ];
        $this->logs = $logs;

        return $this;
    }

    /** Throw if the call ended in failure — handy after a synchronous run. */
    public function throwIfFailed(): self
    {
        if ($this->status === OcrStatus::Failed) {
            throw new OcrProcessingException($this->error ?? 'OCR call failed.');
        }

        return $this;
    }

    // ---------------------------------------------------------------------
    // Conversions
    // ---------------------------------------------------------------------

    /** Rebuild the {@see ImageSource} this call was created from. */
    public function toImageSource(): ImageSource
    {
        return ImageSource::fromReference((array) $this->source);
    }

    /**
     * Reconstruct a provider-agnostic {@see OcrResult} from stored columns.
     * Returns null when the call has not completed. Bounding boxes and block
     * types are rehydrated so consumers get the same shape as a live call.
     */
    public function toOcrResult(): ?OcrResult
    {
        if ($this->status !== OcrStatus::Completed) {
            return null;
        }

        $blocks = array_map(function (array $b): TextBlock {
            $box = null;

            if (! empty($b['bounding_box']['vertices'])) {
                $box = new BoundingBox(array_map(
                    static fn (array $p) => new Point((float) $p['x'], (float) $p['y']),
                    $b['bounding_box']['vertices'],
                ));
            }

            return new TextBlock(
                text: (string) $b['text'],
                type: BlockType::from($b['type']),
                confidence: isset($b['confidence']) ? (float) $b['confidence'] : null,
                boundingBox: $box,
            );
        }, $this->blocks ?? []);

        return new OcrResult(
            engine: $this->engine,
            text: (string) $this->text,
            blocks: $blocks,
            raw: null, // Raw provider payloads are intentionally not persisted.
            meta: $this->meta ?? [],
        );
    }

    // ---------------------------------------------------------------------
    // Query scopes
    // ---------------------------------------------------------------------

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', OcrStatus::Pending->value);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', OcrStatus::Completed->value);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', OcrStatus::Failed->value);
    }

    // ---------------------------------------------------------------------
    // Internal
    // ---------------------------------------------------------------------

    /** Milliseconds elapsed since the call started, or null if never started. */
    private function elapsedMs(): ?int
    {
        return $this->started_at
            ? (int) round($this->started_at->diffInMilliseconds($this->freshTimestamp()))
            : null;
    }
}
