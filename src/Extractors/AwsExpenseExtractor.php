<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Extractors;

use Aws\Textract\TextractClient;
use Raintyyek\Ocr\Contracts\DocumentExtractor;
use Raintyyek\Ocr\Documents\ExtractedDocument;
use Raintyyek\Ocr\Documents\Field;
use Raintyyek\Ocr\Documents\LineItem;
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
 * Structured extractor backed by **AWS Textract `AnalyzeExpense`** — the paid,
 * purpose-built API for invoices and receipts (~USD 0.01/page, OCR included).
 *
 * Unlike the heuristic extractor, this does NOT consume our OCR text: it sends
 * the image (bytes or an S3 object) straight to AnalyzeExpense, which performs
 * OCR and field extraction internally and returns SummaryFields + LineItemGroups.
 * The response — including per-field confidence — is mapped onto the shared
 * {@see ExtractedDocument}. Enabled via `ocr.extraction.aws.analyze_expense`;
 * when disabled, the free heuristic extractor is used instead.
 *
 * The mapping ({@see mapExpenseDocument()}) is pure and unit-tested against a
 * captured response; only {@see extract()} touches the SDK/network.
 *
 * @see https://docs.aws.amazon.com/textract/latest/dg/API_AnalyzeExpense.html
 */
final class AwsExpenseExtractor implements DocumentExtractor
{
    private ?TextractClient $client = null;

    /**
     * @param array<string, mixed> $config     The `ocr.engines.aws` credentials block.
     * @param array<string, mixed> $extraction  The `ocr.extraction` options (locale, …).
     */
    public function __construct(
        private readonly array $config = [],
        private readonly array $extraction = [],
    ) {
    }

    public function name(): string
    {
        return 'aws_expense';
    }

    public function extract(ImageSource $image, array $options = []): ExtractedDocument
    {
        try {
            $result = $this->client()->analyzeExpense([
                'Document' => $this->buildDocument($image, $options),
            ]);

            $documents = $result->get('ExpenseDocuments') ?? [];

            return $this->mapExpenseDocument($documents[0] ?? [], $options);
        } catch (OcrConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw OcrProcessingException::from($this->name(), $e);
        }
    }

    /**
     * Map a single AnalyzeExpense `ExpenseDocument` into an {@see ExtractedDocument}.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $options
     */
    public function mapExpenseDocument(array $doc, array $options = []): ExtractedDocument
    {
        // Index summary fields by their normalized type (e.g. TOTAL, DUE_DATE).
        $summary = [];
        foreach ($doc['SummaryFields'] ?? [] as $field) {
            $type = strtoupper((string) ($field['Type']['Text'] ?? ''));
            if ($type !== '') {
                $summary[$type] = $field;
            }
        }

        $currency = $this->summaryCurrency($summary) ?? ($options['currency'] ?? ($this->extraction['currency'] ?? null));
        $dayFirst = $this->isDayFirst($options);

        $tax = $this->money($summary, 'TAX', $currency);

        return new ExtractedDocument(
            type: $this->detectType($summary, $options),
            currency: $currency,
            vendor: $this->party($summary, 'VENDOR_NAME', 'VENDOR_ADDRESS', 'TAX_PAYER_ID', 'VENDOR_PHONE', null, 'VENDOR_URL'),
            customer: $this->party($summary, 'RECEIVER_NAME', 'RECEIVER_ADDRESS'),
            invoiceNumber: $this->text($summary, 'INVOICE_RECEIPT_ID'),
            poNumber: $this->text($summary, 'PO_NUMBER'),
            issueDate: $this->date($summary, 'INVOICE_RECEIPT_DATE', $dayFirst),
            dueDate: $this->date($summary, 'DUE_DATE', $dayFirst),
            subtotal: $this->money($summary, 'SUBTOTAL', $currency),
            taxTotal: $tax,
            discountTotal: $this->money($summary, 'DISCOUNT', $currency),
            shipping: $this->money($summary, 'SHIPPING_HANDLING_CHARGE', $currency),
            total: $this->money($summary, 'TOTAL', $currency),
            amountPaid: $this->money($summary, 'AMOUNT_PAID', $currency),
            balanceDue: $this->money($summary, 'AMOUNT_DUE', $currency),
            taxes: $tax !== null ? [new TaxLine('TAX', null, $tax->value)] : [],
            lineItems: $this->lineItems($doc['LineItemGroups'] ?? [], $currency),
            payment: $this->payment($summary),
            meta: ['extractor' => $this->name()],
        );
    }

    // ---------------------------------------------------------------------
    // Mapping helpers
    // ---------------------------------------------------------------------

    /** @param array<string, array<string, mixed>> $summary */
    private function detectType(array $summary, array $options): DocumentType
    {
        if (isset($options['as'])) {
            return $options['as'] instanceof DocumentType
                ? $options['as']
                : (DocumentType::tryFrom((string) $options['as']) ?? DocumentType::Unknown);
        }

        // AnalyzeExpense doesn't classify; infer from what it found.
        return isset($summary['DUE_DATE']) || isset($summary['PO_NUMBER'])
            ? DocumentType::Invoice
            : DocumentType::Receipt;
    }

    /** @param array<string, array<string, mixed>> $summary */
    private function text(array $summary, string $type): ?Field
    {
        $value = $this->value($summary, $type);

        return $value === null ? null : new Field($value['text'], $value['confidence'], $value['text']);
    }

    /** @param array<string, array<string, mixed>> $summary */
    private function date(array $summary, string $type, bool $dayFirst): ?Field
    {
        $value = $this->value($summary, $type);

        if ($value === null) {
            return null;
        }

        return new Field(FieldNormalizer::date($value['text'], $dayFirst) ?? $value['text'], $value['confidence'], $value['text']);
    }

    /** @param array<string, array<string, mixed>> $summary */
    private function money(array $summary, string $type, ?string $currency): ?Field
    {
        $value = $this->value($summary, $type);

        if ($value === null) {
            return null;
        }

        $money = FieldNormalizer::money($value['text'], $value['currency'] ?? $currency);

        return $money === null ? null : new Field($money, $value['confidence'], $value['text']);
    }

    /** @param array<string, array<string, mixed>> $summary */
    private function party(
        array $summary,
        string $nameType,
        ?string $addressType = null,
        ?string $taxIdType = null,
        ?string $phoneType = null,
        ?string $emailType = null,
        ?string $websiteType = null,
    ): ?Party {
        $name    = $this->value($summary, $nameType)['text'] ?? null;
        $address = $addressType ? ($this->value($summary, $addressType)['text'] ?? null) : null;
        $taxId   = $taxIdType ? ($this->value($summary, $taxIdType)['text'] ?? null) : null;
        $phone   = $phoneType ? ($this->value($summary, $phoneType)['text'] ?? null) : null;
        $email   = $emailType ? ($this->value($summary, $emailType)['text'] ?? null) : null;
        $website = $websiteType ? ($this->value($summary, $websiteType)['text'] ?? null) : null;

        $party = new Party($name, $address, $taxId, $phone, $email, $website);

        return $party->isPresent() ? $party : null;
    }

    /** @param array<string, array<string, mixed>> $summary */
    private function payment(array $summary): ?PaymentInfo
    {
        $terms = $this->value($summary, 'PAYMENT_TERMS')['text'] ?? null;

        return $terms === null ? null : new PaymentInfo(terms: $terms);
    }

    /**
     * @param  list<array<string, mixed>> $groups
     * @return list<LineItem>
     */
    private function lineItems(array $groups, ?string $currency): array
    {
        $items = [];

        foreach ($groups as $group) {
            foreach ($group['LineItems'] ?? [] as $line) {
                $by = [];
                foreach ($line['LineItemExpenseFields'] ?? [] as $field) {
                    $type = strtoupper((string) ($field['Type']['Text'] ?? ''));
                    if ($type !== '') {
                        $by[$type] = $field;
                    }
                }

                $description = $this->lineText($by, 'ITEM') ?? $this->lineText($by, 'EXPENSE_ROW');
                if ($description === null) {
                    continue;
                }

                $items[] = new LineItem(
                    description: trim($description),
                    quantity: ($q = $this->lineText($by, 'QUANTITY')) !== null ? (float) $q : null,
                    unitPrice: FieldNormalizer::money($this->lineText($by, 'UNIT_PRICE') ?? '', $currency),
                    amount: FieldNormalizer::money($this->lineText($by, 'PRICE') ?? '', $currency),
                    sku: $this->lineText($by, 'PRODUCT_CODE'),
                    confidence: $this->lineConfidence($by, 'PRICE'),
                );
            }
        }

        return $items;
    }

    /**
     * Read a summary field's value, confidence (0–1) and currency.
     *
     * @param  array<string, array<string, mixed>> $summary
     * @return array{text: string, confidence: float|null, currency: string|null}|null
     */
    private function value(array $summary, string $type): ?array
    {
        $field = $summary[$type] ?? null;
        $text  = $field['ValueDetection']['Text'] ?? null;

        if ($text === null || trim((string) $text) === '') {
            return null;
        }

        return [
            'text'       => trim((string) $text),
            'confidence' => isset($field['ValueDetection']['Confidence']) ? ((float) $field['ValueDetection']['Confidence']) / 100 : null,
            'currency'   => $field['Currency']['Code'] ?? null,
        ];
    }

    /** @param array<string, array<string, mixed>> $by */
    private function lineText(array $by, string $type): ?string
    {
        $text = $by[$type]['ValueDetection']['Text'] ?? null;

        return $text === null || trim((string) $text) === '' ? null : trim((string) $text);
    }

    /** @param array<string, array<string, mixed>> $by */
    private function lineConfidence(array $by, string $type): ?float
    {
        return isset($by[$type]['ValueDetection']['Confidence'])
            ? ((float) $by[$type]['ValueDetection']['Confidence']) / 100
            : null;
    }

    /** @param array<string, array<string, mixed>> $summary */
    private function summaryCurrency(array $summary): ?string
    {
        foreach (['TOTAL', 'SUBTOTAL', 'AMOUNT_DUE'] as $type) {
            if (! empty($summary[$type]['Currency']['Code'])) {
                return (string) $summary[$type]['Currency']['Code'];
            }
        }

        return null;
    }

    private function isDayFirst(array $options): bool
    {
        $locale = strtolower((string) ($options['date_locale'] ?? $this->extraction['date_locale'] ?? 'en_MY'));

        return ! str_starts_with($locale, 'en_us');
    }

    // ---------------------------------------------------------------------
    // AWS plumbing
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildDocument(ImageSource $image, array $options): array
    {
        $s3 = $image->s3();

        if ($s3 !== null) {
            return ['S3Object' => array_filter([
                'Bucket'  => $s3['bucket'],
                'Name'    => $s3['key'],
                'Version' => $s3['version'],
            ], static fn ($v) => $v !== null)];
        }

        return ['Bytes' => $image->bytes()];
    }

    private function client(): TextractClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (! class_exists(TextractClient::class)) {
            throw OcrConfigurationException::missingSdk('aws_expense', 'aws/aws-sdk-php');
        }

        $args = [
            'version' => $this->config['version'] ?? 'latest',
            'region'  => $this->config['region'] ?? 'us-east-1',
        ];

        if (! empty($this->config['key']) && ! empty($this->config['secret'])) {
            $args['credentials'] = array_filter([
                'key'    => $this->config['key'],
                'secret' => $this->config['secret'],
                'token'  => $this->config['token'] ?? null,
            ], static fn ($v) => $v !== null);
        }

        return $this->client = new TextractClient($args);
    }
}
