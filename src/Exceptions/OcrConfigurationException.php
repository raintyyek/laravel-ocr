<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Exceptions;

/**
 * Thrown when an engine is misconfigured or its SDK is missing — i.e. problems
 * that are the operator's to fix (bad credentials, absent composer package,
 * unknown driver) rather than transient runtime failures.
 */
class OcrConfigurationException extends OcrException
{
    public static function missingSdk(string $engine, string $package): self
    {
        return new self(sprintf(
            'The "%s" OCR engine requires the "%s" package. Run: composer require %s',
            $engine,
            $package,
            $package,
        ));
    }

    public static function missingCredentials(string $engine, string $detail): self
    {
        return new self(sprintf(
            'The "%s" OCR engine is missing credentials: %s',
            $engine,
            $detail,
        ));
    }
}
