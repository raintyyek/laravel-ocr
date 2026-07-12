<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Enums;

/**
 * The granularity of a recognized {@see \Raintyyek\Ocr\DTO\TextBlock}.
 *
 * Providers expose different structural units; these values normalize them to
 * a common vocabulary so consumers can filter results consistently regardless
 * of the underlying engine.
 */
enum BlockType: string
{
    case Page      = 'page';
    case Block     = 'block';
    case Paragraph = 'paragraph';
    case Line      = 'line';
    case Word      = 'word';
}
