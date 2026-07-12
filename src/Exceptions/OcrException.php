<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Exceptions;

use RuntimeException;

/**
 * Base type for every exception thrown by this library. Catch this to handle
 * any OCR failure generically; catch a subclass for finer-grained control.
 */
class OcrException extends RuntimeException
{
}
