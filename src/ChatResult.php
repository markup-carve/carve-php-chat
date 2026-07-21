<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

final readonly class ChatResult
{
    /**
     * @param string $text
     * @param array<\MarkupCarve\Chat\Loss> $losses
     * @param array<\MarkupCarve\Chat\StyleRange> $ranges Style offsets, for
     *   range-based targets. Always empty for delimiter-based ones, where the
     *   formatting lives in $text instead.
     */
    public function __construct(
        public string $text,
        public array $losses = [],
        public array $ranges = [],
    ) {
    }

    public function hasRanges(): bool
    {
        return $this->ranges !== [];
    }

    /**
     * The ranges as plain arrays, ready to hand to an API that wants them as
     * JSON (Telegram's `entities`, for instance).
     *
     * @return array<int, array<string, mixed>>
     */
    public function rangesToArray(): array
    {
        return array_map(static fn (StyleRange $range): array => $range->toArray(), $this->ranges);
    }

    public function hasLosses(): bool
    {
        return $this->losses !== [];
    }

    /**
     * @return array<\MarkupCarve\Chat\Loss>
     */
    public function lossesFor(string $nodeType): array
    {
        return array_values(array_filter(
            $this->losses,
            static fn (Loss $loss): bool => $loss->nodeType === $nodeType,
        ));
    }
}
