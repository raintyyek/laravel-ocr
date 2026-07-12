<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\DTO;

use Raintyyek\Ocr\Enums\BlockType;

/**
 * The provider-agnostic result of an OCR run.
 *
 * This is the single shape every engine returns, so downstream code never has
 * to branch on which provider produced the data. It exposes the full text plus
 * the structured blocks, and keeps the untouched raw payload for callers that
 * need engine-specific fields.
 */
final class OcrResult
{
    /**
     * @param string          $engine   Identifier of the engine that produced this result.
     * @param string          $text     Full recognized text, in reading order.
     * @param list<TextBlock> $blocks   Structured text units (words, lines, …).
     * @param mixed           $raw      The untouched provider response, for advanced use.
     * @param array<string, mixed> $meta Extra provider metadata (page count, language, …).
     */
    public function __construct(
        public readonly string $engine,
        public readonly string $text,
        public readonly array $blocks = [],
        public readonly mixed $raw = null,
        public readonly array $meta = [],
    ) {
    }

    /**
     * Whether any text was recognized at all.
     */
    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    /**
     * Return only the blocks of a given granularity (e.g. just the lines).
     *
     * @return list<TextBlock>
     */
    public function blocksOfType(BlockType $type): array
    {
        return array_values(
            array_filter($this->blocks, static fn (TextBlock $b) => $b->type === $type)
        );
    }

    /**
     * The mean confidence across all blocks that reported one, or null when
     * none did. Useful as a quick quality gate on the whole result.
     */
    public function averageConfidence(): ?float
    {
        $scores = array_values(array_filter(
            array_map(static fn (TextBlock $b) => $b->confidence, $this->blocks),
            static fn (?float $c) => $c !== null,
        ));

        return $scores === [] ? null : array_sum($scores) / count($scores);
    }

    /**
     * Serialize to a plain array (raw payload omitted — it is engine-specific
     * and often not serializable). Safe for JSON responses and logging.
     *
     * @return array{
     *     engine: string,
     *     text: string,
     *     average_confidence: float|null,
     *     blocks: list<array<string, mixed>>,
     *     meta: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'engine'             => $this->engine,
            'text'               => $this->text,
            'average_confidence' => $this->averageConfidence(),
            'blocks'             => array_map(static fn (TextBlock $b) => $b->toArray(), $this->blocks),
            'meta'               => $this->meta,
        ];
    }
}
