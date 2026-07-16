<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records structured-extraction calls ({@see \Raintyyek\Ocr\OcrService::extract()})
 * in the same `ocr_calls` table, alongside plain OCR runs.
 *
 * - `operation`     — "recognize" (OCR) or "extract" (structured extraction).
 * - `extractor`     — which extractor ran (heuristic / aws_expense / google_docai).
 * - `document_type` — the detected document type (invoice / receipt / payment_slip …).
 * - `document`      — the full extracted document (ExtractedDocument::toArray()).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection($this->connection())->table($this->table(), function (Blueprint $table) {
            $table->string('operation')->default('recognize')->after('engine')->index();
            $table->string('extractor')->nullable()->after('operation');
            $table->string('document_type')->nullable()->after('extractor');
            $table->json('document')->nullable()->after('blocks');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->table($this->table(), function (Blueprint $table) {
            $table->dropColumn(['operation', 'extractor', 'document_type', 'document']);
        });
    }

    private function table(): string
    {
        return (string) config('ocr.database.table', 'ocr_calls');
    }

    private function connection(): ?string
    {
        return config('ocr.database.connection');
    }
};
