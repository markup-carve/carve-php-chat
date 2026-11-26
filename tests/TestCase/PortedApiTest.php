<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Chat\ChatPreviewRenderer;
use MarkupCarve\Chat\ChatRenderer;
use MarkupCarve\Chat\FlavorRegistry;
use PHPUnit\Framework\TestCase;

final class PortedApiTest extends TestCase
{
    public function testPublicParseAndWriterRegistry(): void
    {
        $tree = Carve::parse("Hi *there*.\n");
        $flavor = (new FlavorRegistry())->get('slack');
        $writer = new ChatRenderer($flavor);
        $registry = Carve::writers()->register($writer);

        self::assertSame('chat-slack', $writer->target());
        self::assertSame("Hi *there*.\n", Carve::writeTo($writer->target(), $tree, [], $registry));
        self::assertSame('<p>Hi <strong>there</strong>.</p>', (new ChatPreviewRenderer($flavor))->render($tree));
    }

    public function testWriterKeepsSafeUrlPolicy(): void
    {
        $tree = Carve::parse('[click](javascript:alert(1))');
        $flavor = (new FlavorRegistry())->get('telegram-html');
        $writer = new ChatRenderer($flavor);

        self::assertSame("click\n", $writer->render($tree));
        self::assertStringNotContainsString('<a ', (new ChatPreviewRenderer($flavor))->render($tree));
    }

    public function testTableGridExpandsSpansInBothWriters(): void
    {
        $tree = Carve::parse("|= Name |= Qty |= Note |\n| Apple | 12 | fresh |\n| ^ | 3 | < |\n");
        $flavor = (new FlavorRegistry())->get('slack');

        self::assertStringContainsString("Apple  3    3\n", (new ChatRenderer($flavor))->render($tree));
        self::assertStringContainsString('Apple  3    3', (new ChatPreviewRenderer($flavor))->render($tree));
    }
}
