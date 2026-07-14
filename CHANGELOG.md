# Changelog

All notable changes to `raintyyek/laravel-ocr` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
See [VERSIONING.md](VERSIONING.md) for the release policy and roadmap.

## [Unreleased]

### Added
- **Document extraction foundation (towards 1.0.0)** — the domain model and
  contract for turning invoices/receipts/bills/expenses/payment slips into typed
  data: `DocumentType`, `ExtractedDocument`, `Field` (value + confidence +
  provenance), `Money`, `Party`, `TaxLine`, `LineItem`, `PaymentInfo`, and the
  `DocumentExtractor` contract. See [docs/ROADMAP-1.0.md](docs/ROADMAP-1.0.md).
- **Heuristic extractor (M2)** — offline, rule-based `DocumentExtractor` that
  parses OCR text into structured fields: document type, vendor, invoice/PO no.,
  issue/due/payment dates (locale-aware, normalized to `Y-m-d`), subtotal/tax/
  discount/shipping/total/amount-paid/balance (currency-aware decimal amounts),
  line items, tax lines (type + rate), and payment method/reference/transaction
  id (the payment-slip fields cloud parsers miss). Bound as the default
  `DocumentExtractor`. Cloud extractors (AWS AnalyzeExpense, Google Document AI)
  land next.
- **Trilingual extraction (English / Malay / Chinese)** — the heuristic extractor
  recognises labels, month names (Malay + Chinese `年月日`), currencies
  (`RM`/`MYR`, `¥`/`元`/`CNY`, …), full-width punctuation, and both `1,234.56` and
  `1.234,56` grouping. Detected language is reported in `meta.language`. Smarter
  total selection (bottom-most/grand-total wins) and tax-rate parsing improve
  accuracy across invoices, receipts and payment slips.
- **Hardened line-item parsing** — column-aware row parsing (splits cells on
  column gaps) that recognises product code/SKU, quantity (leading, middle, `2 x`
  / `x2`, or with a unit like `pcs`/`件`), unit price and amount in any column
  order; reconciles `qty × unit ≈ amount` and infers a missing quantity or unit
  price; handles integer (no-decimal) prices and CJK descriptions; and no longer
  mistakes a "Total" column header for the end of the table.
- **Paid structured extractors (M3/M4) with per-provider toggles** — opt-in
  `AwsExpenseExtractor` (Textract AnalyzeExpense) and `GoogleDocumentAiExtractor`
  (Document AI invoice/expense), enabled via `ocr.extraction.aws.analyze_expense`
  and `ocr.extraction.google.document_ai`. An `ExtractorManager` routes to the
  paid API when enabled for the active engine, else the free heuristic — all
  returning the same `ExtractedDocument`. New `Ocr::extract()` facade method.
  These APIs analyse the image directly (their OCR is included; no separate text
  charge). Their response mappers are pure and unit-tested.
- **`FieldNormalizer`** — shared date/money/currency normalization used by every
  extractor, so there is one source of truth (heuristic refactored onto it).

### Changed
- Package renamed to `raintyyek/laravel-ocr` (namespace `Raintyyek\Ocr`).

## [0.1.0] - Unreleased (first tag)

Initial public release. **Pre-1.0: the API may change between minor versions.**

### Added
- Driver-based OCR abstraction with a single `OcrEngine` contract.
- **Google Cloud Vision** engine (`document` / `text` modes, language hints).
- **AWS Textract** engine (`DetectDocumentText`, in-place S3 reads).
- Normalized result DTOs: `OcrResult`, `TextBlock`, `BoundingBox`, `Point`,
  with 0.0–1.0 confidence and geometry.
- `ImageSource` inputs: path, bytes, base64, URL, S3, and Laravel Storage disks.
- Persistence: `OcrCall` Eloquent model + migration (status, logs, result, cost,
  timings), on the app's own DB connection.
- Cost calculation via a configurable pricing table (`CostCalculator`,
  `CostEstimate`).
- Scheduling: run inline, on a queue (`ProcessOcrCall` job), or via cron
  (`ocr:process-pending` command + optional scheduler auto-registration).
- `Ocr` facade, `OcrManager` (driver resolution), `OcrService` (orchestration),
  deferred-free service provider with config + migration publishing.
- S3 reads through Laravel's `config/filesystems.php` disks (no duplicated creds).

[Unreleased]: https://example.com/raintyyek/laravel-ocr/compare/v0.1.0...HEAD
[0.1.0]: https://example.com/raintyyek/laravel-ocr/releases/tag/v0.1.0
