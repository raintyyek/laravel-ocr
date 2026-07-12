<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Exceptions;

use Throwable;

/**
 * Thrown when a recognition request fails at runtime — a rejected API call, a
 * network error, or an unreadable image. Wraps the provider's own exception so
 * callers get a consistent type while retaining the original cause.
 */
class OcrProcessingException extends OcrException
{
    public static function from(string $engine, Throwable $previous): self
    {
        return new self(
            sprintf('The "%s" OCR engine failed to process the image: %s', $engine, $previous->getMessage()),
            (int) $previous->getCode(),
            $previous,
        );
    }
}
