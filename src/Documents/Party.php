<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Documents;

/**
 * A party on the document — the vendor/supplier/merchant, or the
 * customer/bill-to/receiver. All fields optional.
 */
final class Party
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $address = null,
        /** Tax registration id (VAT/GST/SST/company no.). */
        public readonly ?string $taxId = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?string $website = null,
    ) {
    }

    /** Whether any identifying detail is present. */
    public function isPresent(): bool
    {
        return $this->name !== null
            || $this->address !== null
            || $this->taxId !== null
            || $this->email !== null;
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'name'    => $this->name,
            'address' => $this->address,
            'tax_id'  => $this->taxId,
            'phone'   => $this->phone,
            'email'   => $this->email,
            'website' => $this->website,
        ];
    }
}
