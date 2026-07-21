<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

/**
 * How a target carries formatting.
 *
 * Chat platforms split into two families. Most keep formatting inside the
 * message string as delimiters. Others - Signal, Telegram's `entities` API,
 * Slack Block Kit - send plain text plus style offsets alongside it.
 */
enum OutputMode: string
{
    case Markup = 'markup';
    case Ranges = 'ranges';
}
