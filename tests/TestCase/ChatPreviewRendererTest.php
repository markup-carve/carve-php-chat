<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Chat\ChatPreviewRenderer;
use MarkupCarve\Chat\FlavorRegistry;
use PHPUnit\Framework\TestCase;

final class ChatPreviewRendererTest extends TestCase
{
    /**
     * The same mark is styled where the target supports it and bare where it
     * does not - which is the whole point of showing a preview per flavor.
     */
    public function testMarksAreStyledOnlyWhereTheFlavorSupportsThem(): void
    {
        $document = CarveConverter::create()->parse('Shipped _under_ and *bold*.');
        $registry = new FlavorRegistry();

        // Discord has underline; WhatsApp has no underline at all.
        self::assertSame(
            '<p>Shipped <u>under</u> and <strong>bold</strong>.</p>',
            (new ChatPreviewRenderer($registry->get('discord')))->render($document),
        );
        self::assertSame(
            '<p>Shipped under and <strong>bold</strong>.</p>',
            (new ChatPreviewRenderer($registry->get('whatsapp')))->render($document),
        );
    }

    /**
     * A range-based target still shows the styling on screen - it just sends it
     * as offsets rather than delimiters - so its preview is styled too.
     */
    public function testRangeBasedFlavorPreviewIsStyled(): void
    {
        $document = CarveConverter::create()->parse('Shipped *bold*.');

        $rendered = (new ChatPreviewRenderer((new FlavorRegistry())->get('signal')))->render($document);

        self::assertStringContainsString('Shipped <strong>bold</strong>.', $rendered);
    }

    /**
     * A range-based preview is built from what was actually sent, so it cannot
     * invent structure the platform has no syntax for. Signal renders no quote
     * block and no bullet list - those are literally these characters.
     */
    public function testRangeBasedPreviewInventsNoBlockStructure(): void
    {
        $document = CarveConverter::create()->parse("> quoted\n\n- one\n- two\n");

        $rendered = (new ChatPreviewRenderer((new FlavorRegistry())->get('signal')))->render($document);

        self::assertStringNotContainsString('<blockquote', $rendered);
        self::assertStringNotContainsString('<ul', $rendered);
        self::assertStringContainsString('&gt; quoted', $rendered);
        self::assertStringContainsString('- one', $rendered);
    }

    /**
     * Slack has no list syntax, so a bullet list would be a feature the
     * platform does not have.
     */
    public function testMarkupFlavorWithoutListSyntaxShowsLiteralPrefixes(): void
    {
        $document = CarveConverter::create()->parse("- one\n- two\n");
        $registry = new FlavorRegistry();

        $slack = (new ChatPreviewRenderer($registry->get('slack')))->render($document);
        $discord = (new ChatPreviewRenderer($registry->get('discord')))->render($document);

        self::assertStringNotContainsString('<ul', $slack);
        self::assertStringContainsString('- one', $slack);
        self::assertStringContainsString('<ul', $discord);
    }

    /**
     * A payload-carrying range renders as the thing it represents: a real
     * anchor, a quote block, a code block.
     */
    public function testPayloadRangesRenderAsTheirElements(): void
    {
        $source = "[docs](https://e.com/a)\n\n> quoted\n\n``` php\necho 1;\n```\n";
        $document = CarveConverter::create()->parse($source);

        $rendered = (new ChatPreviewRenderer((new FlavorRegistry())->get('telegram-entities')))->render($document);

        self::assertStringContainsString('<a href="https://e.com/a"', $rendered);
        self::assertStringContainsString('>docs</a>', $rendered);
        self::assertStringContainsString('<blockquote>quoted</blockquote>', $rendered);
        self::assertStringContainsString('<pre><code>echo 1;</code></pre>', $rendered);
    }

    /**
     * Nested spans must stay balanced, so the outer one opens first and the
     * inner one closes first.
     */
    public function testNestedRangesNestWellFormed(): void
    {
        $document = CarveConverter::create()->parse('*/nested/ tail*');

        $rendered = (new ChatPreviewRenderer((new FlavorRegistry())->get('signal')))->render($document);

        self::assertStringContainsString('<strong><em>nested</em> tail</strong>', $rendered);
    }

    /**
     * A URL we refuse to link must not become an anchor even when it arrives
     * through a range payload.
     */
    public function testUnsafeUrlInARangePayloadIsNotLinked(): void
    {
        $document = CarveConverter::create()->parse('[click](javascript:alert(1))');

        $rendered = (new ChatPreviewRenderer((new FlavorRegistry())->get('telegram-entities')))->render($document);

        self::assertStringNotContainsString('<a ', $rendered);
        self::assertStringNotContainsString('javascript:', $rendered);
    }

    /**
     * A target with no link syntax shows the inlined URL, which chat clients
     * autolink; one with link syntax shows only the label.
     */
    public function testLinkPreviewFollowsTheFlavorLinkStyle(): void
    {
        $document = CarveConverter::create()->parse('See [docs](https://example.com).');
        $registry = new FlavorRegistry();

        self::assertStringContainsString(
            'docs (<a href="https://example.com"',
            (new ChatPreviewRenderer($registry->get('whatsapp')))->render($document),
        );
        self::assertStringContainsString(
            '>docs</a>',
            (new ChatPreviewRenderer($registry->get('discord-bot')))->render($document),
        );
    }

    /**
     * The preview is embedded in a page, so nothing from the document may reach
     * it as live markup.
     */
    public function testPreviewEscapesDocumentContent(): void
    {
        $document = CarveConverter::create()->parse('A <script>alert(1)</script> B');

        $rendered = (new ChatPreviewRenderer((new FlavorRegistry())->get('signal')))->render($document);

        self::assertStringNotContainsString('<script>', $rendered);
        self::assertStringContainsString('&lt;script&gt;', $rendered);
    }

    /**
     * A scheme we refuse to link must not produce an anchor at all - an empty
     * href would still read as clickable.
     */
    public function testUnsafeUrlIsNotLinked(): void
    {
        $document = CarveConverter::create()->parse('[click](javascript:alert(1))');

        $rendered = (new ChatPreviewRenderer((new FlavorRegistry())->get('discord-bot')))->render($document);

        self::assertStringNotContainsString('<a ', $rendered);
        self::assertStringNotContainsString('javascript:', $rendered);
    }

    /**
     * Rendering a preview must not reparent nodes out of the caller's document.
     */
    public function testPreviewDoesNotMutateTheDocument(): void
    {
        $document = CarveConverter::create()->parse("| A | B |\n|---|---|\n| 1 | 2 |\n");
        $before = count($document->getChildren());

        (new ChatPreviewRenderer((new FlavorRegistry())->get('slack')))->render($document);

        self::assertSame($before, count($document->getChildren()));
        self::assertNotSame('', (new ChatPreviewRenderer((new FlavorRegistry())->get('slack')))->render($document));
    }
}
