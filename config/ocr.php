<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default OCR Engine
    |--------------------------------------------------------------------------
    |
    | The engine used when you resolve the OCR service without naming a driver
    | explicitly (e.g. `Ocr::recognize(...)`). Must match one of the keys in
    | the "engines" array below.
    |
    | Supported: "google", "aws"
    |
    */

    'default' => env('OCR_ENGINE', 'google'),

    /*
    |--------------------------------------------------------------------------
    | Engine Connections
    |--------------------------------------------------------------------------
    |
    | Per-engine configuration. Each engine is resolved lazily, so credentials
    | for an engine you do not use are never touched. Keep secrets in your
    | environment file — never commit them.
    |
    */

    'engines' => [

        'google' => [
            // Absolute path to a service-account JSON key file. Preferred for
            // servers where the file can live outside the web root.
            'credentials_path' => env('GOOGLE_VISION_CREDENTIALS'),

            // Alternatively, provide the service-account JSON inline (e.g. from
            // a secrets manager). Takes precedence over "credentials_path".
            'credentials_json' => env('GOOGLE_VISION_CREDENTIALS_JSON'),

            // Optional. Only needed when a request must be billed to a project
            // other than the one tied to the service account.
            'project_id' => env('GOOGLE_VISION_PROJECT_ID'),

            // "document" is tuned for dense documents (invoices, receipts);
            // "text" is tuned for sparse text in natural scenes.
            'mode' => env('GOOGLE_VISION_MODE', 'document'),

            // BCP-47 language hints (e.g. ["en", "ms"]). Empty = auto-detect.
            'language_hints' => [],
        ],

        'aws' => [
            'key'     => env('AWS_ACCESS_KEY_ID'),
            'secret'  => env('AWS_SECRET_ACCESS_KEY'),
            'token'   => env('AWS_SESSION_TOKEN'),
            'region'  => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'version' => env('AWS_TEXTRACT_VERSION', 'latest'),

            // When true, images are uploaded inline as bytes. When false, you
            // must pass an S3 object reference via the recognize() options.
            'inline_bytes' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults Applied To Every Request
    |--------------------------------------------------------------------------
    |
    | Engine-agnostic knobs that shape the normalized OcrResult. Individual
    | calls can override these through the recognize() $options argument.
    |
    */

    'defaults' => [
        // Drop text blocks whose confidence falls below this threshold (0.0–1.0).
        // Set to 0.0 to keep everything the provider returns.
        'min_confidence' => (float) env('OCR_MIN_CONFIDENCE', 0.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Call Logging (Persistence)
    |--------------------------------------------------------------------------
    |
    | Every call routed through Ocr::run() is recorded in the database with its
    | source, options, status, per-step logs, result and computed cost. This is
    | what powers cost reporting and auditing. Scheduling REQUIRES this to be on,
    | since queued jobs read the pending record.
    |
    */

    'database' => [
        'enabled'    => (bool) env('OCR_DB_ENABLED', true),

        // Connection to use; null = the app's default connection.
        'connection' => env('OCR_DB_CONNECTION'),

        // Table backing the OcrCall model.
        'table'      => env('OCR_DB_TABLE', 'ocr_calls'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost Calculation (Pricing Table)
    |--------------------------------------------------------------------------
    |
    | Cost per call is computed as: billable_units × unit_price, where units are
    | derived from the result (pages processed, minimum 1). Prices below are the
    | public list prices at time of writing — override them in your app config to
    | match your negotiated / regional rates. All amounts share "currency".
    |
    */

    'pricing' => [
        'currency' => env('OCR_CURRENCY', 'USD'),

        'engines' => [
            // Google Cloud Vision — DOCUMENT_TEXT_DETECTION: ~USD 1.50 / 1000 units.
            'google' => [
                'unit'          => 'page',
                'unit_price'    => (float) env('OCR_GOOGLE_UNIT_PRICE', 0.0015),
                'minimum_units' => 1,
            ],

            // AWS Textract — DetectDocumentText: ~USD 1.50 / 1000 pages.
            'aws' => [
                'unit'          => 'page',
                'unit_price'    => (float) env('OCR_AWS_UNIT_PRICE', 0.0015),
                'minimum_units' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | When enabled, Ocr::run() does NOT call the provider inline. Instead it
    | records a "pending" call and hands the work off to run in the background,
    | returning immediately. When disabled, Ocr::run() processes the call inline
    | and returns the completed record.
    |
    | Drivers:
    |   - "queue": dispatch a queued job (processed by `php artisan queue:work`,
    |              which you typically keep alive or trigger from cron).
    |   - "cron" : leave the call pending for `php artisan ocr:process-pending`
    |              to pick up on a schedule (no queue worker required).
    |
    */

    'scheduling' => [
        'enabled' => (bool) env('OCR_SCHEDULING', false),
        'driver'  => env('OCR_SCHEDULING_DRIVER', 'queue'), // "queue" | "cron"

        // Queue driver settings.
        'queue' => [
            'connection' => env('OCR_QUEUE_CONNECTION'), // null = default
            'queue'      => env('OCR_QUEUE', 'ocr'),
            'tries'      => (int) env('OCR_QUEUE_TRIES', 3),
            'backoff'    => (int) env('OCR_QUEUE_BACKOFF', 30), // seconds
        ],

        // Cron driver settings. When auto_schedule is true the package registers
        // the ocr:process-pending command with Laravel's scheduler for you.
        'cron' => [
            'auto_schedule' => (bool) env('OCR_CRON_AUTOSCHEDULE', false),
            'expression'    => env('OCR_CRON_EXPRESSION', '* * * * *'), // every minute
            'batch'         => (int) env('OCR_CRON_BATCH', 25), // max calls per run
        ],

        // Disk used to "spool" in-memory image bytes when a call is scheduled,
        // so the background worker can read them later. Path/URL/S3 sources are
        // stored by reference and need no spooling.
        'spool' => [
            'disk' => env('OCR_SPOOL_DISK', 'local'),
            'path' => env('OCR_SPOOL_PATH', 'ocr-spool'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | S3 Source Resolution
    |--------------------------------------------------------------------------
    |
    | Used when a call's source is an S3 object (e.g. Ocr::run('s3://key.jpg')).
    | This library does NOT hold its own S3 credentials — it reads the object
    | through one of your Laravel filesystem disks (config/filesystems.php), so
    | the bucket, region and credentials all come from there.
    |
    | Point "disk" at an S3-backed disk. The AWS Textract engine reads such
    | objects in place (using the disk's bucket); every other engine downloads
    | the bytes via that same disk.
    |
    */

    's3' => [
        'disk' => env('OCR_S3_DISK', 's3'),
    ],

];
