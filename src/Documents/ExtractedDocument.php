<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Documents;

use Raintyyek\Ocr\DTO\OcrResult;
use Raintyyek\Ocr\Enums\DocumentType;

/**
 * The structured result of understanding a financial document.
 *
 * This is the single, provider-agnostic shape every {@see \Raintyyek\Ocr\Contracts\DocumentExtractor}
 * returns — so downstream code (AP posting, reconciliation, review UIs) never
 * branches on which extractor produced it. Scalar fields are wrapped in
 * {@see Field} to carry per-field confidence and provenance; repeating and
 * grouped data use the dedicated value objects.
 *
 * All fields are nullable/empty by default: real documents omit things, and a
 * partial extraction is still useful.
 */
final class ExtractedDocument
{
    /**
     * @param list<TaxLine>        $taxes
     * @param list<LineItem>       $lineItems
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly DocumentType $type = DocumentType::Unknown,
        public readonly ?string $currency = null,
        // Parties
        public readonly ?Party $vendor = null,
        public readonly ?Party $customer = null,
        // Identifiers
        public readonly ?Field $invoiceNumber = null,
        public readonly ?Field $poNumber = null,
        public readonly ?Field $accountNumber = null,
        // Dates (Field value = 'Y-m-d' string)
        public readonly ?Field $issueDate = null,
        public readonly ?Field $dueDate = null,
        public readonly ?Field $paymentDate = null,
        public readonly ?Field $serviceDate = null,
        // Amounts (Field value = Money)
        public readonly ?Field $subtotal = null,
        public readonly ?Field $taxTotal = null,
        public readonly ?Field $discountTotal = null,
        public readonly ?Field $shipping = null,
        public readonly ?Field $total = null,
        public readonly ?Field $amountPaid = null,
        public readonly ?Field $balanceDue = null,
        // Collections
        public readonly array $taxes = [],
        public readonly array $lineItems = [],
        // Payment
        public readonly ?PaymentInfo $payment = null,
        // Provenance
        public readonly ?OcrResult $source = null,
        public readonly array $meta = [],
    ) {
    }

    /**
     * Whether the arithmetic reconciles: subtotal + tax − discount + shipping ≈
     * total, within a small tolerance. Returns null when there isn't enough data
     * to judge (rather than a misleading false).
     */
    public function isBalanced(float $tolerance = 0.02): ?bool
    {
        $total = $this->amount($this->total);

        // Need a total and at least a subtotal to attempt a check.
        if ($total === null || $this->amount($this->subtotal) === null) {
            return null;
        }

        $sum = ($this->amount($this->subtotal) ?? 0.0)
            + ($this->amount($this->taxTotal) ?? 0.0)
            + ($this->amount($this->shipping) ?? 0.0)
            - ($this->amount($this->discountTotal) ?? 0.0);

        return abs($sum - $total) <= $tolerance;
    }

    /**
     * Names of scalar fields that are present but below a confidence threshold —
     * i.e. the fields a human should double-check.
     *
     * @return list<string>
     */
    public function confidenceBelow(float $threshold): array
    {
        $flagged = [];

        foreach ($this->scalarFields() as $name => $field) {
            if ($field instanceof Field && $field->isPresent() && ! $field->isConfident($threshold)) {
                $flagged[] = $name;
            }
        }

        return $flagged;
    }

    /**
     * A plain, JSON-safe array (source OCR omitted — large and engine-specific).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $fields = [];

        foreach ($this->scalarFields() as $name => $field) {
            $fields[$name] = $field?->toArray();
        }

        return [
            'type'       => $this->type->value,
            'currency'   => $this->currency,
            'vendor'     => $this->vendor?->toArray(),
            'customer'   => $this->customer?->toArray(),
            'fields'     => $fields,
            'taxes'      => array_map(static fn (TaxLine $t) => $t->toArray(), $this->taxes),
            'line_items' => array_map(static fn (LineItem $i) => $i->toArray(), $this->lineItems),
            'payment'    => $this->payment?->toArray(),
            'balanced'   => $this->isBalanced(),
            'meta'       => $this->meta,
        ];
    }

    /**
     * The scalar (Field-wrapped) attributes, keyed by name. Used by the
     * confidence and serialization helpers.
     *
     * @return array<string, Field|null>
     */
    private function scalarFields(): array
    {
        return [
            'invoice_number' => $this->invoiceNumber,
            'po_number'      => $this->poNumber,
            'account_number' => $this->accountNumber,
            'issue_date'     => $this->issueDate,
            'due_date'       => $this->dueDate,
            'payment_date'   => $this->paymentDate,
            'service_date'   => $this->serviceDate,
            'subtotal'       => $this->subtotal,
            'tax_total'      => $this->taxTotal,
            'discount_total' => $this->discountTotal,
            'shipping'       => $this->shipping,
            'total'          => $this->total,
            'amount_paid'    => $this->amountPaid,
            'balance_due'    => $this->balanceDue,
        ];
    }

    /** Extract a float amount from a money-bearing field, or null. */
    private function amount(?Field $field): ?float
    {
        return $field?->value instanceof Money ? $field->value->toFloat() : null;
    }
}
