<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Console;

use Illuminate\Console\Command;
use Raintyyek\Ocr\Enums\OcrStatus;
use Raintyyek\Ocr\Models\OcrCall;
use Raintyyek\Ocr\OcrService;

/**
 * Processes pending OCR calls in a single batch. This is the "no queue worker"
 * path: schedule it in the app's cron (or Laravel scheduler) and it drains the
 * backlog created while `ocr.scheduling.driver` is set to "cron".
 *
 * Example (crontab, every minute):
 *   * * * * * cd /app && php artisan ocr:process-pending >> /dev/null 2>&1
 */
final class ProcessPendingOcrCalls extends Command
{
    /** @var string */
    protected $signature = 'ocr:process-pending
        {--limit= : Maximum number of calls to process (defaults to ocr.scheduling.cron.batch)}';

    /** @var string */
    protected $description = 'Process pending OCR calls recorded for scheduled execution.';

    public function handle(OcrService $service): int
    {
        $limit = (int) ($this->option('limit') ?: config('ocr.scheduling.cron.batch', 25));

        // Oldest first (FIFO) so nothing starves. lockForUpdate + a status guard
        // would be added here for multi-worker safety; a single cron invocation
        // is assumed by default.
        $calls = OcrCall::query()->pending()->orderBy('id')->limit($limit)->get();

        if ($calls->isEmpty()) {
            $this->info('No pending OCR calls.');

            return self::SUCCESS;
        }

        $this->info("Processing {$calls->count()} pending OCR call(s)...");

        $failed = 0;

        foreach ($calls as $call) {
            $result = $service->process($call);

            if ($result->status === OcrStatus::Failed) {
                $failed++;
            }

            $this->line("  [{$call->uuid}] {$result->status->value}");
        }

        if ($failed > 0) {
            $this->warn("{$failed} call(s) failed. Inspect the ocr_calls table for details.");
        }

        return self::SUCCESS;
    }
}
