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

    /**
     * Keep the Carve source markup. Where a target can express nothing, the
     * original delimiters at least make the intent visible - a reader sees
     * `{=highlighted=}` rather than a word that looks unremarkable.
     */
    case Carve = 'carve';
}
