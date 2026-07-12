<?php

declare(strict_types=1);

namespace Raintyyek\Ocr;

use Illuminate\Support\Manager;
use InvalidArgumentException;
use Raintyyek\Ocr\Contracts\OcrEngine;
use Raintyyek\Ocr\DTO\OcrResult;
use Raintyyek\Ocr\Engines\Aws\AwsTextractEngine;
use Raintyyek\Ocr\Engines\Google\GoogleVisionEngine;
use Raintyyek\Ocr\Support\ImageSource;

/**
 * The public entry point of the library.
 *
 * Extends Laravel's {@see Manager} to get lazy, cached, driver-based
 * resolution for free: each engine is instantiated once, on first use, and
 * `engine('google')` / `engine('aws')` picks between them. The manager also
 * forwards {@see OcrEngine} calls to the default driver, so most callers can
 * simply write `Ocr::recognize($image)`.
 *
 * @mixin OcrEngine
 */
class OcrManager extends Manager implements OcrEngine
{
    /**
     * The configured default engine name (from config/ocr.php).
     */
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('ocr.default', 'google');
    }

    /**
     * Fluent alias for {@see driver()} that reads better at call sites and
     * documents the return type as an {@see OcrEngine}.
     */
    public function engine(?string $name = null): OcrEngine
    {
        /** @var OcrEngine $engine */
        $engine = $this->driver($name);

        return $engine;
    }

    /**
     * {@inheritDoc}
     *
     * Recognize using the default engine. Provided so the manager itself
     * satisfies {@see OcrEngine} and can be type-hinted anywhere an engine is
     * expected.
     */
    public function recognize(ImageSource $image, array $options = []): OcrResult
    {
        return $this->engine()->recognize($image, $options);
    }

    public function name(): string
    {
        return $this->getDefaultDriver();
    }

    /**
     * Factory for the Google Cloud Vision engine. Called automatically by the
     * parent Manager the first time the "google" driver is requested.
     */
    protected function createGoogleDriver(): OcrEngine
    {
        return new GoogleVisionEngine(
            config: $this->engineConfig('google'),
            defaults: $this->requestDefaults(),
        );
    }

    /**
     * Factory for the AWS Textract engine. Called automatically by the parent
     * Manager the first time the "aws" driver is requested.
     */
    protected function createAwsDriver(): OcrEngine
    {
        return new AwsTextractEngine(
            config: $this->engineConfig('aws'),
            defaults: $this->requestDefaults(),
        );
    }

    /**
     * Guard against typos / unregistered engines with a clear message rather
     * than the framework's generic "Driver not supported".
     */
    protected function createDriver($driver)
    {
        try {
            return parent::createDriver($driver);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(sprintf(
                'OCR engine [%s] is not supported. Configure one of: %s.',
                $driver,
                implode(', ', array_keys((array) $this->config->get('ocr.engines', []))),
            ), previous: $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function engineConfig(string $engine): array
    {
        return (array) $this->config->get("ocr.engines.{$engine}", []);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestDefaults(): array
    {
        return (array) $this->config->get('ocr.defaults', []);
    }
}
