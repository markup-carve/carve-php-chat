<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Chat\ChatRenderer;
use MarkupCarve\Chat\FlavorRegistry;
use MarkupCarve\Chat\LinkStyle;
use PHPUnit\Framework\TestCase;

final class CustomFlavorTest extends TestCase
{
    public function testCustomZulipFlavorRendersFromJsonOnly(): void
    {
        $registry = new FlavorRegistry();
        $flavor = $registry->fromJsonFile(__DIR__ . '/../fixtures/flavors/zulip.json');

        $document = new Document();
        $paragraph = new Paragraph();
        $strong = new Strong();
        $strong->appendChild(new Text('Zulip'));
        $paragraph->appendChild($strong);
        $paragraph->appendChild(new Text(' '));
        $link = new Link('https://zulip.com');
        $link->appendChild(new Text('site'));
        $paragraph->appendChild($link);
        $document->appendChild($paragraph);

        $result = (new ChatRenderer($flavor))->renderResult($document);

        self::assertSame('**Zulip** [site](https://zulip.com)' . "\n", $result->text);
        self::assertSame(false, $result->hasLosses());
    }

    /**
     * A custom flavor must inherit through `extends` exactly like a bundled one,
     * so a partial definition only has to state its deltas.
     */
    public function testCustomFlavorInheritsFromBundledParent(): void
    {
        $flavor = (new FlavorRegistry())->fromJsonFile(__DIR__ . '/../fixtures/flavors/slack-internal.json');

        $document = new Document();
        $heading = new Heading(1);
        $heading->appendChild(new Text('Title'));
        $document->appendChild($heading);
        $paragraph = new Paragraph();
        $strong = new Strong();
        $strong->appendChild(new Text('bold'));
        $paragraph->appendChild($strong);
        $document->appendChild($paragraph);

        $result = (new ChatRenderer($flavor))->renderResult($document);

        self::assertSame(LinkStyle::SlackPipe, $flavor->linkStyle());
        self::assertSame("*Title*\n\n*bold*\n", $result->text);
    }

    /**
     * A declared node entry replaces the inherited one outright. Merging meant
     * a child could not dislodge a key it did not restate: redeclaring the
     * quote prefix left the parent's `template` in charge, and the parent's
     * markup leaked into the output.
     */
    public function testDeclaredNodeReplacesTheInheritedEntry(): void
    {
        $flavor = (new FlavorRegistry())->fromJsonFile(__DIR__ . '/../fixtures/flavors/quote-override.json');

        $document = CarveConverter::create()->parse("> quoted\n");

        self::assertSame("| quoted\n", (new ChatRenderer($flavor))->render($document));
    }
}
