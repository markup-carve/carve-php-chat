<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

final readonly class ChatResult
{
    /**
     * @param string $text
     * @param array<\MarkupCarve\Chat\Loss> $losses
     */
    public function __construct(
        public string $text,
        public array $losses = [],
    ) {
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
