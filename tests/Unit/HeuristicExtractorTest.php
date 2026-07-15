<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Raintyyek\Ocr\DTO\OcrResult;
use Raintyyek\Ocr\Enums\DocumentType;
use Raintyyek\Ocr\Extractors\HeuristicExtractor;
use Raintyyek\Ocr\OcrManager;

final class HeuristicExtractorTest extends TestCase
{
    public function test_multilingual_corpus_meets_100_percent_target_field_accuracy(): void
    {
        /** @var list<array{name: string, locale: string, text: string, expected: array<string, scalar>}> $corpus */
        $corpus = require dirname(__DIR__) . '/Fixtures/FinancialDocumentCorpus.php';
        $passed = $total = 0;
        $failures = [];

        foreach ($corpus as $case) {
            $document = $this->parse($case['text'], $case['locale']);

            foreach ($case['expected'] as $field => $expected) {
                $actual = $this->value($document, $field);
                $ok = $field === 'line_items_min' ? $actual >= $expected : $actual === $expected;
                $total++;

                if ($ok) {
                    $passed++;
                } else {
                    $failures[] = sprintf('%s.%s expected %s, got %s', $case['name'], $field, var_export($expected, true), var_export($actual, true));
                }
            }
        }

        $accuracy = $passed / $total;
        self::assertSame(
            1.0,
            $accuracy,
            implode("\n", $failures) . sprintf("\nAccuracy: %d/%d (%.2f%%)", $passed, $total, $accuracy * 100),
        );
    }

    public function test_mobile_payment_result_with_grouped_labels(): void
    {
        $text = "12:44 PM\nPayment Result\nRM 45.00\nPaid\nMerchant\nDate & Time\n"
            . "BusOnlineTicket\n26/10/2023 12:45:09\neWallet Reference\nNo.\n"
            . "202310262112128001101714035177\nPayment Method\neWallet Balance\nDone";

        $document = $this->parse($text);

        self::assertSame(DocumentType::PaymentSlip, $document->type);
        self::assertSame('MYR', $document->currency);
        self::assertSame('BusOnlineTicket', $document->vendor?->name);
        self::assertSame('45.00', $document->amountPaid?->value->amount);
        self::assertSame('45.00', $document->total?->value->amount);
        self::assertSame('2023-10-26 12:45:09', $document->paymentDate?->value);
        self::assertSame('202310262112128001101714035177', $document->payment?->reference);
        self::assertSame('e_wallet', $document->payment?->method);
        self::assertTrue($document->payment?->paid);

        // Eight correct target fields out of eight for this layout; the broader
        // corpus test enforces the package-wide 100% target.
        $expected = [
            DocumentType::PaymentSlip,
            'MYR',
            'BusOnlineTicket',
            '45.00',
            '2023-10-26 12:45:09',
            '202310262112128001101714035177',
            'e_wallet',
            true,
        ];
        $actual = [
            $document->type,
            $document->currency,
            $document->vendor?->name,
            $document->amountPaid?->value->amount,
            $document->paymentDate?->value,
            $document->payment?->reference,
            $document->payment?->method,
            $document->payment?->paid,
        ];

        $correct = count(array_filter(array_map(static fn ($a, $e) => $a === $e, $actual, $expected)));
        self::assertSame(1.0, $correct / count($expected));
    }

    public function test_invoice_fields_and_line_items_remain_supported(): void
    {
        $document = $this->parse(<<<'OCR'
ACME Supplies Sdn Bhd
Tax Invoice
Invoice No: INV-2026-088
Date: 15/07/2026
Due Date: 14/08/2026
Description  Qty  Unit Price  Amount
Printer Paper  2  RM 10.00  RM 20.00
Subtotal RM 20.00
SST 8% RM 1.60
Grand Total RM 21.60
OCR);

        self::assertSame(DocumentType::Invoice, $document->type);
        self::assertSame('ACME Supplies Sdn Bhd', $document->vendor?->name);
        self::assertSame('INV-2026-088', $document->invoiceNumber?->value);
        self::assertSame('2026-07-15', $document->issueDate?->value);
        self::assertSame('2026-08-14', $document->dueDate?->value);
        self::assertSame('21.60', $document->total?->value->amount);
        self::assertNotEmpty($document->lineItems);
    }

    public function test_receipt_and_bill_document_types_and_totals(): void
    {
        $receipt = $this->parse("Corner Cafe\nOfficial Receipt\nReceipt No: R-1008\nDate: 16/07/2026\nTotal RM 18.50\nCash\nPaid");
        self::assertSame(DocumentType::Receipt, $receipt->type);
        self::assertSame('18.50', $receipt->total?->value->amount);
        self::assertSame('cash', $receipt->payment?->method);

        $bill = $this->parse("Tenaga Utility Berhad\nElectricity Bill\nAccount: 778899\nBill Date: 01/07/2026\nDue Date: 31/07/2026\nAmount Due RM 125.40");
        self::assertSame(DocumentType::Bill, $bill->type);
        self::assertSame('2026-07-31', $bill->dueDate?->value);
        self::assertSame('125.40', $bill->balanceDue?->value->amount);
    }

    private function parse(string $text, string $locale = 'en_MY'): \Raintyyek\Ocr\Documents\ExtractedDocument
    {
        $manager = $this->createMock(OcrManager::class);
        $extractor = new HeuristicExtractor($manager, ['date_locale' => $locale]);

        return $extractor->parse(new OcrResult('test', $text));
    }

    private function value(\Raintyyek\Ocr\Documents\ExtractedDocument $doc, string $key): mixed
    {
        return match ($key) {
            'type' => $doc->type->value,
            'currency' => $doc->currency,
            'vendor' => $doc->vendor?->name,
            'language' => $doc->meta['language'] ?? null,
            'invoice_number' => $doc->invoiceNumber?->value,
            'account_number' => $doc->accountNumber?->value,
            'issue_date' => $doc->issueDate?->value,
            'due_date' => $doc->dueDate?->value,
            'payment_date' => $doc->paymentDate?->value,
            'subtotal' => $doc->subtotal?->value->amount,
            'tax_total' => $doc->taxTotal?->value->amount,
            'total' => $doc->total?->value->amount,
            'amount_paid' => $doc->amountPaid?->value->amount,
            'balance_due' => $doc->balanceDue?->value->amount,
            'payment_reference' => $doc->payment?->reference,
            'payment_method' => $doc->payment?->method,
            'paid' => $doc->payment?->paid,
            'line_items_min' => count($doc->lineItems),
            default => throw new \InvalidArgumentException("Unknown expected field: {$key}"),
        };
    }
}
