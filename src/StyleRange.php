<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

/**
 * A styled span of the plain-text body, for targets that carry formatting as
 * offsets rather than delimiters.
 *
 * `start` and `length` are counted in the flavor's declared offset unit, not
 * in bytes.
 */
final readonly class StyleRange
{
    public function __construct(
        public int $start,
        public int $length,
        public string $style,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['start' => $this->start, 'length' => $this->length, 'style' => $this->style];
    }
}
