<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

/**
 * The unit a range-based target counts offsets in.
 *
 * This is not a detail to guess at: Telegram documents its entity offsets in
 * UTF-16 code units, so an emoji outside the BMP counts as two. Getting the
 * unit wrong shifts every range after the first such character.
 */
enum OffsetUnit: string
{
    case Utf16 = 'utf16';
    case Utf8 = 'utf8';
    case Codepoints = 'codepoints';
}
