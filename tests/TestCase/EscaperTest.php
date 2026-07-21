<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Chat\ChatRenderer;
use MarkupCarve\Chat\EscapeMechanism;
use MarkupCarve\Chat\Escaper;
use MarkupCarve\Chat\FlavorRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EscaperTest extends TestCase
{
    /**
     * @return array<string, array{0: \MarkupCarve\Chat\EscapeMechanism, 1: string, 2: string, 3: string}>
     */
    public static function escapeProvider(): array
    {
        return [
            'backslash' => [EscapeMechanism::Backslash, '*_~', '*a_~', '\*a\_\~'],
            'entities' => [EscapeMechanism::Entities, '&<>', 'a & <b> >', 'a &amp; &lt;b&gt; &gt;'],
            'none' => [EscapeMechanism::None, '', '* & <', '* & <'],
        ];
    }

    #[DataProvider('escapeProvider')]
    public function testEscape(EscapeMechanism $mechanism, string $chars, string $input, string $expected): void
    {
        self::assertSame($expected, (new Escaper($mechanism, $chars))->escape($input));
    }

    public function testDoesNotEscapeInsideCodeSpans(): void
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('Use '));
        $paragraph->appendChild(new Code('*raw_'));
        $paragraph->appendChild(new Text(' now'));
        $document->appendChild($paragraph);

        $renderer = new ChatRenderer((new FlavorRegistry())->get('whatsapp'));

        self::assertSame('Use `*raw_` now' . "\n", $renderer->render($document));
    }

    /**
     * An HTML-mode target wraps code in real tags, so the payload still needs
     * entity escaping or the message is invalid.
     */
    public function testEntityFlavorEscapesCodeContents(): void
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Code('1 < 2 && x'));
        $document->appendChild($paragraph);

        $renderer = new ChatRenderer((new FlavorRegistry())->get('telegram-html'));

        self::assertSame('<code>1 &lt; 2 &amp;&amp; x</code>' . "\n", $renderer->render($document));
    }

    /**
     * A backtick inside the payload would close the span early, so the fence
     * has to widen. HTML-mode targets are unaffected: entity escaping already
     * neutralizes the closing sequence there.
     */
    public function testBacktickFlavorWidensFenceAroundCodeContainingBackticks(): void
    {
        $registry = new FlavorRegistry();
        $inline = CarveConverter::create()->parse('Use ``a`b`` now.');
        $block = CarveConverter::create()->parse("~~~~\nhas ``` inside\n~~~~\n");

        self::assertSame(
            'Use ``a`b`` now.' . "\n",
            (new ChatRenderer($registry->get('discord')))->render($inline),
        );
        self::assertSame(
            "````\nhas ``` inside\n````\n",
            (new ChatRenderer($registry->get('discord')))->render($block),
        );
        self::assertSame(
            'Use <code>a`b</code> now.' . "\n",
            (new ChatRenderer($registry->get('telegram-html')))->render($inline),
        );
    }

    /**
     * A backslash inside a URL changes the address, so a URL emitted as plain
     * text keeps its markup characters.
     */
    public function testFallbackUrlIsNotMarkupEscaped(): void
    {
        $document = CarveConverter::create()->parse('[x](https://example.com/a_b~c)');

        $rendered = (new ChatRenderer((new FlavorRegistry())->get('whatsapp')))->render($document);

        self::assertSame('x (https://example.com/a_b~c)' . "\n", $rendered);
    }

    /**
     * Table cells end up inside a code fence, where a backslash would be shown
     * literally - but an HTML-mode target still needs its entities.
     */
    public function testTableCellsAreNotMarkupEscapedInsideCodeFence(): void
    {
        $registry = new FlavorRegistry();
        $document = CarveConverter::create()->parse("| A_B |\n|---|\n| c*d |\n");

        self::assertSame(
            "```\nA_B\nc*d\n```\n",
            (new ChatRenderer($registry->get('whatsapp')))->render($document),
        );
        self::assertSame(
            "<pre>\nA&amp;B\n</pre>\n",
            (new ChatRenderer($registry->get('telegram-html')))->render(
                CarveConverter::create()->parse("| A&B |\n|---|\n"),
            ),
        );
    }

    /**
     * Delimiter escaping must not leak into code, where a backslash would be
     * shown literally.
     */
    public function testBackslashFlavorLeavesCodeContentsAlone(): void
    {
        self::assertSame('1 < 2 && x', (new Escaper(EscapeMechanism::Backslash, '*_~'))->escapeVerbatim('1 < 2 && x'));
        self::assertSame('a &lt; b', (new Escaper(EscapeMechanism::Entities))->escapeVerbatim('a < b'));
    }
}
