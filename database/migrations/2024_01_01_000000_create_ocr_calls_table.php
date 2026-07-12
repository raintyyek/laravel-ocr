<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backing table for {@see \Raintyyek\Ocr\Models\OcrCall}. Records every call
 * routed through Ocr::run() — its source, options, per-step logs, result and
 * computed cost — for auditing, retries and cost reporting.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection($this->connection())->create($this->table(), function (Blueprint $table) {
            $table->id();

            // Public, non-guessable identifier for logs / API responses.
            $table->uuid('uuid')->unique();

            $table->string('engine')->index();
            $table->string('status')->default('pending')->index();

            // Where the image came from and how to fetch it again if scheduled.
            $table->string('source_type')->nullable();
            $table->json('source')->nullable();

            // Per-call options passed to the engine (language hints, etc.).
            $table->json('options')->nullable();

            // --- Result -----------------------------------------------------
            $table->longText('text')->nullable();
            $table->json('blocks')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedInteger('pages')->nullable();
            $table->decimal('average_confidence', 5, 4)->nullable();

            // --- Cost -------------------------------------------------------
            $table->decimal('cost', 12, 6)->nullable();
            $table->string('cost_currency', 3)->nullable();
            $table->unsignedInteger('cost_units')->nullable();

            // --- Diagnostics ------------------------------------------------
            $table->text('error')->nullable();
            $table->json('logs')->nullable();

            // --- Scheduling / timing ---------------------------------------
            $table->boolean('scheduled')->default(false)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            // Speeds up cost reports grouped by engine over a period.
            $table->index(['engine', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
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
