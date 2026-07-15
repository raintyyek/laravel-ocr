# Changelog

All notable changes to `raintyyek/laravel-ocr` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
See [VERSIONING.md](VERSIONING.md) for the release policy and roadmap.

## [Unreleased]

_Nothing yet._

## [1.0.2] - 2026-07-16

### Improved

- Heuristic extraction now understands mobile/two-column OCR reading order,
  where consecutive labels are followed by consecutive values.
- Payment-result screens now expose their prominent transaction amount as both
  `amountPaid` and `total`, even when the screen has no explicit amount label.
- Merchant/vendor labels, `Date & Time`, split `Reference` / `No.` labels, and
  more payment-result and payment-method variants are recognized.
- Added localized account-number extraction and expanded payment-success,
  timestamp, transfer, card, wallet, and paid-status terms across English,
  Malay, Simplified Chinese, and Traditional Chinese documents.
- Multi-word labeled values such as merchant names are preserved instead of
  being truncated at capitalized words.
- Payment dates retain an available time as `Y-m-d H:i:s`; date-only documents
  continue to return `Y-m-d`.
- Added a 14-document multilingual regression corpus covering payment slips,
  invoices, receipts, bills, totals, dates, accounts, line items, payment
  methods, merchants, and references. The suite enforces at least 95% target-
  field accuracy and currently passes all 123 expected fields.

## [1.0.0] - 2026-07-15

First stable release.

### Added — Core OCR
- Driver-based OCR abstraction with a single `OcrEngine` contract.
- **Google Cloud Vision** engine (`document` / `text` modes, language hints).
- **AWS Textract** engine (`DetectDocumentText`, in-place S3 reads).
- Normalized result DTOs: `OcrResult`, `TextBlock`, `BoundingBox`, `Point`,
  with 0.0–1.0 confidence and geometry.
- `ImageSource` inputs: path, bytes, base64, URL, S3, and Laravel Storage disks.
- Persistence: `OcrCall` Eloquent model + migration (status, logs, result, cost,
  timings), on the app's own DB connection.
- Cost calculation via a configurable pricing table (`CostCalculator`, `CostEstimate`).
- Scheduling: run inline, on a queue (`ProcessOcrCall` job), or via cron
  (`ocr:process-pending` command + optional scheduler auto-registration).
- `Ocr` facade, `OcrManager` (driver resolution), `OcrService` (orchestration),
  deferred-free service provider with config + migration publishing.
- S3 reads through Laravel's `config/filesystems.php` disks (no duplicated creds).

### Added — Structured document extraction
- Domain model for invoices/receipts/bills/expenses/payment slips:
  `ExtractedDocument`, `Field` (value + confidence + provenance), `Money`,
  `Party`, `TaxLine`, `LineItem`, `PaymentInfo`, `DocumentType`, and the
  `DocumentExtractor` contract.
- **Heuristic extractor** (offline, free) — document type, vendor, invoice/PO no.,
  issue/due/payment dates (locale-aware → `Y-m-d`), subtotal/tax/discount/
  shipping/total/amount-paid/balance, tax lines (type + rate), line items, and
  payment method/reference/transaction id.
- **Trilingual extraction (English / Malay / Chinese)** — localized labels, Malay
  and Chinese (`年月日`) dates, currencies (`RM`/`MYR`, `¥`/`元`/`CNY`), full-width
  punctuation, and both `1,234.56` and `1.234,56` grouping; detected language in
  `meta.language`.
- **Column-aware line items** — product code/SKU, quantity (leading/middle/`2 x`/
  with a unit), unit price and amount in any column order; reconciles
  `qty × unit ≈ amount` and infers a missing value; integer prices and CJK.
- **Paid structured extractors with per-provider toggles** — opt-in
  `AwsExpenseExtractor` (Textract AnalyzeExpense) and `GoogleDocumentAiExtractor`
  (Document AI), enabled via `ocr.extraction.aws.analyze_expense` /
  `ocr.extraction.google.document_ai`. `ExtractorManager` routes to the paid API
  when enabled for the active engine, else the free heuristic — same
  `ExtractedDocument` either way. New `Ocr::extract()` facade method.
- **`FieldNormalizer`** — shared date/money/currency normalization used by every
  extractor.

[Unreleased]: https://github.com/raintyyek/laravel-ocr/compare/v1.0.2...HEAD
[1.0.2]: https://github.com/raintyyek/laravel-ocr/compare/v1.0.0...v1.0.2
[1.0.0]: https://github.com/raintyyek/laravel-ocr/releases/tag/v1.0.0
