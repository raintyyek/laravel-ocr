# Laravel OCR

A driver-based Optical Character Recognition (OCR) abstraction for Laravel.

Write your application against **one** interface and switch between
**Google Cloud Vision** and **AWS Textract** — or add your own engine — by
changing a single config value. Every call can be **persisted, cost-tracked, and
run in the background**, and images can come from a local file, raw bytes, a URL,
or straight from **Amazon S3**.

```php
use Raintyyek\Ocr\Facades\Ocr;

$call = Ocr::run('s3://receipts/2026/07/inv-88.jpg');

$call->text;    // "INVOICE  Acme Sdn Bhd ..."
$call->cost;    // 0.0015 (USD)
$call->status;  // OcrStatus::Completed
```

---

## Features

- **Provider-agnostic** — a single `OcrEngine` contract; Google & AWS included.
- **One normalized result** — the same `OcrResult` (text, blocks, bounding boxes,
  confidence) regardless of provider.
- **Flexible input** — local path, in-memory bytes, base64, remote URL, or an S3
  object read through your existing Laravel filesystem disk. AWS Textract reads
  S3 in place; other engines auto-download via the same disk.
- **Laravel-native infrastructure** — reuses your DB connections, queue/scheduler,
  and `config/filesystems.php` S3 disks. No duplicated credentials.
- **Auditing & billing** — every `Ocr::run()` is stored as an `OcrCall` with its
  source, options, per-step logs, status, timings, result and computed **cost**.
- **Cost calculation** — a configurable pricing table turns pages into money.
- **Scheduling** — run OCR inline, on a **queue**, or via a **cron** command,
  toggled by config with no code changes.
- **Laravel-native** — Facade, service provider, config publishing, migrations,
  Eloquent model, queued job, and an Artisan command.

## Requirements

| Requirement | Version |
| ----------- | ------- |
| PHP         | `^8.1`  |
| Laravel     | `10.x`, `11.x`, `12.x` |
| `google/cloud-vision` | `^1.7` — only if you use the Google engine |
| `aws/aws-sdk-php`     | `^3.280` — only if you use the AWS Textract engine |
| `league/flysystem-aws-s3-v3` | `^3.0` — only if you read S3 image sources |

The provider SDKs are **optional**: they are loaded lazily and only required for
the engine you actually call. Missing SDKs raise a clear, actionable error.

## Table of contents

- [Installation](#installation)
- [Quick start](#quick-start)
- [Core concepts](#core-concepts)
- [Configuration reference](#configuration-reference)
- [Usage](#usage)
  - [Choosing an engine](#choosing-an-engine)
  - [Providing an image](#providing-an-image)
  - [Per-call options](#per-call-options)
  - [Working with `OcrResult`](#working-with-ocrresult)
  - [Persisted calls, logs & cost](#persisted-calls-logs--cost)
  - [Scheduling & background execution](#scheduling--background-execution)
  - [Dependency injection](#dependency-injection)
- [API reference](#api-reference)
- [Database schema](#database-schema)
- [Error handling](#error-handling)
- [Extending with a new engine](#extending-with-a-new-engine)
- [Testing](#testing)
- [FAQ](#faq)

---

## Installation

Add the package (via Composer path/VCS repository or a private registry), then
install the SDK(s) for the engine(s) you intend to use:

```bash
composer require raintyyek/laravel-ocr

composer require google/cloud-vision   # for the Google Vision engine
composer require aws/aws-sdk-php        # for AWS Textract and/or S3 sources
```

The service provider and `Ocr` facade are registered automatically via package
discovery. Publish the config and create the `ocr_calls` table:

```bash
php artisan vendor:publish --tag=ocr-config
php artisan migrate
```

The migration auto-loads from the package. If you prefer to own it, publish it
first with `php artisan vendor:publish --tag=ocr-migrations`.

> **Note** — Persistence is on by default and is **required** for `Ocr::run()`
> and scheduling. If you only ever use the stateless `Ocr::recognize()`, you can
> set `OCR_DB_ENABLED=false` and skip the migration.

---

## Quick start

There are two entry points, for two different needs:

| Method             | Returns     | Persists? | Cost? | Schedulable? | Use when…                              |
| ------------------ | ----------- | :-------: | :---: | :----------: | -------------------------------------- |
| `Ocr::recognize()` | `OcrResult` |     —     |   —   |      —       | you just want the text, right now.     |
| `Ocr::run()`       | `OcrCall`   |     ✓     |   ✓   |      ✓       | you want an audit trail and billing.   |

**Stateless — get text immediately:**

```php
use Raintyyek\Ocr\Facades\Ocr;

$result = Ocr::recognize('/path/to/invoice.jpg');

echo $result->text;
echo $result->averageConfidence();   // 0.0–1.0 or null
```

**Full flow — recorded, costed, optionally backgrounded:**

```php
$call = Ocr::run('s3://receipts/2026/07/inv-88.jpg', ['engine' => 'aws']);

$call->status;         // OcrStatus::Completed | Pending | Processing | Failed
$call->text;           // recognized text
$call->cost;           // e.g. 0.0015   ($call->cost_currency === 'USD')
$call->pages;          // pages billed
$call->toOcrResult();  // full OcrResult (blocks, boxes, confidence)
$call->logs;           // per-step audit trail
```

---

## Core concepts

```
 ┌──────────┐     ┌─────────────┐     ┌────────────┐     ┌────────────┐
 │  Ocr     │────▶│ OcrService  │────▶│ OcrManager │────▶│ OcrEngine  │
 │ (facade) │     │ orchestrate │     │  resolve   │     │ google/aws │
 └──────────┘     └──────┬──────┘     └────────────┘     └─────┬──────┘
                         │  records                            │ returns
                         ▼                                     ▼
                    ┌──────────┐                         ┌───────────┐
                    │ OcrCall  │◀── cost, status, logs ──│ OcrResult │
                    │ (model)  │                         │  (DTO)    │
                    └──────────┘                         └───────────┘
```

- **`OcrEngine`** (`Contracts/OcrEngine.php`) — the one interface every provider
  implements: `recognize(ImageSource, array $options): OcrResult`. Your code
  should depend on this, never on a concrete SDK.
- **`OcrManager`** — a Laravel `Manager` that resolves and caches engines by name
  (`google`, `aws`) from configuration.
- **`OcrService`** — the orchestrator behind the facade. It normalizes input,
  records the `OcrCall`, decides run-now vs. schedule, invokes the engine, and
  computes cost.
- **`ImageSource`** — a value object that normalizes any input (path, bytes,
  base64, URL, S3, storage) into something every engine can consume.
- **`OcrResult`** — the provider-agnostic result: full text plus structured
  `TextBlock`s (each with a `BoundingBox` and confidence), the raw payload, and
  metadata.
- **`OcrCall`** — the Eloquent model that both **audits** and **carries** a call.
  A scheduled call is stored `Pending` and executed later from the same row.

### Directory layout

```
src/
├── Contracts/OcrEngine.php            The interface all engines implement.
├── OcrService.php                     Orchestrator: normalize → record → run/schedule → cost.
├── OcrManager.php                     Manager: resolves & caches engines.
├── OcrServiceProvider.php             Bindings, migrations, command, scheduler hook.
├── Facades/Ocr.php                    Ocr::run() / Ocr::recognize().
├── Models/OcrCall.php                 Eloquent record: status, logs, result, cost.
├── Jobs/ProcessOcrCall.php            Queued execution of a scheduled call.
├── Console/ProcessPendingOcrCalls.php Artisan `ocr:process-pending` (cron driver).
├── Cost/                              CostCalculator + CostEstimate.
├── Support/
│   └── ImageSource.php                Normalized input value object.
├── Engines/
│   ├── AbstractOcrEngine.php          Shared option-merging & confidence filtering.
│   ├── Google/GoogleVisionEngine.php
│   └── Aws/AwsTextractEngine.php
├── DTO/                               OcrResult, TextBlock, BoundingBox, Point.
├── Enums/                             BlockType | OcrStatus | SourceType.
└── Exceptions/                        OcrException + Configuration/Processing.
database/migrations/                   ocr_calls table.
config/ocr.php                         Published configuration.
```

---

## Configuration reference

All settings live in `config/ocr.php` and are environment-driven. The most
common `.env` keys:

```dotenv
# ── Engine ────────────────────────────────────────────────────────────
OCR_ENGINE=google                       # default engine: google | aws

# ── Google Cloud Vision ───────────────────────────────────────────────
GOOGLE_VISION_CREDENTIALS=/secure/service-account.json   # key file path
# GOOGLE_VISION_CREDENTIALS_JSON='{"type":"service_account",...}'  # or inline
GOOGLE_VISION_PROJECT_ID=                # optional billing project override
GOOGLE_VISION_MODE=document              # document (dense) | text (sparse)

# ── AWS Textract ──────────────────────────────────────────────────────
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_SESSION_TOKEN=                        # optional (temporary credentials)
AWS_DEFAULT_REGION=ap-southeast-1

# ── Result shaping ────────────────────────────────────────────────────
OCR_MIN_CONFIDENCE=0.0                    # drop blocks below this (0 = keep all)

# ── Persistence (required for run() & scheduling) ─────────────────────
OCR_DB_ENABLED=true
OCR_DB_CONNECTION=                        # null = default connection
OCR_DB_TABLE=ocr_calls

# ── Cost / pricing (amounts in OCR_CURRENCY) ──────────────────────────
OCR_CURRENCY=USD
OCR_GOOGLE_UNIT_PRICE=0.0015              # per page
OCR_AWS_UNIT_PRICE=0.0015                 # per page

# ── Scheduling ────────────────────────────────────────────────────────
OCR_SCHEDULING=false                      # true = background; false = inline
OCR_SCHEDULING_DRIVER=queue               # queue | cron
OCR_QUEUE_CONNECTION=                     # queue driver: null = default
OCR_QUEUE=ocr                             # queue name
OCR_QUEUE_TRIES=3                         # retries per job
OCR_QUEUE_BACKOFF=30                      # seconds between retries
OCR_CRON_AUTOSCHEDULE=false               # register ocr:process-pending w/ scheduler
OCR_CRON_EXPRESSION="* * * * *"           # cron cadence when auto-scheduled
OCR_CRON_BATCH=25                         # max calls per ocr:process-pending run
OCR_SPOOL_DISK=local                      # disk for spooling scheduled byte sources
OCR_SPOOL_PATH=ocr-spool

# ── S3 source resolution ──────────────────────────────────────────────
OCR_S3_DISK=s3                            # a disk from config/filesystems.php
```

> **S3 credentials live in Laravel, not here.** `OCR_S3_DISK` simply names one of
> your `config/filesystems.php` disks (typically the default `s3` disk). Its
> bucket, region, key and secret come from that disk — this package keeps no
> second copy of your S3 settings.

### Config keys at a glance

| Key                         | Purpose                                                        |
| --------------------------- | -------------------------------------------------------------- |
| `ocr.default`               | Engine used when none is specified.                            |
| `ocr.engines.google`        | Vision credentials, project, mode, language hints.             |
| `ocr.engines.aws`           | Textract credentials, region, API version.                     |
| `ocr.defaults.min_confidence` | Global confidence floor applied to every result.             |
| `ocr.database`              | Enable persistence; which Laravel connection & table name.     |
| `ocr.pricing`               | Currency + per-engine unit price / minimum units.              |
| `ocr.scheduling`            | Enable/driver, queue settings, cron settings, spool disk.      |
| `ocr.s3`                    | Name of the Laravel filesystem disk used for S3 sources.       |

### Reused Laravel infrastructure

This is a Laravel-first library — it does not reimplement anything the framework
already provides:

| Concern       | Uses your…                          | Configured by |
| ------------- | ----------------------------------- | ------------- |
| Database      | DB connection (`config/database.php`) | `OCR_DB_CONNECTION` (null = default) |
| Queues        | queue connection (`config/queue.php`) | `OCR_QUEUE_CONNECTION` / `OCR_QUEUE` |
| Cron          | Laravel scheduler (`schedule:run`)  | `OCR_CRON_AUTOSCHEDULE` |
| S3 storage    | filesystem disk (`config/filesystems.php`) | `OCR_S3_DISK` |
| Byte spooling | filesystem disk                     | `OCR_SPOOL_DISK` |

---

## Usage

### Choosing an engine

The default engine comes from `ocr.default`. Override it per call with the
`engine` option, or resolve a specific engine directly:

```php
Ocr::run($image, ['engine' => 'aws']);          // per-call override
Ocr::recognize($image, ['engine' => 'google']);

// Resolve a raw engine (no persistence, no cost):
$engine = Ocr::engine('aws');                    // implements OcrEngine
$result = $engine->recognize(ImageSource::fromPath($path));
```

### Providing an image

Any of these can be passed to `run()` / `recognize()`, either as an
`ImageSource` or as a bare string that is auto-detected:

```php
use Raintyyek\Ocr\Support\ImageSource;

ImageSource::fromPath('/tmp/receipt.png');
ImageSource::fromBytes($request->file('doc')->get());
ImageSource::fromBase64($dataUri);                 // "data:image/png;base64," tolerated
ImageSource::fromUrl('https://example.com/a.jpg');
ImageSource::fromS3('invoices/88.jpg');            // key on the configured S3 disk
ImageSource::fromS3('invoices/88.jpg', 'reports'); // key on a specific disk
ImageSource::make($anything);                      // auto-detects, incl. "s3://…"
```

```php
// Strings are auto-typed by run()/recognize():
Ocr::run('s3://invoices/88.jpg');                  // S3 object (key on OCR_S3_DISK)
Ocr::run('/var/www/storage/app/scan.png');         // existing path
Ocr::run('https://example.com/photo.jpg');         // URL
Ocr::run($request->file('doc')->get());            // raw bytes
```

**S3 is resolved through your Laravel filesystem disk** (`OCR_S3_DISK`, default
`s3`). An `s3://…` string is the object **key** on that disk — the bucket and
credentials come from `config/filesystems.php`, never from this package.

Behaviour is engine-aware:

- **AWS Textract** reads the object *in place* using the disk's bucket — no
  download, no bandwidth.
- **Every other engine** downloads the bytes through the same disk
  (`Storage::disk(...)->get($key)`).

### Per-call options

Options are merged over engine config, so per-call values win. Unknown keys are
ignored by engines that don't use them.

```php
Ocr::recognize($image, [
    'engine'         => 'google',
    'language_hints' => ['en', 'ms'],    // Google: BCP-47 hints
    'mode'           => 'document',      // Google: document | text
    'min_confidence' => 0.80,            // both: drop low-confidence blocks
    's3'             => [                 // AWS: read directly from S3
        'bucket'  => 'docs',
        'name'    => 'invoice.pdf',
        'version' => null,
    ],
]);
```

### Working with `OcrResult`

```php
$result->engine;                            // "google" | "aws"
$result->text;                              // full text, reading order
$result->isEmpty();                         // bool
$result->averageConfidence();               // float|null (mean over blocks)
$result->blocksOfType(BlockType::Line);     // list<TextBlock>
$result->toArray();                         // JSON-safe (raw omitted)

foreach ($result->blocks as $block) {
    $block->text;                           // string
    $block->type;                           // BlockType::Word | Line | …
    $block->confidence;                     // 0.0–1.0 or null
    $block->boundingBox?->left();           // normalized 0.0–1.0
    $block->boundingBox?->width();
}

$result->raw;                               // untouched provider response
```

Bounding boxes are normalized to fractions (0.0–1.0) of the image dimensions, so
geometry is meaningful even without knowing the source resolution.

### Persisted calls, logs & cost

Every `Ocr::run()` writes a row to `ocr_calls`, giving you a durable, queryable
history for auditing, retries and cost reporting.

```php
$call = Ocr::run('s3://docs/invoice.pdf', ['engine' => 'aws']);

$call->uuid;              // public, non-guessable id for logs / API responses
$call->engine;           // "aws"
$call->status;           // OcrStatus enum
$call->text;             // recognized text
$call->pages;            // pages processed / billed
$call->average_confidence;
$call->cost;             // amount; see cost_currency & cost_units
$call->duration_ms;      // engine wall-clock time
$call->logs;             // [{at, level, message}, …]
$call->error;            // failure message, if any

$call->toOcrResult();    // rebuild the full OcrResult (null unless Completed)
$call->throwIfFailed();  // turn a Failed record into an OcrProcessingException
```

Report on usage with plain Eloquent (scopes included):

```php
use Raintyyek\Ocr\Models\OcrCall;

// This month's spend:
OcrCall::completed()->whereMonth('created_at', now()->month)->sum('cost');

// Spend by engine:
OcrCall::completed()
    ->selectRaw('engine, count(*) calls, sum(cost) total, sum(pages) pages')
    ->groupBy('engine')
    ->get();

// Triage failures:
OcrCall::failed()->latest()->take(20)->get(['uuid', 'engine', 'error']);
```

#### How cost is calculated

```
cost = max(pages, minimum_units) × unit_price     (in ocr.pricing.currency)
```

Units are the pages the provider reported (a single image counts as one page).
The shipped defaults are public list prices — **override them with your
negotiated / regional rates** via `OCR_GOOGLE_UNIT_PRICE`, `OCR_AWS_UNIT_PRICE`,
or the full `ocr.pricing` array. Engines with no configured price yield a zero
estimate rather than failing.

Pre-flight estimate without calling a provider:

```php
use Raintyyek\Ocr\Cost\CostCalculator;

$estimate = app(CostCalculator::class)->forUnits('aws', pages: 3);
$estimate->amount;      // 0.0045
$estimate->currency;    // "USD"
$estimate->units;       // 3
$estimate->unitPrice;   // 0.0015
```

### Scheduling & background execution

By default `Ocr::run()` executes **inline** and returns a completed call. Set
`OCR_SCHEDULING=true` and it instead records a **Pending** call, hands the work
off, and returns immediately — ideal for spiky traffic, large batches, or slow
multi-page documents.

**Queue driver** (`OCR_SCHEDULING_DRIVER=queue`, default) dispatches a
`ProcessOcrCall` job onto the `ocr` queue. Run a worker (kept alive by Supervisor,
or triggered from cron):

```bash
php artisan queue:work --queue=ocr
```

Retries and backoff are config-driven (`OCR_QUEUE_TRIES`, `OCR_QUEUE_BACKOFF`).

**Cron driver** (`OCR_SCHEDULING_DRIVER=cron`) needs no queue worker. Pending
calls wait for an Artisan command you run on a schedule:

```bash
php artisan ocr:process-pending --limit=25
```

Wire it into your crontab directly, or let the package register it with Laravel's
scheduler by setting `OCR_CRON_AUTOSCHEDULE=true` (then ensure
`php artisan schedule:run` fires every minute):

```cron
* * * * * cd /app && php artisan schedule:run >> /dev/null 2>&1
```

A typical flow when scheduling is enabled:

```php
$call = Ocr::run($request->file('doc')->get());   // returns immediately
$call->status;   // OcrStatus::Pending

// …later, after the worker/cron runs…
$fresh = $call->fresh();
$fresh->status;  // OcrStatus::Completed
$fresh->toOcrResult();
```

> In-memory byte sources are **spooled** to the `ocr.scheduling.spool` disk so
> the background worker can read them later. Path, URL and S3 sources are stored
> by reference and need no spooling.

### Dependency injection

Type-hint the contract to receive the default engine without touching the facade:

```php
use Raintyyek\Ocr\Contracts\OcrEngine;
use Raintyyek\Ocr\OcrService;

class InvoiceScanner
{
    public function __construct(
        private OcrService $ocr,      // full orchestrator (run/recognize)
        private OcrEngine $engine,    // default engine (stateless recognize)
    ) {}
}
```

---

## API reference

### `Ocr` facade → `OcrService`

| Method | Signature | Description |
| ------ | --------- | ----------- |
| `run` | `run(ImageSource\|string $source, array $options = []): OcrCall` | Record + run/schedule; returns the call. |
| `recognize` | `recognize(ImageSource\|string $source, array $options = []): OcrResult` | Stateless one-shot; no persistence/cost. |
| `process` | `process(OcrCall $call, ?ImageSource $image = null): OcrCall` | Execute a recorded call (used by job/command). |
| `engine` | `engine(?string $name = null): OcrEngine` | Resolve a raw engine. |

### `ImageSource`

| Factory | Description |
| ------- | ----------- |
| `fromPath(string)` | Local filesystem path. |
| `fromBytes(string)` | Raw binary already in memory. |
| `fromBase64(string)` | Base64 (data-URI prefix tolerated). |
| `fromUrl(string)` | Remote HTTP(S) URL (fetched lazily). |
| `fromS3(string $key, ?string $disk = null)` | S3 object key on a filesystem disk. |
| `fromS3Path(string $path, ?string $disk = null)` | `s3://key` on a filesystem disk. |
| `fromStorage(string $disk, string $path)` | Path on any Laravel Storage disk. |
| `make(string $input)` | Auto-detect from a string. |

### `OcrResult` (DTO)

| Member | Type | Description |
| ------ | ---- | ----------- |
| `engine` | `string` | Engine that produced the result. |
| `text` | `string` | Full recognized text. |
| `blocks` | `list<TextBlock>` | Structured units (words/lines/…). |
| `raw` | `mixed` | Untouched provider response. |
| `meta` | `array` | Provider metadata (e.g. `pages`). |
| `isEmpty()` | `bool` | Whether any text was found. |
| `blocksOfType(BlockType)` | `list<TextBlock>` | Filter by granularity. |
| `averageConfidence()` | `float\|null` | Mean confidence over blocks. |
| `toArray()` | `array` | JSON-safe representation. |

### `OcrStatus` (enum)

`Pending` · `Processing` · `Completed` · `Failed` — with `isFinished(): bool`.

---

## Database schema

`ocr_calls` (table name configurable via `OCR_DB_TABLE`):

| Column | Type | Notes |
| ------ | ---- | ----- |
| `id` | bigint PK | |
| `uuid` | uuid, unique | Public identifier. |
| `engine` | string, indexed | `google` / `aws`. |
| `status` | string, indexed | `OcrStatus` value. |
| `source_type` | string | `path`/`bytes`/`url`/`s3`/`storage`. |
| `source` | json | Locator to rebuild the source (never raw bytes). |
| `options` | json | Per-call options. |
| `text` | longtext | Recognized text. |
| `blocks` | json | Serialized `TextBlock`s. |
| `meta` | json | Provider metadata. |
| `pages` | int | Pages processed. |
| `average_confidence` | decimal(5,4) | |
| `cost` | decimal(12,6) | Computed amount. |
| `cost_currency` | char(3) | |
| `cost_units` | int | Billable units. |
| `error` | text | Failure message. |
| `logs` | json | `[{at, level, message}]`. |
| `scheduled` | bool, indexed | Whether it was queued/deferred. |
| `started_at` / `completed_at` | timestamp | |
| `duration_ms` | int | Engine wall-clock. |
| `created_at` / `updated_at` | timestamp | |

Composite indexes on `(engine, created_at)` and `(status, created_at)` keep cost
reports and status queries fast.

---

## Error handling

All library failures extend `Raintyyek\Ocr\Exceptions\OcrException`:

| Exception | Meaning | Typical fix |
| --------- | ------- | ----------- |
| `OcrConfigurationException` | Missing SDK package, bad/absent credentials, unknown engine. | Operator action — install package, set credentials. |
| `OcrProcessingException` | A failed API call or unreadable image at runtime. Wraps the provider's original exception. | Retry, fall back to another engine, or inspect the cause. |

```php
use Raintyyek\Ocr\Exceptions\OcrConfigurationException;
use Raintyyek\Ocr\Exceptions\OcrProcessingException;

try {
    $result = Ocr::recognize($image);
} catch (OcrConfigurationException $e) {
    // credentials / composer package problem
} catch (OcrProcessingException $e) {
    report($e);                 // $e->getPrevious() holds the provider error
}
```

For persisted calls, failures are recorded on the row (status → `Failed`, with
`error` and a log entry) instead of being thrown, so nothing is lost. Convert one
back into an exception with `$call->throwIfFailed()`.

---

## Extending with a new engine

Adding a provider (e.g. Azure, Tesseract) requires **no application changes**:

1. **Implement the contract.** Extend `AbstractOcrEngine` to reuse option merging
   and confidence filtering:

   ```php
   use Raintyyek\Ocr\Engines\AbstractOcrEngine;
   use Raintyyek\Ocr\DTO\OcrResult;
   use Raintyyek\Ocr\Support\ImageSource;

   final class AzureVisionEngine extends AbstractOcrEngine
   {
       public function name(): string { return 'azure'; }

       public function recognize(ImageSource $image, array $options = []): OcrResult
       {
           $options = $this->resolveOptions($options);
           // …call the provider, map into TextBlock[]…
           return new OcrResult('azure', $text, $blocks, $raw, $meta);
       }
   }
   ```

2. **Register a factory** on `OcrManager`:

   ```php
   protected function createAzureDriver(): OcrEngine
   {
       return new AzureVisionEngine($this->engineConfig('azure'), $this->requestDefaults());
   }
   ```

3. **Add config** under `ocr.engines.azure` (and optionally `ocr.pricing.engines.azure`).

Select it with `OCR_ENGINE=azure` or `['engine' => 'azure']`.

---

## Testing

`orchestra/testbench` and PHPUnit are included as dev dependencies. Because the
engine layer is a single interface, you can test application code without hitting
a provider by binding a fake engine:

```php
use Raintyyek\Ocr\Contracts\OcrEngine;
use Raintyyek\Ocr\DTO\OcrResult;

$this->app->bind(OcrEngine::class, fn () => new class implements OcrEngine {
    public function name(): string { return 'fake'; }
    public function recognize($image, array $options = []): OcrResult
    {
        return new OcrResult('fake', 'hello world');
    }
});
```

For scheduled flows, assert dispatching with Laravel's `Queue::fake()` and the
`ProcessOcrCall` job.

---

## FAQ

**Do I have to use the database?**
Only for `Ocr::run()` and scheduling. `Ocr::recognize()` is fully stateless; set
`OCR_DB_ENABLED=false` to disable persistence entirely.

**Which is cheaper/better, Google or AWS?**
They price similarly (~USD 1.50 / 1000 pages at list). Google Vision's `document`
mode and AWS Textract both excel at dense documents; benchmark on your own images
and switch with one config value.

**Can I process multi-page PDFs?**
Both providers count pages, which flows into `pages` and cost. The synchronous
APIs used here suit single images and short documents; for large async Textract
jobs, add an engine method following the [extension guide](#extending-with-a-new-engine).

**How do I stop low-quality reads polluting results?**
Set `OCR_MIN_CONFIDENCE` (e.g. `0.8`) globally, or pass `min_confidence` per call.

**Is the raw provider response available?**
Yes, on live results via `$result->raw`. It is intentionally **not** persisted
(engine-specific and often large); persisted calls keep the normalized data.

---

## License

MIT.
