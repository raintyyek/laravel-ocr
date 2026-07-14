# Roadmap to 1.0.0 — Structured Financial-Document Extraction

**Theme:** graduate the library from *raw OCR* (text + blocks) to *understanding*
— turning invoices, receipts, bills, expenses and payment slips into typed,
validated data your app can post straight into ledgers, AP workflows or
reconciliation.

> This builds **on top of** the existing engine layer. Raw OCR (`Ocr::recognize`
> / `Ocr::run`) stays exactly as-is; extraction is an additive capability. Per
> [VERSIONING.md](../VERSIONING.md), everything here is backwards-compatible and
> the public surface is frozen at the 1.0.0 tag.

---

## 1. What we extract

Supported document types (`DocumentType` enum): `invoice`, `receipt`, `bill`,
`expense`, `payment_slip`, `credit_note`, `unknown`.

### Field taxonomy

Every field is captured as a `Field` value object carrying the **value**, a
**confidence** (0.0–1.0) and the **bounding box / raw text** it came from, so
consumers can trust, threshold, and audit each datum.

| Group | Field | Type | Notes |
| ----- | ----- | ---- | ----- |
| **Parties** | `vendor` (name, address, tax_id, phone, email, website, bank) | `Party` | Supplier / merchant |
| | `customer` (name, address, tax_id) | `Party` | Bill-to / receiver |
| **Identifiers** | `invoiceNumber` | string | Invoice / receipt no. |
| | `poNumber` | string | Purchase order |
| | `accountNumber` | string | For bills/statements |
| **Dates** | `issueDate` | date `Y-m-d` | Invoice/receipt date |
| | `dueDate` | date | Payment due |
| | `paymentDate` | date | When paid (slips/receipts) |
| | `serviceDate` | date | Delivery/service period |
| **Amounts** | `subtotal`, `taxTotal`, `discountTotal`, `shipping`, `total`, `amountPaid`, `balanceDue` | `Money` | Decimal-string amount + currency |
| **Taxes** | `taxes[]` | `TaxLine` | name/type (VAT/GST/SST), rate %, amount |
| **Line items** | `lineItems[]` | `LineItem` | description, qty, unit, unitPrice, amount, tax, sku |
| **Payment** | `payment` (method, reference, transactionId, paidStatus, cardLast4, bankName, account) | `PaymentInfo` | Payment slips & paid receipts |
| **Doc** | `type`, `currency`, `language`, `meta` | scalars | Document-level |

**Payment slips are special.** Invoice/expense parsers from the cloud providers
do **not** reliably return `payment method`, `payment reference` or
`transaction id` — those live on bank transfer / gateway slips. Those fields are
sourced primarily from the **heuristic extractor** (keyword + spatial rules:
"Ref No", "Transaction ID", "Payment Method", "Paid via", "Approval Code") and
from AnalyzeExpense's `AMOUNT_PAID` / `PAYMENT_TERMS`. This is called out so the
accuracy expectations per field are honest.

---

## 2. Architecture

A new **extraction layer** sits above the engines, mirroring the existing
driver pattern so providers stay swappable by config.

```
 Ocr::extract($source, ['as' => 'invoice', 'extractor' => 'aws_expense'])
        │
        ▼
 ExtractorManager ── resolves ──▶ DocumentExtractor (driver)
                                   ├─ aws_expense    → Textract AnalyzeExpense
                                   ├─ google_docai   → Document AI Invoice/Expense parser
                                   └─ heuristic      → regex + spatial rules over an OcrResult
        │
        ▼
 ExtractedDocument  (typed fields, per-field confidence + provenance)
        │  (+ FieldNormalizer, DocumentValidator)
        ▼
 OcrCall (persisted: document_type + document JSON + cost)
```

### New contract

```php
interface DocumentExtractor
{
    // Extract structured data from an image (provider-native) or a prior OcrResult.
    public function extract(ImageSource $image, array $options = []): ExtractedDocument;
    public function name(): string;
}
```

### Drivers (extractors)

| Driver | Backed by | Strengths | Cost | Needs |
| ------ | --------- | --------- | ---- | ----- |
| `aws_expense` | **Textract `AnalyzeExpense`** | Best-in-class invoice/receipt fields + line items out of the box | ~USD 0.01 / page ($10 / 1,000; $8 / 1,000 above 1M) | `aws/aws-sdk-php` |
| `google_docai` | **Document AI Invoice / Expense parser** | Rich entities, strong multilingual | ~USD 0.01 / page **billed in 10-page blocks per document** (min $0.10/doc) | `google/cloud-document-ai`, a deployed processor id |
| `heuristic` | Our own parser over `OcrResult` | Free, offline, no per-doc fee; fills payment-slip gaps | reuses OCR cost only | existing OCR engine |

**Default strategy:** provider-native where configured (`aws_expense` /
`google_docai`) for accuracy, with `heuristic` as a zero-cost fallback and as the
enricher for payment-slip fields the cloud parsers miss. A `chain` mode runs a
primary extractor then backfills empty fields from the heuristic pass.

---

## 3. Provider field mapping

### AWS Textract `AnalyzeExpense` → `ExtractedDocument`

| Textract SummaryField | Our field |
| --------------------- | --------- |
| `VENDOR_NAME`, `VENDOR_ADDRESS`, `VENDOR_PHONE`, `VENDOR_URL`, `TAX_PAYER_ID`/`VENDOR_GST_NUMBER` | `vendor.*` |
| `RECEIVER_NAME`, `RECEIVER_ADDRESS` | `customer.*` |
| `INVOICE_RECEIPT_ID` | `invoiceNumber` |
| `PO_NUMBER` | `poNumber` |
| `INVOICE_RECEIPT_DATE` | `issueDate` |
| `DUE_DATE` | `dueDate` |
| `SUBTOTAL`, `TAX`, `DISCOUNT`, `SHIPPING_HANDLING_CHARGE`, `TOTAL`, `AMOUNT_PAID`, `AMOUNT_DUE` | `subtotal`, `taxTotal`, `discountTotal`, `shipping`, `total`, `amountPaid`, `balanceDue` |
| `PAYMENT_TERMS` | `payment.terms` |
| LineItemGroups → `ITEM`, `PRICE`, `QUANTITY`, `UNIT_PRICE`, `PRODUCT_CODE` | `lineItems[]` |

### Google Document AI → `ExtractedDocument`

| Document AI entity | Our field |
| ------------------ | --------- |
| `invoice_id` | `invoiceNumber` |
| `purchase_order` | `poNumber` |
| `invoice_date` / `receipt_date` | `issueDate` |
| `due_date` | `dueDate` |
| `currency` | `currency` |
| `net_amount`, `total_tax_amount`, `total_amount`, `freight_amount` | `subtotal`, `taxTotal`, `total`, `shipping` |
| `vat/*` (rate, amount, type) | `taxes[]` |
| `supplier_name/address/tax_id/email/phone/iban/website` | `vendor.*` |
| `receiver_name/address` | `customer.*` |
| `line_item/{description,quantity,unit_price,amount,product_code,unit}` | `lineItems[]` |

---

## 4. Normalization & validation

- **`FieldNormalizer`** — locale-aware parsing:
  - **Dates**: many formats (`15/07/2026`, `Jul 15, 2026`, `2026-07-15`) → `Y-m-d`, honouring a configured `date_locale` (day-first vs month-first).
  - **Money**: strip currency symbols/thousand separators, handle `1.234,56` vs `1,234.56`, negatives/parentheses; keep as **decimal string** (never float) + inferred currency.
  - **Tax**: infer rate from amount when only one is present; label by region (`SST`/`GST`/`VAT`).
- **`DocumentValidator`** — sanity/reconciliation:
  - `subtotal + taxTotal - discountTotal + shipping ≈ total`
  - `Σ lineItems.amount ≈ subtotal`
  - `amountPaid + balanceDue ≈ total`
  - Surfaces `->warnings()` and an `->isBalanced()` flag rather than throwing, so a slightly-off scan is still usable.

---

## 5. Persistence & cost

- **Persistence**: add nullable `document_type` (string) and `document` (json)
  columns to `ocr_calls` via a new migration, plus `OcrCall::toDocument():
  ?ExtractedDocument`. Extraction runs reuse the existing status/logs/scheduling
  machinery — `Ocr::extract()` can be inline or queued exactly like `run()`.
- **Cost**: extend the pricing table to price **per operation**, since
  `AnalyzeExpense` (~$0.01/page, i.e. $10/1,000) costs ~6.7× more than
  `DetectDocumentText` (~$0.0015/page, $1.50/1,000). New config:
  `pricing.operations.{text,expense,document_ai}`. `CostCalculator` gains
  `forOperation($engine, $operation, $units)`. (US West/Oregon list prices;
  confirm per region.)

---

## 6. Config additions (sketch)

```php
'extraction' => [
    'default'     => env('OCR_EXTRACTOR', 'aws_expense'), // aws_expense|google_docai|heuristic|chain
    'chain'       => ['aws_expense', 'heuristic'],        // primary then backfill
    'date_locale' => env('OCR_DATE_LOCALE', 'en_MY'),     // day-first parsing, etc.
    'currency'    => env('OCR_DEFAULT_CURRENCY', 'MYR'),
    'min_field_confidence' => 0.0,

    'google_docai' => [
        'project_id'   => env('GOOGLE_DOCAI_PROJECT'),
        'location'     => env('GOOGLE_DOCAI_LOCATION', 'us'),
        'processor_id' => env('GOOGLE_DOCAI_PROCESSOR'), // Invoice/Expense processor
    ],
],
'pricing' => [
    'operations' => [
        'text'        => ['unit_price' => 0.0015], // DetectDocumentText / Vision
        'expense'     => ['unit_price' => 0.01],   // Textract AnalyzeExpense ($10/1,000), per page
        'document_ai' => ['unit_price' => 0.01, 'block_pages' => 10], // Google: billed in 10-page blocks/doc
    ],
],
```

---

## 7. Public API (target)

```php
use Raintyyek\Ocr\Facades\Ocr;

// One-shot structured extraction:
$doc = Ocr::extract('s3://invoices/88.pdf', ['as' => 'invoice']);

$doc->invoiceNumber->value;          // "INV-2026-088"
$doc->dueDate->value;                // "2026-08-14"
$doc->total->value->amount;          // "1590.00"
$doc->total->value->currency;        // "MYR"
$doc->taxTotal->value->amount;       // "90.00"
$doc->payment->method;               // "bank_transfer"
$doc->payment->reference;            // "FT26071500123"

foreach ($doc->lineItems as $item) {
    $item->description; $item->quantity; $item->unitPrice; $item->amount;
}

$doc->isBalanced();                  // true — totals reconcile
$doc->confidenceBelow(0.6);          // list of low-confidence fields to review

// Persisted + costed + (optionally) queued, like run():
$call = Ocr::extractAndStore('s3://invoices/88.pdf', ['as' => 'invoice']);
$call->toDocument();                 // ExtractedDocument
```

---

## 8. Milestones

| # | Milestone | Deliverable |
| - | --------- | ----------- |
| **M1** | **Domain model + contract** ✅ *(scaffolded now)* | `DocumentType`, `Field`, `Money`, `Party`, `TaxLine`, `LineItem`, `PaymentInfo`, `ExtractedDocument`, `DocumentExtractor` |
| **M2** | Heuristic extractor | Regex/spatial parser over `OcrResult`; payment-slip fields; offline, free |
| **M3** | AWS `AnalyzeExpense` extractor | SummaryFields + LineItemGroups mapping |
| **M4** | Google Document AI extractor | Invoice/Expense processor mapping |
| **M5** | Normalization + validation | `FieldNormalizer`, `DocumentValidator`, locale config |
| **M6** | Persistence + cost | `document` columns, per-operation pricing, `Ocr::extract/extractAndStore` |
| **M7** | Multi-page / PDF (async) | Async Textract + Document AI batch; page-range control |
| **M8** | Tests, docs, polish | Testbench fixtures per provider; README "Extraction" guide → **tag `v1.0.0`** |

## 9. Exit criteria for 1.0.0

- [ ] At least one cloud extractor (`aws_expense` or `google_docai`) + the heuristic fallback shipping.
- [ ] Invoice, receipt and payment-slip covered end-to-end on real fixtures.
- [ ] Totals reconciliation + per-field confidence proven on a fixture corpus.
- [ ] Extraction persisted & costed through the existing `OcrCall` pipeline.
- [ ] Public API documented and frozen; PHPUnit suite green.
```
