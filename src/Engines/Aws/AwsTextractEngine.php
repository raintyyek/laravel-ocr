<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Engines\Aws;

use Aws\Textract\TextractClient;
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
 * OCR engine backed by AWS Textract's synchronous `DetectDocumentText` API.
 *
 * Images are sent inline as bytes by default, which suits the common "one
 * image, immediate result" use case. Callers who keep documents in S3 can pass
 * `['s3' => ['bucket' => ..., 'name' => ...]]` in the options to skip the
 * upload and let Textract read straight from the bucket.
 *
 * @see https://docs.aws.amazon.com/textract/latest/dg/API_DetectDocumentText.html
 */
final class AwsTextractEngine extends AbstractOcrEngine
{
    private ?TextractClient $client = null;

    public function name(): string
    {
        return 'aws';
    }

    public function recognize(ImageSource $image, array $options = []): OcrResult
    {
        $options = $this->resolveOptions($options);

        try {
            $result = $this->client()->detectDocumentText([
                'Document' => $this->buildDocument($image, $options),
            ]);

            /** @var list<array<string, mixed>> $rawBlocks */
            $rawBlocks = $result->get('Blocks') ?? [];

            $blocks = $this->extractBlocks($rawBlocks);

            return new OcrResult(
                engine: $this->name(),
                text: $this->joinLines($blocks),
                blocks: $this->applyConfidenceFilter($blocks, $options),
                raw: $result->toArray(),
                meta: [
                    'pages' => $result->search('DocumentMetadata.Pages') ?? 1,
                ],
            );
        } catch (Throwable $e) {
            throw OcrProcessingException::from($this->name(), $e);
        }
    }

    /**
     * Resolve (and memoize) the Textract client. The AWS SDK is an optional
     * dependency, so a missing package is reported as a configuration error.
     */
    private function client(): TextractClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (! class_exists(TextractClient::class)) {
            throw OcrConfigurationException::missingSdk('aws', 'aws/aws-sdk-php');
        }

        $args = [
            'version' => $this->config['version'] ?? 'latest',
            'region'  => $this->config['region'] ?? 'us-east-1',
        ];

        // Only pass explicit credentials when configured; otherwise defer to the
        // SDK's default provider chain (env vars, shared ini file, IAM roles).
        if (! empty($this->config['key']) && ! empty($this->config['secret'])) {
            $args['credentials'] = array_filter([
                'key'    => $this->config['key'],
                'secret' => $this->config['secret'],
                'token'  => $this->config['token'] ?? null,
            ], static fn ($v) => $v !== null);
        }

        return $this->client = new TextractClient($args);
    }

    /**
     * Build the Textract `Document` payload — either an S3 object reference or
     * inline bytes, depending on the caller's options and engine config.
     *
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildDocument(ImageSource $image, array $options): array
    {
        if (! empty($options['s3']['bucket']) && ! empty($options['s3']['name'])) {
            return [
                'S3Object' => array_filter([
                    'Bucket'  => $options['s3']['bucket'],
                    'Name'    => $options['s3']['name'],
                    'Version' => $options['s3']['version'] ?? null,
                ], static fn ($v) => $v !== null),
            ];
        }

        return ['Bytes' => $image->bytes()];
    }

    /**
     * Map Textract's flat block list into our {@see TextBlock}s. Textract emits
     * PAGE, LINE and WORD blocks in one array; we keep LINE and WORD, which
     * together cover both readable text and token-level positioning.
     *
     * @param  list<array<string, mixed>> $rawBlocks
     * @return list<TextBlock>
     */
    private function extractBlocks(array $rawBlocks): array
    {
        $blocks = [];

        foreach ($rawBlocks as $raw) {
            $type = match ($raw['BlockType'] ?? null) {
                'LINE' => BlockType::Line,
                'WORD' => BlockType::Word,
                default => null, // Skip PAGE and any block types we don't model.
            };

            if ($type === null || ! isset($raw['Text'])) {
                continue;
            }

            $blocks[] = new TextBlock(
                text: (string) $raw['Text'],
                type: $type,
                confidence: $this->normalizeConfidence($raw['Confidence'] ?? null),
                boundingBox: $this->boundingBox($raw['Geometry']['Polygon'] ?? null),
            );
        }

        return $blocks;
    }

    /**
     * Reconstruct the full document text from its LINE blocks, in the order
     * Textract returned them (already top-to-bottom, left-to-right).
     *
     * @param list<TextBlock> $blocks
     */
    private function joinLines(array $blocks): string
    {
        $lines = array_map(
            static fn (TextBlock $b) => $b->text,
            array_filter($blocks, static fn (TextBlock $b) => $b->type === BlockType::Line),
        );

        return implode("\n", $lines);
    }

    /**
     * Convert a Textract polygon (list of {X, Y} normalized points) into our
     * normalized {@see BoundingBox}.
     *
     * @param list<array{X: float, Y: float}>|null $polygon
     */
    private function boundingBox(?array $polygon): ?BoundingBox
    {
        if (empty($polygon)) {
            return null;
        }

        $vertices = array_map(
            static fn (array $p) => new Point((float) ($p['X'] ?? 0.0), (float) ($p['Y'] ?? 0.0)),
            $polygon,
        );

        return new BoundingBox(array_values($vertices));
    }

    /**
     * Textract confidences are 0–100; normalize to the library-wide 0.0–1.0.
     */
    private function normalizeConfidence(mixed $confidence): ?float
    {
        return $confidence === null ? null : ((float) $confidence) / 100.0;
    }
}
