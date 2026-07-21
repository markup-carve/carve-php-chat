<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

/**
 * A styled span of the plain-text body, for targets that carry formatting as
 * offsets rather than delimiters.
 *
 * `start` and `length` are counted in the flavor's declared offset unit, not
 * in bytes.
 *
 * Some styles need more than a name. Telegram's `text_link` carries the URL
 * and `pre` carries the language, because the body itself has no room for
 * them once the delimiters are gone.
 */
final readonly class StyleRange
{
    /**
     * @param int $start
     * @param int $length
     * @param string $style
     * @param array<string, string> $data Extra fields the style needs, e.g.
     *   `url` for a link or `language` for a code block.
     */
    public function __construct(
        public int $start,
        public int $length,
        public string $style,
        public array $data = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['start' => $this->start, 'length' => $this->length, 'style' => $this->style] + $this->data;
    }
}
