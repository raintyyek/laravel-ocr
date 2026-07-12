<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\DTO;

use Raintyyek\Ocr\Enums\BlockType;

/**
 * A single unit of recognized text (a word, line, paragraph, …) together with
 * its confidence and position. Immutable by design: results should be treated
 * as a read-only snapshot of what the provider returned.
 */
final class TextBlock
{
    /**
     * @param string           $text        The recognized text.
     * @param BlockType        $type        Structural granularity of this block.
     * @param float|null       $confidence  Provider confidence normalized to 0.0–1.0,
     *                                       or null when the engine does not report it.
     * @param BoundingBox|null $boundingBox Position on the page, when available.
     */
    public function __construct(
        public readonly string $text,
        public readonly BlockType $type,
        public readonly ?float $confidence = null,
        public readonly ?BoundingBox $boundingBox = null,
    ) {
    }

    /**
     * @return array{
     *     text: string,
     *     type: string,
     *     confidence: float|null,
     *     bounding_box: array<string, mixed>|null
     * }
     */
    public function toArray(): array
    {
        return [
            'text'         => $this->text,
            'type'         => $this->type->value,
            'confidence'   => $this->confidence,
            'bounding_box' => $this->boundingBox?->toArray(),
        ];
    }
}
