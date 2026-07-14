<?php

declare(strict_types=1);

namespace Raintyyek\Ocr;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ServiceProvider;
use Raintyyek\Ocr\Console\ProcessPendingOcrCalls;
use Raintyyek\Ocr\Contracts\DocumentExtractor;
use Raintyyek\Ocr\Contracts\OcrEngine;
use Raintyyek\Ocr\Cost\CostCalculator;

/**
 * Registers the OCR library with the Laravel container and wires up its
 * persistence, cost and scheduling support.
 *
 * S3 image sources are read through Laravel's own filesystem disks, so there is
 * nothing S3-specific to bind here — see config `ocr.s3.disk`.
 */
final class OcrServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH     = __DIR__ . '/../config/ocr.php';
    private const MIGRATIONS_PATH = __DIR__ . '/../database/migrations';

    /**
     * Register container bindings.
     */
    public function register(): void
    {
        // Ship sane defaults; the app's own config/ocr.php overrides these.
        $this->mergeConfigFrom(self::CONFIG_PATH, 'ocr');

        // Engine registry (driver resolution + caching).
        $this->app->singleton(OcrManager::class, static fn ($app) => new OcrManager($app));

        // Pricing table → cost calculator.
        $this->app->singleton(CostCalculator::class, static fn ($app) => new CostCalculator(
            (array) $app->make(Config::class)->get('ocr.pricing', []),
        ));

        // Document extraction registry (routes free heuristic vs paid AWS/Google
        // per the ocr.extraction toggles).
        $this->app->singleton(ExtractorManager::class, static fn ($app) => new ExtractorManager($app));

        // The orchestrator the facade resolves to.
        $this->app->singleton(OcrService::class, static fn ($app) => new OcrService(
            $app->make(OcrManager::class),
            $app->make(CostCalculator::class),
            $app->make(Config::class),
            $app->make(ExtractorManager::class),
        ));

        // Let callers type-hint the OcrEngine contract and receive the default
        // engine, keeping application code decoupled from the manager itself.
        $this->app->bind(OcrEngine::class, static fn ($app) => $app->make(OcrManager::class)->engine());

        // Type-hinting the DocumentExtractor contract yields the extractor the
        // toggles select (free heuristic unless a paid provider is enabled).
        $this->app->bind(DocumentExtractor::class, static fn ($app) => $app->make(ExtractorManager::class)->for());

        $this->app->alias(ExtractorManager::class, 'ocr.extractors');

        // Friendly string aliases: app('ocr') and app('ocr.manager').
        $this->app->alias(OcrService::class, 'ocr');
        $this->app->alias(OcrManager::class, 'ocr.manager');
    }

    /**
     * Bootstrap migrations, publishing, console commands and scheduling.
     */
    public function boot(): void
    {
        // Zero-config migrations; also publishable for teams that vendor them.
        $this->loadMigrationsFrom(self::MIGRATIONS_PATH);

        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG_PATH => $this->app->configPath('ocr.php')], 'ocr-config');
            $this->publishes([self::MIGRATIONS_PATH => $this->app->databasePath('migrations')], 'ocr-migrations');

            $this->commands([ProcessPendingOcrCalls::class]);
        }

        $this->registerScheduledCommand();
    }

    /**
     * Optionally register the pending-calls command with Laravel's scheduler,
     * so pure-cron setups need only the single `schedule:run` cron entry.
     */
    private function registerScheduledCommand(): void
    {
        $config = $this->app->make(Config::class);

        if (! $config->get('ocr.scheduling.cron.auto_schedule', false)) {
            return;
        }

        $expression = (string) $config->get('ocr.scheduling.cron.expression', '* * * * *');

        // Runs whenever the scheduler is resolved — reliable in both the
        // `schedule:run` and `schedule:list` code paths.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) use ($expression): void {
            $schedule->command(ProcessPendingOcrCalls::class)
                ->cron($expression)
                ->withoutOverlapping();
        });
    }
}
