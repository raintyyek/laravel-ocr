<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Engines\Google;

use Google\Cloud\Vision\V1\Block;
use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Page;
use Google\Cloud\Vision\V1\Paragraph;
use Google\Cloud\Vision\V1\Symbol;
use Google\Cloud\Vision\V1\Word;
use Google\Protobuf\Internal\RepeatedField;
use Raintyyek\Ocr\DTO\BoundingBox;
use Raintyyek\Ocr\DTO\OcrResult;
use Raintyyek\Ocr\DTO\Point;
use Raintyyek\Ocr\DTO\TextBlock;
use Raintyyek\Ocr\Engines\AbstractOcrEngine;
use Raintyyek\Ocr\Enums\BlockType;
use Raintyyek\Ocr\Exceptions\OcrConfigurationException;
use Raintyyek\Ocr\Exceptions\OcrProcessingException;
use Raintyyek\Ocr\Support\ImageSource;
use Throwable;

/**
 * OCR engine backed by Google Cloud Vision.
 *
 * Uses the `documentTextDetection` / `textDetection` helpers on the official
 * `ImageAnnotatorClient`. The full text annotation is walked down to word
 * granularity so callers get both the concatenated text and positioned blocks.
 *
 * @see https://cloud.google.com/vision/docs/ocr
 */
final class GoogleVisionEngine extends AbstractOcrEngine
{
    private ?ImageAnnotatorClient $client = null;

    public function name(): string
    {
        return 'google';
    }

    public function recognize(ImageSource $image, array $options = []): OcrResult
    {
        $options = $this->resolveOptions($options);
        $client  = $this->client();

        try {
            // "document" mode is optimised for dense text (invoices, receipts);
            // "text" mode is better for sparse text in natural scenes.
            $response = ($options['mode'] ?? 'document') === 'text'
                ? $client->textDetection($image->bytes(), $this->imageContext($options))
                : $client->documentTextDetection($image->bytes(), $this->imageContext($options));

            if ($response->getError() !== null && $response->getError()->getCode() !== 0) {
                throw new OcrProcessingException(sprintf(
                    'Google Vision returned an error: %s',
                    $response->getError()->getMessage(),
                ));
            }

            $annotation = $response->getFullTextAnnotation();
            $text       = $annotation?->getText() ?? '';
            $blocks     = $annotation ? $this->extractBlocks($annotation->getPages()) : [];

            return new OcrResult(
                engine: $this->name(),
                text: $text,
                blocks: $this->applyConfidenceFilter($blocks, $options),
                raw: $response,
                meta: ['pages' => $annotation ? count($annotation->getPages()) : 0],
            );
        } catch (OcrProcessingException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw OcrProcessingException::from($this->name(), $e);
        }
    }

    /**
     * Resolve (and memoize) the Vision client from configuration. The SDK is an
     * optional dependency, so its absence is reported as a configuration error
     * with an actionable message rather than a fatal "class not found".
     */
    private function client(): ImageAnnotatorClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (! class_exists(ImageAnnotatorClient::class)) {
            throw OcrConfigurationException::missingSdk('google', 'google/cloud-vision');
        }

        return $this->client = new ImageAnnotatorClient($this->clientOptions());
    }

    /**
     * Translate our config keys into the SDK's constructor options, supporting
     * either a JSON key path or inline JSON credentials.
     *
     * @return array<string, mixed>
     */
    private function clientOptions(): array
    {
        $options = [];

        if (! empty($this->config['credentials_json'])) {
            $decoded = json_decode((string) $this->config['credentials_json'], true);

            if (! is_array($decoded)) {
                throw OcrConfigurationException::missingCredentials('google', 'credentials_json is not valid JSON.');
            }

            $options['credentials'] = $decoded;
        } elseif (! empty($this->config['credentials_path'])) {
            $options['credentials'] = $this->config['credentials_path'];
        }

        if (! empty($this->config['project_id'])) {
            $options['projectId'] = $this->config['project_id'];
        }

        return $options;
    }

    /**
     * Build the per-request image context (currently just language hints).
     *
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function imageContext(array $options): array
    {
        $hints = $options['language_hints'] ?? [];

        return $hints === [] ? [] : ['imageContext' => ['languageHints' => $hints]];
    }

    /**
     * Flatten Vision's page → block → paragraph → word tree into a single list
     * of {@see TextBlock}s, one per word. Word granularity is the most useful
     * default for downstream field extraction; callers can regroup as needed.
     *
     * @param  RepeatedField<Page> $pages
     * @return list<TextBlock>
     */
    private function extractBlocks(RepeatedField $pages): array
    {
        $blocks = [];

        /** @var Page $page */
        foreach ($pages as $page) {
            /** @var Block $block */
            foreach ($page->getBlocks() as $block) {
                /** @var Paragraph $paragraph */
                foreach ($block->getParagraphs() as $paragraph) {
                    /** @var Word $word */
                    foreach ($paragraph->getWords() as $word) {
                        $blocks[] = new TextBlock(
                            text: $this->wordText($word),
                            type: BlockType::Word,
                            confidence: $this->normalizeConfidence($word->getConfidence()),
                            boundingBox: $this->boundingBox($word),
                        );
                    }
                }
            }
        }

        return $blocks;
    }

    /**
     * Concatenate a word's symbols into its literal text.
     */
    private function wordText(Word $word): string
    {
        $text = '';

        /** @var Symbol $symbol */
        foreach ($word->getSymbols() as $symbol) {
            $text .= $symbol->getText();
        }

        return $text;
    }

    /**
     * Convert a Vision bounding polygon (normalized or absolute) into our
     * normalized {@see BoundingBox}. Vision provides normalized vertices for
     * full-text annotations, which is exactly what our DTO expects.
     */
    private function boundingBox(Word $word): ?BoundingBox
    {
        $poly = $word->getBoundingBox();

        if ($poly === null) {
            return null;
        }

        $vertices = [];

        foreach ($poly->getNormalizedVertices() as $vertex) {
            $vertices[] = new Point((float) $vertex->getX(), (float) $vertex->getY());
        }

        return $vertices === [] ? null : new BoundingBox($vertices);
    }

    /**
     * Vision confidences are already 0.0–1.0; a 0.0 typically means "not
     * reported", which we surface as null to distinguish it from a real zero.
     */
    private function normalizeConfidence(float $confidence): ?float
    {
        return $confidence > 0.0 ? $confidence : null;
    }
}
