<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

final readonly class Loss
{
    public function __construct(
        public string $nodeType,
        public ?int $sourceLine,
        public Fallback $fallback,
        public string $reason,
    ) {
    }
}
