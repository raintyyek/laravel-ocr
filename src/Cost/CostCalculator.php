<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Cost;

use Raintyyek\Ocr\DTO\OcrResult;

/**
 * Turns the pricing table (config `ocr.pricing`) into a concrete cost for a
 * call. The formula is deliberately simple and transparent:
 *
 *     amount = max(units, minimum_units) × unit_price
 *
 * where "units" is the number of pages the engine reported (at least 1). Engines
 * with no configured price yield a zero estimate rather than throwing, so cost
 * tracking never blocks recognition.
 */
final class CostCalculator
{
    /**
     * @param array<string, mixed> $pricing The `ocr.pricing` configuration block.
     */
    public function __construct(
        private readonly array $pricing = [],
    ) {
    }

    /**
     * Compute the cost of a completed result for the given engine.
     */
    public function forResult(string $engine, OcrResult $result): CostEstimate
    {
        return $this->forUnits($engine, $this->billableUnits($result));
    }

    /**
     * Compute cost directly from a known unit count. Useful for pre-flight
     * estimates before a call is actually made.
     */
    public function forUnits(string $engine, int $units): CostEstimate
    {
        $currency = (string) ($this->pricing['currency'] ?? 'USD');
        $rate     = $this->pricing['engines'][$engine] ?? null;

        if ($rate === null) {
            return CostEstimate::zero($currency);
        }

        $unitPrice   = (float) ($rate['unit_price'] ?? 0.0);
        $minimum     = (int) ($rate['minimum_units'] ?? 1);
        $billable    = max($units, $minimum);

        return new CostEstimate(
            amount: round($billable * $unitPrice, 6),
            currency: $currency,
            units: $billable,
            unitPrice: $unitPrice,
        );
    }

    /**
     * Derive the billable unit count from a result. We bill per page reported by
     * the provider (single images count as one page).
     */
    private function billableUnits(OcrResult $result): int
    {
        return max(1, (int) ($result->meta['pages'] ?? 1));
    }
}
