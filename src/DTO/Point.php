<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\DTO;

/**
 * An immutable 2D coordinate, expressed as a fraction (0.0–1.0) of the image's
 * width (x) and height (y).
 *
 * Normalizing to fractions — rather than raw pixels — keeps geometry meaningful
 * even when the caller does not know the source resolution, and makes results
 * comparable across engines that report coordinates differently.
 */
final class Point
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
    ) {
    }

    /**
     * @return array{x: float, y: float}
     */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }
}
