<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Node;
use MarkupCarve\Chat\ChatFlavor;
use MarkupCarve\Chat\FlavorRegistry;
use PHPUnit\Framework\TestCase;

final class FlavorCompletenessTest extends TestCase
{
    /**
     * @var array
     */
    /**
     * @var array<string>
     */
    private const STRUCTURAL = ['document', 'include'];

    public function testBundledFlavorsCoverEveryCoreNodeType(): void
    {
        $registry = new FlavorRegistry();
        foreach ($registry->ids() as $id) {
            $flavor = $registry->get($id);
            foreach ($this->schemaTypes() as $type) {
                if (in_array($type, self::STRUCTURAL, true)) {
                    continue;
                }
                self::assertIsArray($flavor->emission($type), $id . ' missing ' . $type);
            }
        }
    }

    public function testBundledFlavorsCoverNodeTypesThatHaveNoConstant(): void
    {
        // AST 2.0 uses one public Node class. Its schema inventory replaces the
        // 0.1 NodeType constant and subclass checks (C2-A-021).
        $types = ChatFlavor::knownNodeTypes();
        sort($types);
        $schema = $this->schemaTypes();
        sort($schema);
        self::assertSame($schema, $types);
    }

    public function testExtraNodeTypeListMatchesTheCore(): void
    {
        $tree = Carve::parse("# Title\n\nA *strong* word.\n");
        self::assertInstanceOf(Node::class, $tree);
        foreach ($tree->children ?? [] as $child) {
            self::assertInstanceOf(Node::class, $child);
        }
    }

    /**
     * @return array<string>
     */
    private function schemaTypes(): array
    {
        $path = dirname(__DIR__, 2) . '/vendor/markup-carve/carve-php/spec/data/ast-schema.json';
        $schema = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_filter(array_keys($schema['$defs']), 'is_string'));
    }
}
