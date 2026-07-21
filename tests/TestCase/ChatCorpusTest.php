<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Chat\ChatRenderer;
use MarkupCarve\Chat\FlavorRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChatCorpusTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function corpusProvider(): array
    {
        // Derived from the registry, not a hard-coded list: adding a flavor must
        // pick up corpus coverage on its own rather than passing unnoticed.
        $targets = [];
        foreach ((new FlavorRegistry())->ids() as $id) {
            $targets[] = $id === 'telegram-html' ? 'telegram' : $id;
        }

        $cases = [];
        foreach (glob(__DIR__ . '/../corpus-chat/*.crv') ?: [] as $sourcePath) {
            $slug = basename($sourcePath, '.crv');
            foreach ($targets as $target) {
                $expectedPath = dirname($sourcePath) . '/' . $slug . '.' . $target;
                if (is_file($expectedPath)) {
                    $cases[$slug . ' ' . $target] = [$sourcePath, $target, $expectedPath];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('corpusProvider')]
    public function testCorpus(string $sourcePath, string $target, string $expectedPath): void
    {
        $source = file_get_contents($sourcePath);
        $expected = file_get_contents($expectedPath);
        self::assertIsString($source);
        self::assertIsString($expected);

        $flavorId = $target === 'telegram' ? 'telegram-html' : $target;
        $document = (new BlockParser())->parse($source);
        $actual = (new ChatRenderer((new FlavorRegistry())->get($flavorId)))->render($document);

        self::assertSame($expected, $actual);
    }
}
