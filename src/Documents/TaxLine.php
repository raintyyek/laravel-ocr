<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Documents;

/**
 * A single tax component on a document (e.g. VAT/GST/SST), with its optional
 * rate and amount. A document may carry several (mixed-rate invoices).
 */
final class TaxLine
{
    public function __construct(
        /** Tax label/type, e.g. "SST", "GST", "VAT". */
        public readonly ?string $type = null,
        /** Rate as a percentage, e.g. 6.0 for 6%. */
        public readonly ?float $rate = null,
        /** Tax amount. */
        public readonly ?Money $amount = null,
    ) {
    }

    /**
     * @return array{type: string|null, rate: float|null, amount: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'type'   => $this->type,
            'rate'   => $this->rate,
            'amount' => $this->amount?->toArray(),
        ];
    }
}
