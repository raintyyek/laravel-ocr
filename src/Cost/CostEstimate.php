<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Cost;

/**
 * An immutable, computed cost for a single OCR call.
 *
 * Amounts are plain floats in the estimate's currency. Keeping the unit count
 * and unit price alongside the total makes the figure auditable — you can see
 * exactly how it was derived rather than just the bottom line.
 */
final class CostEstimate
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
        public readonly int $units,
        public readonly float $unitPrice,
    ) {
    }

    /** A zero-cost estimate (e.g. when pricing is not configured for an engine). */
    public static function zero(string $currency): self
    {
        return new self(0.0, $currency, 0, 0.0);
    }

    /**
     * @return array{amount: float, currency: string, units: int, unit_price: float}
     */
    public function toArray(): array
    {
        return [
            'amount'     => $this->amount,
            'currency'   => $this->currency,
            'units'      => $this->units,
            'unit_price' => $this->unitPrice,
        ];
    }
}
