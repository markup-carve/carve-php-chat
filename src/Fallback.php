<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

enum Fallback: string
{
    case Unwrap = 'unwrap';
    case Inline = 'inline';
    case CodeBlock = 'codeblock';
    case Appendix = 'appendix';
    case Drop = 'drop';
}
