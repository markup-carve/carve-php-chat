<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Tabs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Chat\ChatPreviewRenderer;
use MarkupCarve\Chat\ChatRenderer;
use MarkupCarve\Chat\FlavorRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChatRendererTest extends TestCase
{
    public function testRendererDepthContractIsPublic(): void
    {
        self::assertSame(512, ChatRenderer::MAX_RENDER_DEPTH);
        self::assertSame(512, ChatPreviewRenderer::MAX_RENDER_DEPTH);
    }

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
        $document = Carve::parse('[a\\]b](https://x.com)');

        $rendered = (new ChatRenderer((new FlavorRegistry())->get('discord-bot')))->render($document);

        self::assertSame('[a\\]b](https://x.com)' . "\n", $rendered);
    }

    /**
     * A table falling back to a code block must use the flavor's own
     * delimiters, not a hard-coded Markdown fence.
     */
    public function testTableFallbackUsesFlavorCodeDelimiters(): void
    {
        $source = "|= A |= B |\n| 1 | 2 |\n";
        $document = Carve::parse($source);
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
     * A `+` continuation row joins its cell with one space, not by gluing words.
     */
    public function testContinuationRowJoinsTheCellWithASpace(): void
    {
        $source = "|= A |= B |\n| 1 | 2 |\n+ x | y |\n";
        $document = Carve::parse($source);

        self::assertSame(
            "```\nA    B\n1 x  2 y\n```\n",
            (new ChatRenderer((new FlavorRegistry())->get('discord')))->render($document),
        );
    }

    /**
     * Slack mention syntax needs a real user ID. A Carve mention carries only
     * the literal label, so it is emitted as written rather than wrapped into
     * an ID that does not exist.
     */
    public function testMentionsAreNotWrappedIntoSlackIds(): void
    {
        $document = Carve::parse('Ping @alice here.');

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
        $source = "# Title\n\nShipped /today/: *bold*, {~struck~} and `code`.\n"; // U-066
        $document = Carve::parse($source);

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
        $document = Carve::parse("Run `keep inline`.\n\n~~~\nkeep block\n~~~\n");

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
        $document = Carve::parse('A {=mark=} here.');
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
        $document = Carve::parse('A :spoiler[hidden] here.');
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
        $document = Carve::parse('A :spoiler[hidden] here.');

        self::assertSame(
            "A hidden here.\n",
            (new ChatRenderer((new FlavorRegistry())->get('whatsapp')))->render($document),
        );
    }

    /**
     * A citation keeps its data in node properties, so unwrapping it by
     * rendering children dropped the citation from the message entirely.
     */
    public function testCitationSurvivesInsteadOfVanishing(): void
    {
        $document = Carve::parse('See [@smith2020, p. 5].');

        $rendered = (new ChatRenderer((new FlavorRegistry())->get('whatsapp')))->render($document);

        self::assertSame("See [@smith2020, p. 5].\n", $rendered);
    }

    /**
     * Chat has no boxes to draw, so the label is the only thing separating a
     * warning from an ordinary paragraph.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function labelledDivProvider(): array
    {
        return [
            'admonition kind' => ["::: warning\nBe careful.\n:::\n", "Warning:\nBe careful.\n"],
            'details title' => ["::: details \"More info\"\nHidden body.\n:::\n", "More info:\nHidden body.\n"],
            'unlabelled div stays bare' => ["::: myclass\nJust prose.\n:::\n", "Just prose.\n"],
        ];
    }

    #[DataProvider('labelledDivProvider')]
    public function testLabelledDivKeepsItsLabel(string $source, string $expected): void
    {
        $rendered = (new ChatRenderer((new FlavorRegistry())->get('whatsapp')))->render(Carve::parse($source));

        self::assertSame($expected, $rendered);
    }

    /**
     * A tab panel names itself with a `label` attribute rather than a title,
     * and without it the panels run together as undifferentiated prose.
     */
    public function testTabLabelSurvives(): void
    {
        $source = ":::: tabs\n::: tab \"First Tab\"\nFirst body.\n:::\n::: tab \"Second Tab\"\nSecond body.\n:::\n::::\n";

        $rendered = (new ChatRenderer((new FlavorRegistry())->get('whatsapp')))->render(Carve::parse($source, ['extensions' => Tabs::extensions()]));

        self::assertSame("First Tab:\nFirst body.\n\nSecond Tab:\nSecond body.\n", $rendered);
    }

    /**
     * Substitutions and caption numbers keep their content in properties. The
     * renderer had correct branches for both, but they were unreachable while
     * no flavor declared the node, so the fallback path silently emptied them.
     */
    public function testPropertyHeldInlineNodesAreNotEmptied(): void
    {
        // U-069: 0.2 represents a substitution as adjacent delete and insert nodes.
        $document = new Node('document', [
            new Node('paragraph', [
                Node::text('A ', 0, 2),
                new Node('delete', [Node::text('old', 2, 5)]),
                new Node('insert', [Node::text('new', 5, 8)]),
                Node::text(' B ', 8, 11),
                new Node('caption_number'),
            ]),
        ]);

        $rendered = (new ChatRenderer((new FlavorRegistry())->get('whatsapp')))->render($document);

        // The struck old text and its replacement, separated - bare "oldnew"
        // was the mangling carve-php-chat#1 reported.
        self::assertStringContainsString('~old~ new', $rendered);
        self::assertStringContainsString('#', $rendered);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function substitutionHalfProvider(): array
    {
        return [
            'whatsapp' => ['whatsapp', "A ~_old_~ *new* run.\n"],
            'slack' => ['slack', "A ~_old_~ *new* run.\n"],
            'discord' => ['discord', "A ~~*old*~~ **new** run.\n"],
            'telegram-html' => ['telegram-html', "A <s><i>old</i></s> <b>new</b> run.\n"],
        ];
    }

    /**
     * From SOURCE, so the halves are built by the engine rather than by hand.
     * Both carry inline nodes; reading them as flat text dropped the emphasis
     * and the strong on every flavor, leaving a bare `~old~ new`.
     */
    #[DataProvider('substitutionHalfProvider')]
    public function testSubstitutionHalvesKeepTheirInlineMarkup(string $flavor, string $expected): void
    {
        $document = Carve::parse("A {-/old/-}{+*new*+} run.\n");

        $rendered = (new ChatRenderer((new FlavorRegistry())->get($flavor)))->render($document);

        self::assertSame($expected, $rendered);
    }

    /**
     * The struck half is assembled from clones. Appending the caller's own
     * nodes to the synthetic Strike would repoint their parent at it, which
     * the rendered string cannot show - so assert the link itself.
     */
    public function testRenderingASubstitutionDoesNotReparentTheCallersNodes(): void
    {
        // U-069: the old halves are sibling delete and insert nodes in 0.2.
        $document = Carve::parse("A {-/old/-}{+*new*+} run.\n");
        $paragraph = $document->children[0] ?? self::fail('missing paragraph');
        $deleted = $paragraph->children[1] ?? self::fail('missing delete');
        $child = $deleted->children[0] ?? self::fail('missing deleted child');

        (new ChatRenderer((new FlavorRegistry())->get('whatsapp')))->render($document);

        self::assertSame($child, $deleted->children[0]);
    }

    /**
     * An escaped space arrives as a `non_breaking_space` node of its own
     * (carve-php 0.1.10), not as text holding a space. With no arm for it the
     * renderer fell through to its children, of which it has none, so
     * `a\ b` rendered as `ab`.
     */
    #[DataProvider('nonBreakingSpaceFlavorProvider')]
    public function testAnEscapedSpaceSurvivesAsANonBreakingSpace(string $flavor): void
    {
        $document = Carve::parse("a\\ b\n");

        $rendered = (new ChatRenderer((new FlavorRegistry())->get($flavor)))->render($document);

        self::assertSame("a\u{00A0}b\n", $rendered);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonBreakingSpaceFlavorProvider(): array
    {
        $cases = [];
        foreach ((new FlavorRegistry())->ids() as $id) {
            $cases[$id] = [$id];
        }

        return $cases;
    }

    private function richDocument(): Node
    {
        // U-055 and U-066: native table headers and braced strike.
        return Carve::parse("# Title\n\nHello /em/ *strong* {~strike~} `a*b` [site](https://example.com).\n\n- one\n- two\n\n> quoted\n\n![logo](https://example.com/logo.png)\n\n|= A |= B |\n| 1 | 2 |\n\n[^1]: note\n");
    }

    /**
     * A destination that resolves to nothing used to be written out as a link
     * anyway, so a client got `<|text>`, `<a href="">text</a>` or `[text]()`
     * instead of the label. Six of the seven flavors were affected; only
     * telegram-entities already wrote the label alone.
     */
    public function testAnUnresolvedReferenceLinkKeepsOnlyItsLabel(): void
    {
        $document = Carve::parse("[text][missing]\n");
        $registry = new FlavorRegistry();

        foreach ($registry->ids() as $id) {
            self::assertSame(
                "text\n",
                (new ChatRenderer($registry->get($id)))->render($document),
                $id . ' writes a link with no destination',
            );
        }
    }

    /**
     * carve-php 0.1.13 parses `[text]()` and `![alt]()` as a link and an image with
     * an empty destination; before, they stayed literal text.
     */
    public function testAnEmptyDestinationKeepsOnlyTheLabel(): void
    {
        $document = Carve::parse("See [text]() and ![alt]() end.\n");
        $registry = new FlavorRegistry();

        foreach ($registry->ids() as $id) {
            self::assertSame(
                "See text and alt end.\n",
                (new ChatRenderer($registry->get($id)))->render($document),
                $id . ' writes a link or image with no destination',
            );
        }
    }

    /**
     * carve-php 0.1.13 reads a `{table}` CSV fence as a table, so it degrades the
     * way a pipe table does rather than as a code block.
     */
    public function testACsvTableFenceRendersLikeAPipeTable(): void
    {
        $registry = new FlavorRegistry();
        $csv = Carve::parse("{table}\n``` csv\na,b\n1,2\n```\n");
        $pipe = Carve::parse("|= a |= b |\n| 1 | 2 |\n");

        foreach ($registry->ids() as $id) {
            self::assertSame(
                (new ChatRenderer($registry->get($id)))->render($pipe),
                (new ChatRenderer($registry->get($id)))->render($csv),
                $id,
            );
        }
    }

    /**
     * carve-php 0.1.11 stopped resolving a collapsed reference image against a
     * heading id, so the image reaches the renderer with no source and the
     * `{alt} ({url})` template left a dangling pair of parentheses.
     */
    public function testACollapsedReferenceImageWithNoSourceKeepsOnlyItsAlt(): void
    {
        $document = Carve::parse("{#pic}\n# Pic\n\n![Pic][]\n");
        $registry = new FlavorRegistry();

        foreach ($registry->ids() as $id) {
            self::assertStringEndsWith(
                "Pic\n",
                (new ChatRenderer($registry->get($id)))->render($document),
                $id . ' writes an image with no source',
            );
            self::assertStringNotContainsString(
                '()',
                (new ChatRenderer($registry->get($id)))->render($document),
                $id . ' leaves an empty parenthesis behind',
            );
        }
    }
}
