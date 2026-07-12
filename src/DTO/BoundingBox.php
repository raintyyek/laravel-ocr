<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\DTO;

/**
 * An immutable polygon describing where a piece of text sits on the page.
 *
 * All vertices are normalized {@see Point}s (0.0–1.0). Engines may report an
 * arbitrary polygon (e.g. a skewed quadrilateral), so the vertices are kept
 * verbatim; the axis-aligned helpers derive a bounding rectangle on demand.
 */
final class BoundingBox
{
    /**
     * @param list<Point> $vertices Ordered polygon vertices (typically 4).
     */
    public function __construct(
        public readonly array $vertices,
    ) {
    }

    /** Left edge of the axis-aligned bounding rectangle (0.0–1.0). */
    public function left(): float
    {
        return $this->min(fn (Point $p) => $p->x);
    }

    /** Top edge of the axis-aligned bounding rectangle (0.0–1.0). */
    public function top(): float
    {
        return $this->min(fn (Point $p) => $p->y);
    }

    /** Width of the axis-aligned bounding rectangle (0.0–1.0). */
    public function width(): float
    {
        return $this->max(fn (Point $p) => $p->x) - $this->left();
    }

    /** Height of the axis-aligned bounding rectangle (0.0–1.0). */
    public function height(): float
    {
        return $this->max(fn (Point $p) => $p->y) - $this->top();
    }

    /**
     * @return array{
     *     vertices: list<array{x: float, y: float}>,
     *     left: float, top: float, width: float, height: float
     * }
     */
    public function toArray(): array
    {
        return [
            'vertices' => array_map(static fn (Point $p) => $p->toArray(), $this->vertices),
            'left'     => $this->left(),
            'top'      => $this->top(),
            'width'    => $this->width(),
            'height'   => $this->height(),
        ];
    }

    /**
     * @param callable(Point): float $accessor
     */
    private function min(callable $accessor): float
    {
        return $this->vertices === [] ? 0.0 : min(array_map($accessor, $this->vertices));
    }

    /**
     * @param callable(Point): float $accessor
     */
    private function max(callable $accessor): float
    {
        return $this->vertices === [] ? 0.0 : max(array_map($accessor, $this->vertices));
    }
}
