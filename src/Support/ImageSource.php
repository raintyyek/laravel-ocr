<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Support;

use Illuminate\Support\Facades\Storage;
use Raintyyek\Ocr\Enums\SourceType;
use Raintyyek\Ocr\Exceptions\OcrException;

/**
 * A normalized, engine-agnostic reference to an image.
 *
 * Callers can hand the library a local path, raw bytes, a base64 string, a
 * remote URL, an S3 object, or a Laravel Storage path; every engine then
 * consumes the same object. Bytes are read lazily and cached, so constructing a
 * source is cheap and materializing it happens at most once.
 *
 * S3 and Storage sources are read through Laravel's filesystem (`Storage`), so
 * their credentials, region and bucket come from the app's `config/filesystems.php`
 * — this library never holds a second copy of your S3 settings.
 *
 * A source can also be serialized to a plain array via {@see describe()} and
 * rebuilt with {@see fromReference()} — this is how scheduled calls remember
 * where to fetch their image when they run later in a background worker.
 */
final class ImageSource
{
    /** Lazily resolved raw image bytes. */
    private ?string $resolvedBytes = null;

    /**
     * @param SourceType           $type Kind of source.
     * @param array<string, mixed> $ref  Type-specific locator (path, bytes, url,
     *                                    disk/path).
     */
    private function __construct(
        private readonly SourceType $type,
        private readonly array $ref,
    ) {
    }

    // ---------------------------------------------------------------------
    // Factories
    // ---------------------------------------------------------------------

    /** Build a source from a local filesystem path. */
    public static function fromPath(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new OcrException("Image file does not exist or is not readable: {$path}");
        }

        return new self(SourceType::Path, ['path' => $path]);
    }

    /** Build a source from raw binary image data already in memory. */
    public static function fromBytes(string $bytes): self
    {
        if ($bytes === '') {
            throw new OcrException('Cannot build an ImageSource from empty bytes.');
        }

        return new self(SourceType::Bytes, ['bytes' => $bytes]);
    }

    /** Build a source from a base64-encoded string (data-URI prefix tolerated). */
    public static function fromBase64(string $base64): self
    {
        if (str_contains($base64, ',')) {
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }

        $decoded = base64_decode(strtr(trim($base64), ' ', '+'), true);

        if ($decoded === false) {
            throw new OcrException('Provided string is not valid base64 image data.');
        }

        return self::fromBytes($decoded);
    }

    /** Build a source from a remote URL (fetched lazily). */
    public static function fromUrl(string $url): self
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new OcrException("Invalid image URL: {$url}");
        }

        return new self(SourceType::Url, ['url' => $url]);
    }

    /**
     * Build a source from an object key on a Laravel S3 filesystem disk.
     *
     * The bucket, region and credentials come from `config/filesystems.php` for
     * the given disk (default: the disk named in `ocr.s3.disk`, usually "s3").
     */
    public static function fromS3(string $key, ?string $disk = null): self
    {
        if ($key === '') {
            throw new OcrException('S3 sources require an object key.');
        }

        return new self(SourceType::S3, [
            'disk' => $disk,
            'path' => ltrim($key, '/'),
        ]);
    }

    /**
     * Build an S3 source from an "s3://key" style path. Everything after the
     * scheme is treated as the object key on the configured S3 disk.
     */
    public static function fromS3Path(string $path, ?string $disk = null): self
    {
        $key = preg_replace('#^s3://#i', '', $path) ?? $path;

        return self::fromS3(ltrim($key, '/'), $disk);
    }

    /** Build a source from a path on any configured Laravel Storage disk. */
    public static function fromStorage(string $disk, string $path): self
    {
        return new self(SourceType::Storage, ['disk' => $disk, 'path' => $path]);
    }

    /**
     * Best-effort factory that guesses the right constructor from a string:
     * an "s3://" URI, an HTTP(S) URL, an existing file path, or raw/base64 bytes.
     */
    public static function make(string $input): self
    {
        if (preg_match('#^s3://#i', $input) === 1) {
            return self::fromS3Path($input);
        }

        if (filter_var($input, FILTER_VALIDATE_URL) !== false) {
            return self::fromUrl($input);
        }

        // Only stat() short strings to avoid touching the disk for binary blobs.
        if (strlen($input) < 4096 && @is_file($input)) {
            return self::fromPath($input);
        }

        return self::fromBytes($input);
    }

    // ---------------------------------------------------------------------
    // Accessors
    // ---------------------------------------------------------------------

    public function type(): SourceType
    {
        return $this->type;
    }

    /** Whether the image only exists in memory (and thus must be spooled to be scheduled). */
    public function isInMemory(): bool
    {
        return $this->type === SourceType::Bytes;
    }

    /**
     * S3 locator (bucket + key) for engines such as AWS Textract that can read
     * the object in place without downloading it. The bucket is read from the
     * disk's `config/filesystems.php` entry. Returns null for non-S3 sources or
     * when the disk has no bucket configured (the caller then falls back to
     * downloading the bytes).
     *
     * @return array{bucket: string, key: string, version: string|null}|null
     */
    public function s3(): ?array
    {
        if ($this->type !== SourceType::S3) {
            return null;
        }

        $disk   = $this->disk();
        $bucket = config("filesystems.disks.{$disk}.bucket");

        if (empty($bucket)) {
            return null;
        }

        // Honor the disk's root prefix so the passthrough key matches what
        // Storage::disk()->get() would resolve to.
        $root = trim((string) config("filesystems.disks.{$disk}.root", ''), '/');
        $key  = $root === '' ? $this->ref['path'] : $root . '/' . $this->ref['path'];

        return [
            'bucket'  => (string) $bucket,
            'key'     => $key,
            'version' => null,
        ];
    }

    /** The original URL when built from one, otherwise null. */
    public function url(): ?string
    {
        return $this->type === SourceType::Url ? $this->ref['url'] : null;
    }

    /**
     * The raw image bytes, resolved and cached on first access.
     *
     * @throws OcrException On an unreadable path/URL/disk object.
     */
    public function bytes(): string
    {
        if ($this->resolvedBytes !== null) {
            return $this->resolvedBytes;
        }

        $bytes = match ($this->type) {
            SourceType::Bytes   => $this->ref['bytes'],
            SourceType::Path    => $this->readFile($this->ref['path']),
            SourceType::Url     => $this->readUrl($this->ref['url']),
            SourceType::S3      => $this->readDisk($this->disk(), $this->ref['path']),
            SourceType::Storage => $this->readDisk($this->ref['disk'], $this->ref['path']),
        };

        return $this->resolvedBytes = $bytes;
    }

    // ---------------------------------------------------------------------
    // Serialization (for scheduled calls)
    // ---------------------------------------------------------------------

    /**
     * A serializable locator describing how to rebuild this source later.
     *
     * In-memory byte sources cannot be described by reference — spool them to a
     * disk first (see the scheduling flow) so they become a Storage source.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        if ($this->type === SourceType::Bytes) {
            throw new OcrException(
                'In-memory byte sources cannot be serialized; spool them to a disk before scheduling.'
            );
        }

        // Persist the resolved disk for S3 so reprocessing is stable even if the
        // default disk config changes later.
        $ref = $this->type === SourceType::S3
            ? ['disk' => $this->disk(), 'path' => $this->ref['path']]
            : $this->ref;

        return ['type' => $this->type->value] + $ref;
    }

    /**
     * Rebuild a source from a {@see describe()} array.
     *
     * @param array<string, mixed> $ref
     */
    public static function fromReference(array $ref): self
    {
        $type = SourceType::from((string) ($ref['type'] ?? ''));

        return match ($type) {
            SourceType::Path    => self::fromPath($ref['path']),
            SourceType::Url     => self::fromUrl($ref['url']),
            SourceType::S3      => self::fromS3($ref['path'], $ref['disk'] ?? null),
            SourceType::Storage => self::fromStorage($ref['disk'], $ref['path']),
            SourceType::Bytes   => throw new OcrException('Byte sources cannot be rebuilt from a reference.'),
        };
    }

    // ---------------------------------------------------------------------
    // Internal
    // ---------------------------------------------------------------------

    /** The S3 disk for this source, resolving the configured default when unset. */
    private function disk(): string
    {
        return $this->ref['disk'] ?? (string) config('ocr.s3.disk', 's3');
    }

    private function readFile(string $path): string
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new OcrException("Failed to read image file: {$path}");
        }

        return $bytes;
    }

    private function readUrl(string $url): string
    {
        $bytes = @file_get_contents($url);

        if ($bytes === false) {
            throw new OcrException("Failed to download image from URL: {$url}");
        }

        return $bytes;
    }

    private function readDisk(string $disk, string $path): string
    {
        $bytes = Storage::disk($disk)->get($path);

        if ($bytes === null) {
            throw new OcrException("Failed to read image from disk [{$disk}]: {$path}");
        }

        return $bytes;
    }
}
