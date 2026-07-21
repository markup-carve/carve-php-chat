<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function class_exists;
use function file_get_contents;
use function glob;
use function preg_match_all;

/**
 * PHP does not error on `instanceof` against a class that was never imported -
 * it silently evaluates to false. That has bitten this renderer twice: once
 * with Emphasis, which dropped every emphasis mark, and once with
 * InlineExtension, which dropped every spoiler. Both passed the whole suite,
 * because the wrong branch is a plausible-looking one.
 */
final class SourceIntegrityTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function sourceFileProvider(): array
    {
        $cases = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/*.php') ?: [] as $path) {
            $cases[basename($path)] = [$path];
        }

        return $cases;
    }

    #[DataProvider('sourceFileProvider')]
    public function testEveryInstanceofTargetResolvesToARealClass(string $path): void
    {
        $source = (string)file_get_contents($path);

        preg_match_all('/instanceof\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $matches);
        $names = array_unique($matches[1]);

        preg_match_all('/^use\s+([^;]+);/m', $source, $useMatches);
        $imported = [];
        foreach ($useMatches[1] as $use) {
            $parts = explode('\\', trim($use));
            $imported[end($parts)] = trim($use);
        }

        if ($names === []) {
            $this->addToAssertionCount(1);

            return;
        }

        foreach ($names as $name) {
            $fqcn = $imported[$name] ?? 'MarkupCarve\\Chat\\' . $name;
            self::assertTrue(
                class_exists($fqcn) || interface_exists($fqcn),
                sprintf('%s uses "instanceof %s", which resolves to %s - not a real class.', basename($path), $name, $fqcn),
            );
        }
    }
}
