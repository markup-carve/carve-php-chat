<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Chat\ChatPreviewRenderer;
use MarkupCarve\Chat\ChatRenderer;
use MarkupCarve\Chat\FlavorRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Input shapes the carve-php 0.1.17 parser reads differently from 0.1.16.
 */
final class EngineWaveInputTest extends TestCase
{
    /**
     * @var string
     */
    private const BRACED_ALIGNMENT = "|={<} Item |={>} Qty |\n| Apple |{>} 3 |\n";

    public function testBracedCellAlignmentLeavesNoBracesInAnyFlavor(): void
    {
        $registry = new FlavorRegistry();
        $document = CarveConverter::create()->parse(self::BRACED_ALIGNMENT);

        foreach ($registry->ids() as $id) {
            $text = (new ChatRenderer($registry->get($id)))->renderResult($document)->text;

            self::assertStringNotContainsString('{', $text, $id);
            self::assertStringContainsString('Item', $text, $id);
        }
    }

    public function testBracedCellAlignmentRendersPlainCells(): void
    {
        $document = CarveConverter::create()->parse(self::BRACED_ALIGNMENT);
        $flavor = (new FlavorRegistry())->get('discord');

        self::assertSame("```\nItem   Qty\nApple  3\n```\n", (new ChatRenderer($flavor))->render($document));
        self::assertSame(
            "<pre><code>Item   Qty\nApple  3</code></pre>",
            (new ChatPreviewRenderer($flavor))->render($document),
        );
    }

    public function testErasedCommentLineJoinsTheParagraph(): void
    {
        $document = CarveConverter::create()->parse("alpha\n%% gone\nbeta\n");
        $flavor = (new FlavorRegistry())->get('slack');

        self::assertSame("alpha\nbeta\n", (new ChatRenderer($flavor))->render($document));
        self::assertSame('<p>alpha<br>beta</p>', (new ChatPreviewRenderer($flavor))->render($document));
    }
}
