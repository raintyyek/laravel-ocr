<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Contracts;

use Raintyyek\Ocr\DTO\OcrResult;
use Raintyyek\Ocr\Support\ImageSource;

/**
 * The single contract every OCR engine (Google, AWS, or a future provider)
 * must satisfy. Application code should depend on this interface — never on a
 * concrete engine — so providers stay swappable via configuration.
 */
interface OcrEngine
{
    /**
     * Run text recognition over an image and return a provider-agnostic result.
     *
     * @param  ImageSource          $image   The image to analyse.
     * @param  array<string, mixed> $options Per-call overrides (e.g. language
     *                                        hints, min_confidence). Merged over
     *                                        the engine's configured defaults.
     *
     * @throws \Raintyyek\Ocr\Exceptions\OcrException On any recognition failure.
     */
    public function recognize(ImageSource $image, array $options = []): OcrResult;

    /**
     * The engine's short identifier (e.g. "google", "aws"). Used for logging
     * and to stamp the originating engine onto each {@see OcrResult}.
     */
    public function name(): string;
}
