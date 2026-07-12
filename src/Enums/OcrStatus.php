<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Enums;

/**
 * Lifecycle status of a persisted {@see \Raintyyek\Ocr\Models\OcrCall}.
 *
 *   Pending    → recorded, awaiting execution (scheduled runs sit here).
 *   Processing → an engine call is currently in flight.
 *   Completed  → finished successfully; text, blocks and cost are populated.
 *   Failed     → the engine call errored; see the `error` column and logs.
 */
enum OcrStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Completed  = 'completed';
    case Failed     = 'failed';

    /** Whether this is a terminal (no longer changing) state. */
    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
