<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Enums;

/**
 * How an {@see \Raintyyek\Ocr\Support\ImageSource} holds its image, and how a
 * persisted call remembers where to fetch it from when run later.
 *
 *   Path    → a local filesystem path.
 *   Bytes   → raw binary held in memory (not persistable by reference — spooled
 *             to Storage before a call is scheduled).
 *   Url     → a remote HTTP(S) URL.
 *   S3      → an object in Amazon S3 (bucket + key).
 *   Storage → a path on a configured Laravel filesystem disk (used for spooling).
 */
enum SourceType: string
{
    case Path    = 'path';
    case Bytes   = 'bytes';
    case Url     = 'url';
    case S3      = 's3';
    case Storage = 'storage';
}
