<?php

declare(strict_types=1);

/*
 * Dependency-free parser smoke test. This deliberately supplies the manager
 * type because parse() is pure and does not use Laravel or an OCR engine.
 * Run with: php tests/smoke.php
 */

namespace Raintyyek\Ocr {
    class OcrManager
    {
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Raintyyek\\Ocr\\';
        if (! str_starts_with($class, $prefix) || $class === 'Raintyyek\\Ocr\\OcrManager') {
            return;
        }

        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });

    $value = static function (\Raintyyek\Ocr\Documents\ExtractedDocument $doc, string $key): mixed {
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
    };

    /** @var list<array{name: string, locale: string, text: string, expected: array<string, scalar>}> $corpus */
    $corpus = require __DIR__ . '/Fixtures/FinancialDocumentCorpus.php';
    $passed = $total = 0;
    $failures = [];

    foreach ($corpus as $case) {
        $extractor = new \Raintyyek\Ocr\Extractors\HeuristicExtractor(
            new \Raintyyek\Ocr\OcrManager(),
            ['date_locale' => $case['locale']],
        );
        $document = $extractor->parse(new \Raintyyek\Ocr\DTO\OcrResult('smoke', $case['text']));

        foreach ($case['expected'] as $field => $expected) {
            $actual = $value($document, $field);
            $ok = $field === 'line_items_min' ? $actual >= $expected : $actual === $expected;
            $total++;

            if ($ok) {
                $passed++;
            } else {
                $failures[] = sprintf('%s.%s expected %s, got %s', $case['name'], $field, var_export($expected, true), var_export($actual, true));
            }
        }
    }

    $accuracy = $total > 0 ? $passed / $total : 0.0;
    if ($accuracy < 1.0) {
        fwrite(STDERR, "FAILED\n- " . implode("\n- ", $failures) . PHP_EOL);
        fwrite(STDERR, sprintf('Accuracy: %d/%d (%.2f%%), required: 100.00%%', $passed, $total, $accuracy * 100) . PHP_EOL);
        exit(1);
    }

    echo sprintf('OK: %d documents, %d/%d target fields (%.2f%%)', count($corpus), $passed, $total, $accuracy * 100) . PHP_EOL;
    if ($failures !== []) {
        echo "Remaining mismatches:\n- " . implode("\n- ", $failures) . PHP_EOL;
    }
}
