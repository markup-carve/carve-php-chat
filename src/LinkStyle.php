<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

enum LinkStyle: string
{
    case None = 'none';
    case Markdown = 'markdown';
    case SlackPipe = 'slackPipe';
    case Html = 'html';
}
