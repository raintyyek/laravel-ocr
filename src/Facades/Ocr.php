<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Facades;

use Illuminate\Support\Facades\Facade;
use Raintyyek\Ocr\Contracts\OcrEngine;
use Raintyyek\Ocr\DTO\OcrResult;
use Raintyyek\Ocr\Models\OcrCall;
use Raintyyek\Ocr\OcrService;
use Raintyyek\Ocr\Support\ImageSource;

/**
 * Facade for the OCR service.
 *
 * @method static OcrCall   run(ImageSource|string $source, array $options = [])
 * @method static OcrResult recognize(ImageSource|string $source, array $options = [])
 * @method static OcrCall   process(OcrCall $call, ?ImageSource $image = null)
 * @method static OcrEngine engine(?string $name = null)
 *
 * @see OcrService
 */
final class Ocr extends Facade
{
    /**
     * The container binding key the facade resolves to.
     */
    protected static function getFacadeAccessor(): string
    {
        return OcrService::class;
    }
}
