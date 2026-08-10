<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use MarkupCarve\Carve\NodeType;
use MarkupCarve\Chat\ChatFlavor;
use MarkupCarve\Chat\FlavorRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use function file_get_contents;
use function preg_match;

final class FlavorCompletenessTest extends TestCase
{
    /**
     * Node types the renderer handles structurally rather than through the
     * flavor table.
     *
     * @var array<string>
     */
    private const EXEMPT = ['document'];

    public function testBundledFlavorsCoverEveryCoreNodeType(): void
    {
        $registry = new FlavorRegistry();
        $nodeTypes = array_merge(NodeType::allBlockTypes(), NodeType::allInlineTypes());

        foreach ($registry->ids() as $id) {
            $flavor = $registry->get($id);
            foreach ($nodeTypes as $nodeType) {
                self::assertIsArray($flavor->emission($nodeType), $id . ' missing ' . $nodeType);
            }
        }
    }

    /**
     * The gate above reflects over NodeType, which is not the whole story: five
     * node classes have no constant there. One of them, `citation_group`, had
     * no entry in any flavor, so every citation was silently dropped and no
     * test noticed. This derives the list from the node classes themselves.
     */
    public function testBundledFlavorsCoverNodeTypesThatHaveNoConstant(): void
    {
        $registry = new FlavorRegistry();

        foreach ($this->nodeTypesFromSource() as $nodeType => $class) {
            if (in_array($nodeType, self::EXEMPT, true)) {
                continue;
            }

            foreach ($registry->ids() as $id) {
                self::assertIsArray(
                    $registry->get($id)->emission($nodeType),
                    sprintf('%s has no entry for "%s" (%s), so those nodes degrade unchecked.', $id, $nodeType, $class),
                );
            }
        }
    }

    /**
     * Keeps the hardcoded list of constant-less types honest: a new one in the
     * core must be added deliberately rather than slipping past both gates.
     */
    public function testExtraNodeTypeListMatchesTheCore(): void
    {
        $constants = array_values(array_filter(
            (new ReflectionClass(NodeType::class))->getConstants(),
            static fn ($value): bool => is_string($value),
        ));

        $withoutConstant = array_values(array_diff(
            array_keys($this->nodeTypesFromSource()),
            $constants,
        ));

        sort($withoutConstant);
        $declared = ChatFlavor::EXTRA_NODE_TYPES;
        sort($declared);

        self::assertSame($declared, $withoutConstant);
    }

    /**
     * Every node type the core can actually produce, read from the `getType()`
     * of each node class.
     *
     * @return array<string, string>
     */
    private function nodeTypesFromSource(): array
    {
        $root = dirname(__DIR__, 2) . '/vendor/markup-carve/carve-php/src/Node';
        self::assertDirectoryExists($root);

        $types = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string)file_get_contents($file->getPathname());
            if (preg_match('/function getType\(\): string\s*\{\s*return \'([^\']+)\';/', $source, $matches) === 1) {
                $types[$matches[1]] = $file->getBasename('.php');
            }
        }

        self::assertNotSame([], $types, 'Found no node classes - the scan path is wrong.');

        return $types;
    }
}
