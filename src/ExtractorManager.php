<?php

declare(strict_types=1);

namespace Raintyyek\Ocr;

use Illuminate\Support\Manager;
use Raintyyek\Ocr\Contracts\DocumentExtractor;
use Raintyyek\Ocr\Extractors\AwsExpenseExtractor;
use Raintyyek\Ocr\Extractors\GoogleDocumentAiExtractor;
use Raintyyek\Ocr\Extractors\HeuristicExtractor;

/**
 * Resolves and caches document extractors, and — crucially — decides *which*
 * extractor to use based on the per-provider "paid structured extraction"
 * toggles.
 *
 * Routing (see {@see resolveName()}):
 *   - An explicit `extractor` option, or a non-"auto" `ocr.extraction.default`,
 *     always wins.
 *   - Otherwise, for the active OCR engine:
 *       aws    + `ocr.extraction.aws.analyze_expense`  → "aws_expense"  (paid)
 *       google + `ocr.extraction.google.document_ai`   → "google_docai" (paid)
 *     and everything else falls back to "heuristic" (free — the current default).
 *
 * So disabling the toggles keeps extraction on the free offline path; enabling
 * one routes that provider's documents to its paid, higher-accuracy API.
 *
 * @mixin DocumentExtractor
 */
class ExtractorManager extends Manager
{
    /** The free, offline extractor is the default. */
    public function getDefaultDriver(): string
    {
        return 'heuristic';
    }

    /**
     * Resolve the extractor to use for the given OCR engine + per-call options.
     */
    public function for(?string $engine = null, array $options = []): DocumentExtractor
    {
        /** @var DocumentExtractor $extractor */
        $extractor = $this->driver($this->resolveName($engine, $options));

        return $extractor;
    }

    /**
     * Decide the extractor name from the toggles, engine and options.
     */
    public function resolveName(?string $engine = null, array $options = []): string
    {
        if (! empty($options['extractor'])) {
            return (string) $options['extractor'];
        }

        $default = (string) $this->config->get('ocr.extraction.default', 'auto');
        if ($default !== 'auto') {
            return $default;
        }

        $engine ??= (string) $this->config->get('ocr.default', 'google');

        if ($engine === 'aws' && $this->config->get('ocr.extraction.aws.analyze_expense')) {
            return 'aws_expense';
        }

        if ($engine === 'google' && $this->config->get('ocr.extraction.google.document_ai')) {
            return 'google_docai';
        }

        return 'heuristic';
    }

    /**
     * Whether the paid structured API is enabled for an engine (for reporting).
     */
    public function isPaidEnabled(string $engine): bool
    {
        return match ($engine) {
            'aws'    => (bool) $this->config->get('ocr.extraction.aws.analyze_expense'),
            'google' => (bool) $this->config->get('ocr.extraction.google.document_ai'),
            default  => false,
        };
    }

    // ---------------------------------------------------------------------
    // Driver factories
    // ---------------------------------------------------------------------

    protected function createHeuristicDriver(): DocumentExtractor
    {
        return new HeuristicExtractor(
            $this->container->make(OcrManager::class),
            (array) $this->config->get('ocr.extraction', []),
        );
    }

    protected function createAwsExpenseDriver(): DocumentExtractor
    {
        return new AwsExpenseExtractor(
            (array) $this->config->get('ocr.engines.aws', []),
            (array) $this->config->get('ocr.extraction', []),
        );
    }

    protected function createGoogleDocaiDriver(): DocumentExtractor
    {
        return new GoogleDocumentAiExtractor(
            (array) $this->config->get('ocr.extraction.google', []),
            (array) $this->config->get('ocr.extraction', []),
        );
    }
}
