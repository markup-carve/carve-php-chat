<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

final readonly class Escaper
{
    public function __construct(
        private EscapeMechanism $mechanism,
        private string $chars = '',
    ) {
    }

    public function escape(string $text): string
    {
        return match ($this->mechanism) {
            EscapeMechanism::Backslash => $this->escapeBackslash($text),
            EscapeMechanism::Entities => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text),
            EscapeMechanism::None => $text,
        };
    }

    /**
     * Escapes content that must survive verbatim: code spans, code blocks, and
     * URLs emitted as plain text.
     *
     * Delimiter escaping must not apply - a backslash before `*` inside code
     * shows up literally, and one inside a URL changes the address. Entity
     * escaping still must, because an HTML-mode target puts this payload inside
     * real tags, where an unescaped `<` or `&` makes the message invalid.
     */
    public function escapeVerbatim(string $text): string
    {
        return match ($this->mechanism) {
            EscapeMechanism::Entities => $this->escape($text),
            EscapeMechanism::Backslash, EscapeMechanism::None => $text,
        };
    }

    private function escapeBackslash(string $text): string
    {
        if ($this->chars === '') {
            return $text;
        }

        $chars = preg_quote($this->chars, '/');

        return preg_replace('/([' . $chars . '])/', '\\\\$1', $text) ?? $text;
    }
}
