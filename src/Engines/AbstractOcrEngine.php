<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Engines;

use Raintyyek\Ocr\Contracts\OcrEngine;
use Raintyyek\Ocr\DTO\OcrResult;
use Raintyyek\Ocr\DTO\TextBlock;

/**
 * Shared behaviour for concrete engines: configuration access, option merging,
 * and confidence filtering. Concrete engines only implement the provider call
 * and the mapping of its response into our {@see OcrResult} shape.
 */
abstract class AbstractOcrEngine implements OcrEngine
{
    /**
     * @param array<string, mixed> $config   Engine-specific configuration.
     * @param array<string, mixed> $defaults Library-wide request defaults.
     */
    public function __construct(
        protected readonly array $config = [],
        protected readonly array $defaults = [],
    ) {
    }

    /**
     * Merge per-call options over the configured defaults, giving the caller
     * the final say on a single request without mutating shared config.
     *
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function resolveOptions(array $options): array
    {
        return array_merge($this->defaults, $this->config, $options);
    }

    /**
     * Discard blocks below the effective confidence threshold. Blocks without a
     * reported confidence are always kept — absence of a score is not evidence
     * of a bad read.
     *
     * @param  list<TextBlock>      $blocks
     * @param  array<string, mixed> $options
     * @return list<TextBlock>
     */
    protected function applyConfidenceFilter(array $blocks, array $options): array
    {
        $min = (float) ($options['min_confidence'] ?? 0.0);

        if ($min <= 0.0) {
            return $blocks;
        }

        return array_values(array_filter(
            $blocks,
            static fn (TextBlock $b) => $b->confidence === null || $b->confidence >= $min,
        ));
    }
}
