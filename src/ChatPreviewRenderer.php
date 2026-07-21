<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\NodeType;
use MarkupCarve\Carve\Renderer\RendererInterface;
use MarkupCarve\Carve\Renderer\TableLayout;

/**
 * Renders what a chat client would actually put on screen, rather than the
 * markup sent to it.
 *
 * This needs no extra per-platform data. Which marks a target supports is
 * already in the flavor table, and the mapping from a supported mark to its
 * visual form is universal - strong is bold everywhere it exists at all.
 *
 * There are two paths, matching the two {@see OutputMode}s:
 *
 * - `ranges` - built from what was actually sent, applying the style offsets
 *   over the plain-text body. Nothing else is interpreted, so a construct the
 *   platform has no syntax for shows as the literal characters in the message.
 *   Re-walking the AST here would invent a quote block or a bullet list the
 *   platform does not have.
 * - `markup` - walks the AST, honoring the flavor's support table for blocks
 *   too. Slack has no list syntax, so its `- ` is a hyphen on screen, not a
 *   bullet.
 *
 * Security: every text value is escaped, and raw nodes are never passed
 * through as markup. The output is meant to be embedded in a page, so nothing
 * derived from the document may reach it unescaped.
 */
final class ChatPreviewRenderer implements RendererInterface
{
    /**
     * @var int
     */
    private const MAX_RENDER_DEPTH = 512;

    /**
     * Supported inline marks and the tag that shows them. Not per-platform:
     * the flavor decides *whether* a mark survives, this decides how it looks.
     *
     * @var array<string, string>
     */
    private const MARK_TAGS = [
        NodeType::STRONG => 'strong',
        NodeType::EMPHASIS => 'em',
        NodeType::STRIKE => 's',
        NodeType::UNDERLINE => 'u',
        NodeType::HIGHLIGHT => 'mark',
        NodeType::INSERT => 'ins',
        NodeType::DELETE => 'del',
    ];

    private int $renderDepth = 0;

    public function __construct(private readonly ChatFlavor $flavor)
    {
    }

    public function render(Document $document): string
    {
        $this->renderDepth = 0;

        // A range-based target sends plain text with style offsets, so its
        // preview is built from what was actually sent rather than from the
        // AST. Anything the platform has no syntax for - a quote, a list, a
        // heading - is then literally those characters on screen, which is
        // what the reader sees. Re-walking the AST here would invent structure
        // the platform does not have.
        if ($this->flavor->output() === OutputMode::Ranges) {
            return $this->renderFromRanges((new ChatRenderer($this->flavor))->renderResult($document));
        }

        return trim($this->renderChildren($document));
    }

    /**
     * Applies style offsets over the plain-text body.
     */
    private function renderFromRanges(ChatResult $result): string
    {
        $text = $result->text;

        $opens = [];
        $closes = [];
        foreach ($result->ranges as $range) {
            $tags = $this->tagsForRange($range);
            if ($tags === null) {
                continue;
            }
            $opens[$this->byteOffset($text, $range->start)][] = $tags[0];
            $closes[$this->byteOffset($text, $range->start + $range->length)][] = $tags[1];
        }

        $out = '';
        $length = strlen($text);
        for ($i = 0; $i <= $length; $i++) {
            // Close what ends here before opening what starts here, innermost
            // span first, so the tags stay balanced when spans nest.
            foreach (array_reverse($closes[$i] ?? []) as $tag) {
                $out .= $tag;
            }
            foreach ($opens[$i] ?? [] as $tag) {
                $out .= $tag;
            }
            if ($i < $length) {
                $out .= $this->escape($text[$i]);
            }
        }

        return '<div class="chat-plain">' . $out . '</div>';
    }

    /**
     * The open and close tags a styled span renders as, or null when the style
     * maps to nothing showable.
     *
     * @return array{0: string, 1: string}|null
     */
    private function tagsForRange(StyleRange $range): ?array
    {
        $nodeType = $this->nodeTypeForStyle($range->style);

        if (isset(self::MARK_TAGS[$nodeType])) {
            $tag = self::MARK_TAGS[$nodeType];

            return ['<' . $tag . '>', '</' . $tag . '>'];
        }

        return match ($nodeType) {
            NodeType::CODE => ['<code>', '</code>'],
            NodeType::CODE_BLOCK => ['<pre><code>', '</code></pre>'],
            NodeType::BLOCKQUOTE => ['<blockquote>', '</blockquote>'],
            NodeType::LINK => $this->linkOpenTag($range),
            default => null,
        };
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function linkOpenTag(StyleRange $range): ?array
    {
        $url = $this->sanitizeUrl($range->data['url'] ?? '');
        if ($url === '') {
            return null;
        }

        return ['<a href="' . $this->escape($url) . '" rel="nofollow noopener" target="_blank">', '</a>'];
    }

    /**
     * Maps a flavor's style name back to the node it came from, so the preview
     * can pick a tag for it.
     */
    private function nodeTypeForStyle(string $style): string
    {
        $candidates = array_merge(
            array_keys(self::MARK_TAGS),
            [NodeType::CODE, NodeType::CODE_BLOCK, NodeType::BLOCKQUOTE, NodeType::LINK],
        );

        foreach ($candidates as $nodeType) {
            if ($this->flavor->styleFor($nodeType) === $style) {
                return $nodeType;
            }
        }

        return '';
    }

    /**
     * Converts an offset in the flavor's unit to a byte offset into the body.
     */
    private function byteOffset(string $text, int $offset): int
    {
        return match ($this->flavor->offsetUnit()) {
            OffsetUnit::Utf8 => $offset,
            OffsetUnit::Codepoints => strlen((string)mb_substr($text, 0, $offset, 'UTF-8')),
            OffsetUnit::Utf16 => strlen((string)mb_convert_encoding(
                substr((string)mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'), 0, $offset * 2),
                'UTF-8',
                'UTF-16LE',
            )),
        };
    }

    private function renderChildren(Node $node): string
    {
        $out = '';
        foreach ($node->getChildren() as $child) {
            $out .= $this->renderNode($child);
        }

        return $out;
    }

    private function renderNode(Node $node): string
    {
        if ($this->renderDepth >= self::MAX_RENDER_DEPTH) {
            return '';
        }

        $this->renderDepth++;
        try {
            return $this->renderNodeInner($node);
        } finally {
            $this->renderDepth--;
        }
    }

    private function renderNodeInner(Node $node): string
    {
        $type = $node->getType();

        if (isset(self::MARK_TAGS[$type])) {
            $inner = $this->renderChildren($node);
            if (!$this->flavor->supports($type)) {
                return $inner;
            }
            $tag = self::MARK_TAGS[$type];

            return '<' . $tag . '>' . $inner . '</' . $tag . '>';
        }

        return match (true) {
            $node instanceof Text, $node instanceof EscapedText => $this->escape($node->getContent()),
            $node instanceof Paragraph => '<p>' . $this->renderChildren($node) . '</p>',
            $node instanceof Heading => $this->renderHeading($node),
            $node instanceof BlockQuote => $this->renderBlockQuote($node),
            $node instanceof ListBlock => $this->renderList($node),
            $node instanceof ListItem => '<li>' . $this->itemContent($node) . '</li>',
            $node instanceof CodeBlock => '<pre><code>' . $this->escape($node->getContent()) . '</code></pre>',
            $node instanceof Code => $this->renderCode($node),
            $node instanceof Table => $this->renderTable($node),
            $node instanceof Link => $this->renderLink($node),
            $node instanceof Image => $this->renderImage($node),
            $node instanceof SoftBreak, $node instanceof HardBreak => '<br>',
            default => $this->renderChildren($node),
        };
    }

    /**
     * A target without heading syntax still shows the text, bolded where the
     * flavor degrades it that way.
     */
    private function renderHeading(Heading $node): string
    {
        $inner = $this->renderChildren($node);
        if (!$this->flavor->supports(NodeType::HEADING)) {
            $emphasized = str_contains((string)($this->flavor->emission(NodeType::HEADING)['template'] ?? ''), '*');

            return '<p>' . ($emphasized ? '<strong>' . $inner . '</strong>' : $inner) . '</p>';
        }

        $level = min(max($node->getLevel(), 1), 6);

        return '<h' . $level . '>' . $inner . '</h' . $level . '>';
    }

    private function renderCode(Code $node): string
    {
        $inner = $this->escape($node->getContent());

        return $this->flavor->supports(NodeType::CODE) ? '<code>' . $inner . '</code>' : $inner;
    }

    /**
     * Slack has no list syntax at all, so its `- ` is literally a hyphen on
     * screen. Showing a real bullet list there would claim a feature the
     * platform does not have.
     */
    private function renderList(ListBlock $node): string
    {
        $ordered = $node->getListType() === ListBlock::TYPE_ORDERED;

        if (!$this->flavor->supports(NodeType::LIST_BLOCK)) {
            $lines = '';
            $number = 1;
            foreach ($node->getChildren() as $item) {
                $prefix = $ordered ? $number++ . '. ' : '- ';
                $lines .= '<div>' . $this->escape($prefix) . $this->itemContent($item) . '</div>';
            }

            return $lines;
        }

        $tag = $ordered ? 'ol' : 'ul';

        return '<' . $tag . '>' . $this->renderChildren($node) . '</' . $tag . '>';
    }

    /**
     * List item text without its paragraph wrapper, so a literal `- one` stays
     * one contiguous run rather than being split by a block element.
     */
    private function itemContent(Node $node): string
    {
        $out = '';
        foreach ($node->getChildren() as $child) {
            $out .= $child instanceof Paragraph ? $this->renderChildren($child) : $this->renderNode($child);
        }

        return $out;
    }

    /**
     * Same reasoning as lists: a target without quote rendering shows the
     * `> ` prefix as ordinary characters.
     */
    private function renderBlockQuote(BlockQuote $node): string
    {
        if ($this->flavor->supports(NodeType::BLOCKQUOTE)) {
            return '<blockquote>' . $this->renderChildren($node) . '</blockquote>';
        }

        $out = '';
        foreach ($node->getChildren() as $child) {
            $out .= '<div>&gt; ' . $this->renderChildren($child) . '</div>';
        }

        return $out;
    }

    /**
     * Every target flattens tables, so the preview shows the monospace block a
     * reader would actually see.
     */
    private function renderTable(Table $node): string
    {
        $layout = TableLayout::expand($node, fn (TableCell $cell): string => trim($this->plainText($cell)));

        $rows = [];
        $widths = [];
        foreach ($layout['rows'] as $row) {
            $cells = [];
            foreach ($row['cells'] as $index => $cell) {
                $cells[$index] = is_string($cell) ? $cell : '';
                $widths[$index] = max($widths[$index] ?? 0, strlen($cells[$index]));
            }
            $rows[] = $cells;
        }

        $lines = [];
        foreach ($rows as $row) {
            $padded = [];
            foreach ($row as $index => $cell) {
                $padded[] = str_pad($cell, $widths[$index] ?? 0);
            }
            $lines[] = rtrim(implode('  ', $padded));
        }

        return '<pre><code>' . $this->escape(implode("\n", $lines)) . '</code></pre>';
    }

    /**
     * Cell text without markup - the flattened block is monospace, so marks
     * inside it have nothing to render as.
     */
    private function plainText(Node $node): string
    {
        if ($node instanceof Text || $node instanceof EscapedText || $node instanceof Code) {
            return $node->getContent();
        }

        $out = '';
        foreach ($node->getChildren() as $child) {
            $out .= $this->plainText($child);
        }

        return $out;
    }

    /**
     * With no link syntax the URL is inlined, which is what the reader sees;
     * chat clients autolink the bare URL, so the preview does too.
     */
    private function renderLink(Link $node): string
    {
        $inner = $this->renderChildren($node);
        $url = (string)$node->getDestination();
        $safeUrl = $this->sanitizeUrl($url);
        $inlined = $this->flavor->linkStyle() === LinkStyle::None;

        // A scheme we refuse to link becomes inert text rather than an anchor
        // with an empty href, which would still look clickable.
        if ($safeUrl === '') {
            return $inlined ? $inner . ' (' . $this->escape($url) . ')' : $inner;
        }

        $anchor = '<a href="' . $this->escape($safeUrl) . '" rel="nofollow noopener" target="_blank">';

        return $inlined
            ? $inner . ' (' . $anchor . $this->escape($url) . '</a>)'
            : $anchor . $inner . '</a>';
    }

    private function renderImage(Image $node): string
    {
        $url = $node->getSource();

        return $this->escape($node->getAlt()) . ' (' . $this->escape($url) . ')';
    }

    /**
     * Only http(s) and mailto reach an href; anything else (javascript:, data:)
     * is neutralized rather than linked.
     */
    private function sanitizeUrl(string $url): string
    {
        $trimmed = trim($url);
        if (preg_match('#^(https?://|mailto:|/|\#)#i', $trimmed) === 1) {
            return $trimmed;
        }

        return '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
