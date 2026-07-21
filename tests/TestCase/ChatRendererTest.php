<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\SpoilerExtension;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Strike;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Chat\ChatRenderer;
use MarkupCarve\Chat\FlavorRegistry;
use PHPUnit\Framework\TestCase;

final class ChatRendererTest extends TestCase
{
    public function testPerFlavorRendering(): void
    {
        $document = $this->richDocument();
        $registry = new FlavorRegistry();

        self::assertSame(
            '*Title*' . "\n\n"
            . 'Hello _em_ *strong* ~strike~ `a*b` site (https://example.com).' . "\n\n"
            . '- one' . "\n"
            . '- two' . "\n\n"
            . '> quoted' . "\n\n"
            . 'logo (https://example.com/logo.png)' . "\n\n"
            . "```\nA  B\n1  2\n```\n\n"
            . '[1] note' . "\n",
            (new ChatRenderer($registry->get('whatsapp')))->render($document),
        );

        self::assertSame(
            '*Title*' . "\n\n"
            . 'Hello _em_ *strong* ~strike~ `a*b` <https://example.com|site>.' . "\n\n"
            . '- one' . "\n"
            . '- two' . "\n\n"
            . '> quoted' . "\n\n"
            . 'logo (https://example.com/logo.png)' . "\n\n"
            . "```\nA  B\n1  2\n```\n\n"
            . '[1] note' . "\n",
            (new ChatRenderer($registry->get('slack')))->render($document),
        );

        self::assertSame(
            '<b>Title</b>' . "\n\n"
            . 'Hello <i>em</i> <b>strong</b> <s>strike</s> <code>a*b</code> <a href="https://example.com">site</a>.' . "\n\n"
            . '- one' . "\n"
            . '- two' . "\n\n"
            . '<blockquote>quoted</blockquote>' . "\n\n"
            . 'logo (https://example.com/logo.png)' . "\n\n"
            . "<pre>\nA  B\n1  2\n</pre>\n\n"
            . '[1] note' . "\n",
            (new ChatRenderer($registry->get('telegram-html')))->render($document),
        );

        self::assertSame(
            '# Title' . "\n\n"
            . 'Hello *em* **strong** ~~strike~~ `a*b` site (https://example.com).' . "\n\n"
            . '- one' . "\n"
            . '- two' . "\n\n"
            . '> quoted' . "\n\n"
            . 'logo (https://example.com/logo.png)' . "\n\n"
            . "```\nA  B\n1  2\n```\n\n"
            . '[1] note' . "\n",
            (new ChatRenderer($registry->get('discord')))->render($document),
        );
    }

    /**
     * A `]` in the label would otherwise close the link early.
     */
    public function testMarkdownLinkLabelEscapesBrackets(): void
    {
        $document = CarveConverter::create()->parse('[a\\]b](https://x.com)');

        $rendered = (new ChatRenderer((new FlavorRegistry())->get('discord-bot')))->render($document);

        self::assertSame('[a\\]b](https://x.com)' . "\n", $rendered);
    }

    /**
     * A table falling back to a code block must use the flavor's own
     * delimiters, not a hard-coded Markdown fence.
     */
    public function testTableFallbackUsesFlavorCodeDelimiters(): void
    {
        $source = "| A | B |\n|---|---|\n| 1 | 2 |\n";
        $document = CarveConverter::create()->parse($source);
        $registry = new FlavorRegistry();

        self::assertSame(
            "<pre>\nA  B\n1  2\n</pre>\n",
            (new ChatRenderer($registry->get('telegram-html')))->render($document),
        );
        self::assertSame(
            "```\nA  B\n1  2\n```\n",
            (new ChatRenderer($registry->get('discord')))->render($document),
        );
    }

    /**
     * Slack mention syntax needs a real user ID. A Carve mention carries only
     * the literal label, so it is emitted as written rather than wrapped into
     * an ID that does not exist.
     */
    public function testMentionsAreNotWrappedIntoSlackIds(): void
    {
        $document = CarveConverter::create()->parse('Ping @alice here.');

        $rendered = (new ChatRenderer((new FlavorRegistry())->get('slack')))->render($document);

        self::assertSame('Ping @alice here.' . "\n", $rendered);
    }

    /**
     * Signal carries formatting as style ranges over a plain-text body rather
     * than as delimiters, so the marks survive as offsets and the body stays
     * free of markup. Only the heading, which has no style to map onto, is a
     * genuine loss.
     */
    public function testSignalEmitsPlainTextWithStyleRanges(): void
    {
        $source = "# Title\n\nShipped /today/: *bold*, ~struck~ and `code`.\n";
        $document = CarveConverter::create()->parse($source);

        $result = (new ChatRenderer((new FlavorRegistry())->get('signal')))->renderResult($document);

        self::assertSame("Title\n\nShipped today: bold, struck and code.\n", $result->text);
        self::assertSame(
            ['heading'],
            array_map(static fn ($loss): string => $loss->nodeType, $result->losses),
        );
        // The heading has no syntax in Signal, but bold still carries it.
        self::assertSame(
            ['BOLD', 'ITALIC', 'BOLD', 'STRIKETHROUGH', 'MONOSPACE'],
            array_map(static fn ($range): string => $range->style, $result->ranges),
        );
    }

    /**
     * Code blocks and code spans carry their payload as content rather than
     * children, so unwrapping them by rendering children alone would silently
     * delete the code.
     */
    public function testUnwrappingAContentBearingNodeKeepsItsPayload(): void
    {
        $document = CarveConverter::create()->parse("Run `keep inline`.\n\n~~~\nkeep block\n~~~\n");

        $rendered = (new ChatRenderer((new FlavorRegistry())->get('signal')))->render($document);

        self::assertStringContainsString('keep inline', $rendered);
        self::assertStringContainsString('keep block', $rendered);
    }

    /**
     * Highlight is `{=mark=}` - emphasis. Spoiler is a separate Carve
     * extension meaning concealment. Mapping highlight onto a platform spoiler
     * inverts the author's intent: it hides text that was meant to stand out.
     */
    public function testHighlightIsNotTreatedAsASpoiler(): void
    {
        $document = CarveConverter::create()->parse('A {=mark=} here.');
        $registry = new FlavorRegistry();

        foreach (['discord', 'telegram-html', 'signal', 'whatsapp'] as $id) {
            $rendered = (new ChatRenderer($registry->get($id)))->render($document);
            self::assertStringNotContainsString('||', $rendered, $id);
            self::assertStringNotContainsString('spoiler', $rendered, $id);
            self::assertStringContainsString('{=mark=}', $rendered, $id);
        }
    }

    /**
     * Carve's real spoiler is an extension, addressed by a qualified node key
     * so a flavor maps that one extension rather than every extension.
     */
    public function testSpoilerExtensionMapsToEachTargetsOwnSpoiler(): void
    {
        $converter = CarveConverter::create();
        $converter->addExtension(new SpoilerExtension());
        $document = $converter->parse('A :spoiler[hidden] here.');
        $registry = new FlavorRegistry();

        self::assertSame(
            "A ||hidden|| here.\n",
            (new ChatRenderer($registry->get('discord')))->render($document),
        );
        self::assertSame(
            "A <tg-spoiler>hidden</tg-spoiler> here.\n",
            (new ChatRenderer($registry->get('telegram-html')))->render($document),
        );

        $signal = (new ChatRenderer($registry->get('signal')))->renderResult($document);
        self::assertSame("A hidden here.\n", $signal->text);
        self::assertSame('SPOILER', $signal->ranges[0]->style);
        self::assertSame('hidden', substr($signal->text, $signal->ranges[0]->start, $signal->ranges[0]->length));
    }

    /**
     * A target with no spoiler of its own must still show the text - hiding is
     * the one thing it cannot do, but dropping it would be worse.
     */
    public function testSpoilerFallsBackToPlainTextWhereUnsupported(): void
    {
        $converter = CarveConverter::create();
        $converter->addExtension(new SpoilerExtension());
        $document = $converter->parse('A :spoiler[hidden] here.');

        self::assertSame(
            "A hidden here.\n",
            (new ChatRenderer((new FlavorRegistry())->get('whatsapp')))->render($document),
        );
    }

    private function richDocument(): Document
    {
        $document = new Document();
        $heading = new Heading(1);
        $heading->appendChild(new Text('Title'));
        $document->appendChild($heading);

        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('Hello '));
        $emphasis = new Emphasis();
        $emphasis->appendChild(new Text('em'));
        $paragraph->appendChild($emphasis);
        $paragraph->appendChild(new Text(' '));
        $strong = new Strong();
        $strong->appendChild(new Text('strong'));
        $paragraph->appendChild($strong);
        $paragraph->appendChild(new Text(' '));
        $strike = new Strike();
        $strike->appendChild(new Text('strike'));
        $paragraph->appendChild($strike);
        $paragraph->appendChild(new Text(' '));
        $paragraph->appendChild(new Code('a*b'));
        $paragraph->appendChild(new Text(' '));
        $link = new Link('https://example.com');
        $link->appendChild(new Text('site'));
        $paragraph->appendChild($link);
        $paragraph->appendChild(new Text('.'));
        $document->appendChild($paragraph);

        $list = new ListBlock();
        foreach (['one', 'two'] as $text) {
            $item = new ListItem();
            $itemParagraph = new Paragraph();
            $itemParagraph->appendChild(new Text($text));
            $item->appendChild($itemParagraph);
            $list->appendChild($item);
        }
        $document->appendChild($list);

        $quote = new BlockQuote();
        $quoteParagraph = new Paragraph();
        $quoteParagraph->appendChild(new Text('quoted'));
        $quote->appendChild($quoteParagraph);
        $document->appendChild($quote);

        $imageParagraph = new Paragraph();
        $imageParagraph->appendChild(new Image('https://example.com/logo.png', 'logo'));
        $document->appendChild($imageParagraph);
        $document->appendChild($this->table());

        $footnote = new Footnote('1');
        $footnoteParagraph = new Paragraph();
        $footnoteParagraph->appendChild(new Text('note'));
        $footnote->appendChild($footnoteParagraph);
        $document->appendChild($footnote);

        return $document;
    }

    private function table(): Table
    {
        $table = new Table();
        $header = new TableRow(true);
        $header->appendChild($this->cell('A', true));
        $header->appendChild($this->cell('B', true));
        $table->appendChild($header);
        $row = new TableRow();
        $row->appendChild($this->cell('1'));
        $row->appendChild($this->cell('2'));
        $table->appendChild($row);

        return $table;
    }

    private function cell(string $text, bool $header = false): TableCell
    {
        $cell = new TableCell($header);
        $cell->appendChild(new Text($text));

        return $cell;
    }
}
