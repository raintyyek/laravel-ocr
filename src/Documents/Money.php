<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Documents;

/**
 * An immutable monetary amount.
 *
 * The amount is kept as a **decimal string** (e.g. "1234.50"), never a float —
 * money must not suffer binary-floating-point rounding. Use {@see toFloat()}
 * only for display or tolerant comparisons, never for storing balances.
 */
final class Money
{
    public function __construct(
        public readonly ?string $amount,
        public readonly ?string $currency = null,
    ) {
    }

    /** Whether a numeric amount is present. */
    public function isPresent(): bool
    {
        return $this->amount !== null && $this->amount !== '';
    }

    /**
     * The amount as a float — for display and tolerant reconciliation only.
     * Returns null when no amount is set.
     */
    public function toFloat(): ?float
    {
        return $this->isPresent() ? (float) $this->amount : null;
    }

    /**
     * @return array{amount: string|null, currency: string|null}
     */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }
}
