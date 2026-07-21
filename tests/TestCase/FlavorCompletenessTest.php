<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use MarkupCarve\Carve\NodeType;
use MarkupCarve\Chat\FlavorRegistry;
use PHPUnit\Framework\TestCase;

final class FlavorCompletenessTest extends TestCase
{
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
}
