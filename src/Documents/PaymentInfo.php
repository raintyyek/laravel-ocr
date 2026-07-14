<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Documents;

/**
 * Payment details — most relevant to receipts and payment slips (bank transfer
 * confirmations, gateway receipts). Fields like {@see $method}, {@see $reference}
 * and {@see $transactionId} typically come from the heuristic extractor, since
 * cloud invoice parsers rarely surface them.
 */
final class PaymentInfo
{
    public function __construct(
        /** e.g. "cash", "card", "bank_transfer", "e_wallet", "cheque". */
        public readonly ?string $method = null,
        /** Human-facing payment reference / approval code. */
        public readonly ?string $reference = null,
        /** Gateway/bank transaction id. */
        public readonly ?string $transactionId = null,
        /** Payment terms text, e.g. "NET 30". */
        public readonly ?string $terms = null,
        /** Last 4 digits of a card, when shown. */
        public readonly ?string $cardLast4 = null,
        public readonly ?string $bankName = null,
        public readonly ?string $accountNumber = null,
        /** True/false/null (unknown) whether the document is marked paid. */
        public readonly ?bool $paid = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method'         => $this->method,
            'reference'      => $this->reference,
            'transaction_id' => $this->transactionId,
            'terms'          => $this->terms,
            'card_last4'     => $this->cardLast4,
            'bank_name'      => $this->bankName,
            'account_number' => $this->accountNumber,
            'paid'           => $this->paid,
        ];
    }
}
