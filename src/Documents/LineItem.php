<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Documents;

/**
 * A single line on an invoice/receipt: what was bought, how many, and for how
 * much. Every field is optional because providers (and scans) vary in what they
 * expose per row.
 */
final class LineItem
{
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?float $quantity = null,
        public readonly ?string $unit = null,
        public readonly ?Money $unitPrice = null,
        public readonly ?Money $amount = null,
        public readonly ?Money $tax = null,
        /** Product code / SKU. */
        public readonly ?string $sku = null,
        /** Model confidence for the row (0.0–1.0). */
        public readonly ?float $confidence = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'quantity'    => $this->quantity,
            'unit'        => $this->unit,
            'unit_price'  => $this->unitPrice?->toArray(),
            'amount'      => $this->amount?->toArray(),
            'tax'         => $this->tax?->toArray(),
            'sku'         => $this->sku,
            'confidence'  => $this->confidence,
        ];
    }
}
