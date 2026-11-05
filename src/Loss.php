<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

final readonly class Loss
{
    /**
     * @param string $nodeType
     * @param int|null $sourceLine
     * @param \MarkupCarve\Chat\Fallback $fallback
     * @param string $reason
     * @param string|null $file
     * @param array<array{file: string, line: int, column: int}>|null $includedBy Include directives that reached $file, outermost first.
     */
    public function __construct(
        public string $nodeType,
        public ?int $sourceLine,
        public Fallback $fallback,
        public string $reason,
        public ?string $file = null,
        public ?array $includedBy = null,
    ) {
    }
}
