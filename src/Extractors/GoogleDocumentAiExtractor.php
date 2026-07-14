<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Extractors;

use Google\Cloud\DocumentAI\V1\Client\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\ProcessRequest;
use Google\Cloud\DocumentAI\V1\RawDocument;
use Raintyyek\Ocr\Contracts\DocumentExtractor;
use Raintyyek\Ocr\Documents\ExtractedDocument;
use Raintyyek\Ocr\Documents\Field;
use Raintyyek\Ocr\Documents\LineItem;
use Raintyyek\Ocr\Documents\Money;
use Raintyyek\Ocr\Documents\Party;
use Raintyyek\Ocr\Documents\PaymentInfo;
use Raintyyek\Ocr\Documents\TaxLine;
use Raintyyek\Ocr\Enums\DocumentType;
use Raintyyek\Ocr\Exceptions\OcrConfigurationException;
use Raintyyek\Ocr\Exceptions\OcrProcessingException;
use Raintyyek\Ocr\Support\FieldNormalizer;
use Raintyyek\Ocr\Support\ImageSource;
use Throwable;

/**
 * Structured extractor backed by **Google Document AI** (Invoice / Expense
 * processor). Like AWS AnalyzeExpense, it analyses the image directly (OCR is
 * internal) and returns rich entities, which are mapped onto the shared
 * {@see ExtractedDocument}.
 *
 * Enabled via `ocr.extraction.google.document_ai` and requires a deployed
 * processor (`project_id`, `location`, `processor_id`); when disabled the free
 * heuristic extractor is used instead. Billing note: Google charges per document
 * in **10-page blocks** (min ~USD 0.10/doc), unlike AWS's per-page pricing.
 *
 * The mapping ({@see mapDocument()}) is pure and unit-tested against a captured
 * response (the Document AI JSON shape); only {@see extract()} touches the SDK.
 *
 * @see https://cloud.google.com/document-ai/docs/processors-list
 */
final class GoogleDocumentAiExtractor implements DocumentExtractor
{
    /**
     * @param array<string, mixed> $config     The `ocr.extraction.google` block.
     * @param array<string, mixed> $extraction  The `ocr.extraction` options (locale, …).
     */
    public function __construct(
        private readonly array $config = [],
        private readonly array $extraction = [],
    ) {
    }

    public function name(): string
    {
        return 'google_docai';
    }

    public function extract(ImageSource $image, array $options = []): ExtractedDocument
    {
        if (! class_exists(DocumentProcessorServiceClient::class)) {
            throw OcrConfigurationException::missingSdk('google_docai', 'google/cloud-document-ai');
        }

        foreach (['project_id', 'location', 'processor_id'] as $key) {
            if (empty($this->config[$key])) {
                throw OcrConfigurationException::missingCredentials('google_docai', "config 'ocr.extraction.google.{$key}' is required.");
            }
        }

        try {
            $client = new DocumentProcessorServiceClient($this->clientOptions());
            $name   = $client->processorName($this->config['project_id'], $this->config['location'], $this->config['processor_id']);

            $request = (new ProcessRequest())
                ->setName($name)
                ->setRawDocument(
                    (new RawDocument())
                        ->setContent($image->bytes())
                        ->setMimeType($this->mimeType($image->bytes()))
                );

            $document = $client->processDocument($request)->getDocument();
            $decoded  = json_decode($document->serializeToJsonString(), true) ?: [];

            return $this->mapDocument($decoded, $options);
        } catch (OcrConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw OcrProcessingException::from($this->name(), $e);
        }
    }

    /**
     * Map a Document AI `Document` (decoded JSON) into an {@see ExtractedDocument}.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $options
     */
    public function mapDocument(array $document, array $options = []): ExtractedDocument
    {
        $scalars   = [];
        $lineItems = [];

        foreach ($document['entities'] ?? [] as $entity) {
            $type = (string) ($entity['type'] ?? '');

            if ($type === 'line_item') {
                $lineItems[] = $entity;
            } elseif ($type !== '' && ! isset($scalars[$type])) {
                $scalars[$type] = $entity; // first occurrence wins
            }
        }

        $dayFirst = $this->isDayFirst($options);
        $currency = ($this->value($scalars, 'currency')['text'] ?? null)
            ?? ($this->moneyOf($scalars['total_amount'] ?? null, null)?->currency)
            ?? ($options['currency'] ?? ($this->extraction['currency'] ?? null));

        $taxField = $this->money($scalars, 'total_tax_amount', $currency);

        return new ExtractedDocument(
            type: $this->detectType($options),
            currency: $currency,
            vendor: $this->party($scalars, 'supplier_name', 'supplier_address', 'supplier_tax_id', 'supplier_phone', 'supplier_email', 'supplier_website'),
            customer: $this->party($scalars, 'receiver_name', 'receiver_address'),
            invoiceNumber: $this->text($scalars, 'invoice_id'),
            poNumber: $this->text($scalars, 'purchase_order'),
            issueDate: $this->date($scalars, 'invoice_date', $dayFirst) ?? $this->date($scalars, 'receipt_date', $dayFirst),
            dueDate: $this->date($scalars, 'due_date', $dayFirst),
            subtotal: $this->money($scalars, 'net_amount', $currency),
            taxTotal: $taxField,
            shipping: $this->money($scalars, 'freight_amount', $currency),
            total: $this->money($scalars, 'total_amount', $currency),
            taxes: $taxField !== null ? [new TaxLine('TAX', null, $taxField->value)] : [],
            lineItems: $this->lineItems($lineItems, $currency),
            payment: $this->payment($scalars),
            meta: ['extractor' => $this->name()],
        );
    }

    // ---------------------------------------------------------------------
    // Mapping helpers
    // ---------------------------------------------------------------------

    private function detectType(array $options): DocumentType
    {
        if (isset($options['as'])) {
            return $options['as'] instanceof DocumentType
                ? $options['as']
                : (DocumentType::tryFrom((string) $options['as']) ?? DocumentType::Unknown);
        }

        return DocumentType::Invoice;
    }

    /** @param array<string, array<string, mixed>> $scalars */
    private function text(array $scalars, string $type): ?Field
    {
        $value = $this->value($scalars, $type);

        return $value === null ? null : new Field($value['text'], $value['confidence'], $value['text']);
    }

    /** @param array<string, array<string, mixed>> $scalars */
    private function date(array $scalars, string $type, bool $dayFirst): ?Field
    {
        $entity = $scalars[$type] ?? null;
        if ($entity === null) {
            return null;
        }

        $dv = $entity['normalizedValue']['dateValue'] ?? null;
        $normalized = ! empty($dv['year'])
            ? sprintf('%04d-%02d-%02d', $dv['year'], $dv['month'] ?? 1, $dv['day'] ?? 1)
            : FieldNormalizer::date((string) ($entity['mentionText'] ?? ''), $dayFirst);

        $raw = (string) ($entity['mentionText'] ?? '');

        return new Field($normalized ?? $raw, $this->confidence($entity), $raw !== '' ? $raw : null);
    }

    /** @param array<string, array<string, mixed>> $scalars */
    private function money(array $scalars, string $type, ?string $currency): ?Field
    {
        $entity = $scalars[$type] ?? null;
        $money  = $this->moneyOf($entity, $currency);

        return $money === null ? null : new Field($money, $this->confidence($entity), (string) ($entity['mentionText'] ?? '') ?: null);
    }

    /** Read a Document AI money entity (normalized moneyValue, else mentionText). */
    private function moneyOf(?array $entity, ?string $fallbackCurrency): ?Money
    {
        if ($entity === null) {
            return null;
        }

        $mv = $entity['normalizedValue']['moneyValue'] ?? null;

        if ($mv !== null && (isset($mv['units']) || isset($mv['nanos']))) {
            $amount = (float) ($mv['units'] ?? 0) + ((int) ($mv['nanos'] ?? 0)) / 1_000_000_000;

            return new Money(FieldNormalizer::format($amount), $mv['currencyCode'] ?? $fallbackCurrency);
        }

        return FieldNormalizer::money((string) ($entity['mentionText'] ?? ''), $fallbackCurrency);
    }

    /** @param array<string, array<string, mixed>> $scalars */
    private function party(
        array $scalars,
        string $nameType,
        ?string $addressType = null,
        ?string $taxIdType = null,
        ?string $phoneType = null,
        ?string $emailType = null,
        ?string $websiteType = null,
    ): ?Party {
        $party = new Party(
            $this->value($scalars, $nameType)['text'] ?? null,
            $addressType ? ($this->value($scalars, $addressType)['text'] ?? null) : null,
            $taxIdType ? ($this->value($scalars, $taxIdType)['text'] ?? null) : null,
            $phoneType ? ($this->value($scalars, $phoneType)['text'] ?? null) : null,
            $emailType ? ($this->value($scalars, $emailType)['text'] ?? null) : null,
            $websiteType ? ($this->value($scalars, $websiteType)['text'] ?? null) : null,
        );

        return $party->isPresent() ? $party : null;
    }

    /** @param array<string, array<string, mixed>> $scalars */
    private function payment(array $scalars): ?PaymentInfo
    {
        $terms = $this->value($scalars, 'payment_terms')['text'] ?? null;

        return $terms === null ? null : new PaymentInfo(terms: $terms);
    }

    /**
     * @param  list<array<string, mixed>> $entities
     * @return list<LineItem>
     */
    private function lineItems(array $entities, ?string $currency): array
    {
        $items = [];

        foreach ($entities as $entity) {
            $props = [];
            foreach ($entity['properties'] ?? [] as $prop) {
                $type = (string) ($prop['type'] ?? '');
                if ($type !== '' && ! isset($props[$type])) {
                    $props[$type] = $prop;
                }
            }

            $description = $props['line_item/description']['mentionText'] ?? $entity['mentionText'] ?? null;
            if ($description === null || trim((string) $description) === '') {
                continue;
            }

            $qty = $props['line_item/quantity']['mentionText'] ?? null;

            $items[] = new LineItem(
                description: trim((string) $description),
                quantity: $qty !== null ? (float) $qty : null,
                unit: isset($props['line_item/unit']) ? trim((string) $props['line_item/unit']['mentionText']) : null,
                unitPrice: $this->moneyOf($props['line_item/unit_price'] ?? null, $currency),
                amount: $this->moneyOf($props['line_item/amount'] ?? null, $currency),
                sku: isset($props['line_item/product_code']) ? trim((string) $props['line_item/product_code']['mentionText']) : null,
                confidence: $this->confidence($entity),
            );
        }

        return $items;
    }

    /**
     * @param  array<string, array<string, mixed>> $scalars
     * @return array{text: string, confidence: float|null}|null
     */
    private function value(array $scalars, string $type): ?array
    {
        $entity = $scalars[$type] ?? null;
        $text   = $entity['normalizedValue']['text'] ?? $entity['mentionText'] ?? null;

        if ($text === null || trim((string) $text) === '') {
            return null;
        }

        return ['text' => trim((string) $text), 'confidence' => $this->confidence($entity)];
    }

    private function confidence(?array $entity): ?float
    {
        return isset($entity['confidence']) ? (float) $entity['confidence'] : null;
    }

    private function isDayFirst(array $options): bool
    {
        $locale = strtolower((string) ($options['date_locale'] ?? $this->extraction['date_locale'] ?? 'en_MY'));

        return ! str_starts_with($locale, 'en_us');
    }

    // ---------------------------------------------------------------------
    // Google plumbing
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function clientOptions(): array
    {
        $options = [];

        if (! empty($this->config['credentials_json'])) {
            $decoded = json_decode((string) $this->config['credentials_json'], true);
            if (is_array($decoded)) {
                $options['credentials'] = $decoded;
            }
        } elseif (! empty($this->config['credentials_path'])) {
            $options['credentials'] = $this->config['credentials_path'];
        }

        // Document AI is regional; point the endpoint at the processor's location.
        $location = (string) ($this->config['location'] ?? 'us');
        if ($location !== 'us') {
            $options['apiEndpoint'] = "{$location}-documentai.googleapis.com";
        }

        return $options;
    }

    private function mimeType(string $bytes): string
    {
        return match (true) {
            str_starts_with($bytes, '%PDF')                 => 'application/pdf',
            str_starts_with($bytes, "\x89PNG")              => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF")         => 'image/jpeg',
            str_starts_with($bytes, 'II*') || str_starts_with($bytes, 'MM') => 'image/tiff',
            default                                          => 'image/jpeg',
        };
    }
}
