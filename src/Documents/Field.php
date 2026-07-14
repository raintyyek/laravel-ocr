<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Documents;

use Raintyyek\Ocr\DTO\BoundingBox;

/**
 * A single extracted datum together with its provenance.
 *
 * Financial extraction is probabilistic, so every field records not just the
 * (normalized) value but the model's **confidence**, the **raw text** it was
 * read from, and **where** on the page it sat. That lets consumers threshold
 * low-confidence fields, show the source to a human reviewer, or highlight it on
 * the original image.
 *
 * `value` is the normalized value — a string, a {@see Money}, a `Y-m-d` date
 * string, a number — depending on the field.
 */
final class Field
{
    public function __construct(
        public readonly mixed $value,
        public readonly ?float $confidence = null,
        public readonly ?string $rawText = null,
        public readonly ?BoundingBox $boundingBox = null,
    ) {
    }

    /** Whether a non-empty value is present. */
    public function isPresent(): bool
    {
        return $this->value !== null
            && $this->value !== ''
            && ! ($this->value instanceof Money && ! $this->value->isPresent());
    }

    /** Whether the field is present and meets a minimum confidence. */
    public function isConfident(float $min): bool
    {
        return $this->isPresent() && ($this->confidence ?? 1.0) >= $min;
    }

    /**
     * @return array{value: mixed, confidence: float|null, raw_text: string|null, bounding_box: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'value'        => $this->value instanceof Money ? $this->value->toArray() : $this->value,
            'confidence'   => $this->confidence,
            'raw_text'     => $this->rawText,
            'bounding_box' => $this->boundingBox?->toArray(),
        ];
    }
}
