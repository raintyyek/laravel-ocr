<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Contracts;

use Raintyyek\Ocr\Documents\ExtractedDocument;
use Raintyyek\Ocr\Support\ImageSource;

/**
 * Turns a document image into structured, typed data.
 *
 * The parallel to {@see OcrEngine}: where an engine returns raw text/blocks, an
 * extractor returns an {@see ExtractedDocument} (invoice/receipt/… fields). The
 * two are layered — a provider-native extractor (AWS AnalyzeExpense, Google
 * Document AI) analyses the image directly, while the heuristic extractor first
 * runs OCR then parses the resulting text. Application code depends on this
 * contract so extractors stay swappable by config.
 *
 * @see docs/ROADMAP-1.0.md
 */
interface DocumentExtractor
{
    /**
     * Extract structured data from a document image.
     *
     * @param  ImageSource          $image   The document to analyse.
     * @param  array<string, mixed> $options Per-call hints, e.g. `as` (expected
     *                                        DocumentType), `date_locale`,
     *                                        `currency`, `min_field_confidence`.
     *
     * @throws \Raintyyek\Ocr\Exceptions\OcrException On any extraction failure.
     */
    public function extract(ImageSource $image, array $options = []): ExtractedDocument;

    /**
     * The extractor's short identifier (e.g. "aws_expense", "google_docai",
     * "heuristic"). Used for logging and to stamp cost/provenance.
     */
    public function name(): string;
}
