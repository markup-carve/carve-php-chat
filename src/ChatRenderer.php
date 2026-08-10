<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

use Closure;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Inline\CitationGroup;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\Delete;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Highlight;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Inline\InlineFootnote;
use MarkupCarve\Carve\Node\Inline\Insert;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Inline\RawText;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Strike;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Subscript;
use MarkupCarve\Carve\Node\Inline\Substitution;
use MarkupCarve\Carve\Node\Inline\Superscript;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\Underline;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\NodeType;
use MarkupCarve\Carve\Renderer\RendererInterface;
use MarkupCarve\Carve\Renderer\TableLayout;
use MarkupCarve\Carve\Util\StringUtil;

final class ChatRenderer implements RendererInterface
{
    /**
     * @var int
     */
    public const MAX_RENDER_DEPTH = 512;

    private int $renderDepth = 0;

    /**
     * @var array<\MarkupCarve\Chat\Loss>
     */
    private array $losses = [];

    /**
     * Appendix entries keyed by their printed number, so a footnote reference
     * and its appendix entry always show the same number.
     *
     * @var array<int, string>
     */
    private array $appendix = [];

    /**
     * Footnote label to appendix number. A reference is rendered before its
     * definition, so the number is assigned on first sight of either.
     *
     * @var array<string, int>
     */
    private array $footnoteSlots = [];

    private int $appendixCounter = 0;

    private int $listDepth = 0;

    /**
     * While true, text nodes skip markup escaping. Set for content headed into
     * a code fence, where a backslash would be shown literally.
     */
    private bool $verbatim = false;

    private bool $rangeMode = false;

    /**
     * Style name per marker id, resolved when the markers are extracted.
     *
     * @var array<int, string>
     */
    private array $styleNames = [];

    /**
     * Extra fields per marker id, for styles that need more than a name.
     *
     * @var array<int, array<string, string>>
     */
    private array $styleData = [];

    private int $markerId = 0;

    public function __construct(private readonly ChatFlavor $flavor)
    {
    }

    public function render(Document $document): string
    {
        return $this->renderResult($document)->text;
    }

    public function renderResult(Document $document): ChatResult
    {
        $this->losses = [];
        $this->appendix = [];
        $this->footnoteSlots = [];
        $this->appendixCounter = 0;
        $this->renderDepth = 0;
        $this->listDepth = 0;
        $this->styleNames = [];
        $this->styleData = [];
        $this->markerId = 0;
        $this->rangeMode = $this->flavor->output() === OutputMode::Ranges;

        $text = $this->renderNode($document);
        $appendix = $this->appendix;
        if ($appendix !== []) {
            ksort($appendix);
            $lines = [];
            foreach ($appendix as $number => $content) {
                $lines[] = '[' . $number . '] ' . $content;
            }
            $text = rtrim($text) . "\n\n" . implode("\n", $lines) . "\n";
        }

        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text) . "\n";
        $text = str_replace("\u{E000}", ' ', $text);

        $ranges = [];
        if ($this->rangeMode) {
            [$text, $ranges] = $this->extractRanges($text);
        }

        $limit = $this->flavor->messageLimit();
        if ($limit !== null && strlen($text) > $limit) {
            $this->losses[] = new Loss(
                nodeType: 'document',
                sourceLine: null,
                fallback: Fallback::Unwrap,
                reason: sprintf('Rendered message length %d exceeds limit %d.', strlen($text), $limit),
            );
        }

        return new ChatResult($text, $this->losses, $ranges);
    }

    /**
     * Mutates $losses, $appendix and $footnoteSlots as it walks.
     *
     * @phpstan-impure
     */
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
        if ($node instanceof Document) {
            return $this->renderChildren($node);
        }

        if ($node instanceof InlineExtension) {
            return $this->renderInlineExtension($node);
        }

        if (!$this->flavor->supports($node->getType())) {
            return $this->renderFallback($node);
        }

        return match (true) {
            $node instanceof Paragraph => $this->renderChildren($node) . "\n\n",
            $node instanceof Heading => $this->renderHeading($node),
            $node instanceof CodeBlock => $this->renderCodeBlock($node),
            $node instanceof Comment => '',
            $node instanceof RawBlock => $this->stripControls($node->getContent()) . "\n\n",
            $node instanceof BlockQuote => $this->renderBlockQuote($node),
            $node instanceof ListBlock => $this->renderList($node, false),
            $node instanceof ListItem => $this->renderChildren($node),
            $node instanceof DefinitionList => $this->renderChildren($node) . "\n",
            $node instanceof DefinitionTerm => $this->renderChildren($node) . "\n",
            $node instanceof DefinitionDescription => '  ' . trim($this->renderChildren($node)) . "\n",
            $node instanceof ThematicBreak => "---\n\n",
            $node instanceof Div => $this->renderDiv($node),
            $node instanceof Table => $this->renderTableCodeBlock($node),
            $node instanceof TableRow => $this->renderChildren($node),
            $node instanceof TableCell => $this->renderChildren($node),
            $node instanceof LineBlock => $this->renderLineBlock($node),
            $node instanceof Footnote => $this->renderFootnote($node),
            $node instanceof Figure => $this->renderChildren($node),
            $node instanceof Caption => trim($this->renderChildren($node)) . "\n\n",
            $node instanceof Text => $this->escapeText($node->getContent()),
            $node instanceof EscapedText => $this->escapeText($node->getContent()),
            $node instanceof Code => $this->renderCode($node),
            $node instanceof Math => $this->renderCodeLike($node->getContent(), $node->getType()),
            $node instanceof Image => $this->renderImageNative($node),
            $node instanceof Mention => $this->renderMention($node),
            $node instanceof Link => $this->renderLink($node),
            $node instanceof HardBreak => "\n",
            $node instanceof SoftBreak => "\n",
            $node instanceof Strong,
            $node instanceof Emphasis,
            $node instanceof Underline,
            $node instanceof Strike,
            $node instanceof Highlight,
            $node instanceof Insert,
            $node instanceof Delete,
            $node instanceof Superscript,
            $node instanceof Subscript,
            $node instanceof Span => $this->renderDelimited($node),
            $node instanceof Substitution => $this->flavor->escaper()->escape($this->stripControls($node->getOldText() . $node->getNewText())),
            $node instanceof Symbol => ':' . $this->flavor->escaper()->escape($this->stripControls($node->getName())) . ':',
            $node instanceof InlineFootnote => $this->renderDelimited($node),
            $node instanceof FootnoteRef => '[' . $this->footnoteSlot($node->getLabel()) . ']',
            $node instanceof HeadingRef => '</#' . $this->flavor->escaper()->escape($this->stripControls($node->getTargetId())) . '>',
            $node instanceof CaptionNumber => $node->getNumber() === null ? '#' : (string)$node->getNumber(),
            $node instanceof RawInline => $this->flavor->escaper()->escape($this->stripControls($node->getContent())),
            $node instanceof RawText => $this->flavor->escaper()->escape($this->stripControls($node->getContent())),
            default => $this->renderChildren($node),
        };
    }

    private function renderFallback(Node $node): string
    {
        $type = $node->getType();
        $fallback = $this->flavor->fallbackEnum($type);
        $this->recordLoss($node, $fallback, sprintf('Node "%s" is not native in %s.', $type, $this->flavor->id()));

        $rendered = match ($fallback) {
            Fallback::Unwrap => $this->unwrapFallback($node),
            Fallback::Inline => $this->renderInlineBlockAwareFallback($node),
            Fallback::CodeBlock => $node instanceof Table ? $this->renderTableCodeBlock($node) : $this->renderCodeBlockFallback($node),
            Fallback::Appendix => $this->appendixFallback($node),
            Fallback::Carve => $this->carveFallback($node),
            Fallback::Drop => '',
        };

        // A node the target cannot represent natively may still declare a
        // style, on a range-based target. A heading has no syntax in Signal but
        // can still be bold, which beats degrading it to unremarkable text.
        if ($this->rangeMode && $rendered !== '') {
            return $this->markStyled($type, rtrim($rendered, "\n")) . substr($rendered, strlen(rtrim($rendered, "\n")));
        }

        return $rendered;
    }

    /**
     * Admonition kinds, from the extension's own default set. A `::: warning`
     * that renders as bare prose has lost the one thing it was marking.
     *
     * @var array<string>
     */
    private const LABELLED_DIV_CLASSES = ['note', 'tip', 'warning', 'danger', 'info', 'success', 'caution', 'important'];

    /**
     * A div whose class names it, or which carries a title, keeps that label as
     * a leading line. Chat has no boxes to draw, so the label is the only thing
     * distinguishing a warning from a paragraph.
     */
    private function renderDiv(Div $node): string
    {
        $body = $this->renderChildren($node);
        $label = $this->divLabel($node);

        return $label === '' ? $body : $label . "\n" . $body;
    }

    private function divLabel(Div $node): string
    {
        // `title` names a details/spoiler block, `label` a tab panel. Either
        // way it is the block's name, and a tab without one is just prose
        // butted against the next tab.
        foreach (['title', 'label'] as $attribute) {
            $value = $this->stripControls($node->getAttribute($attribute) ?? '');
            if ($value !== '') {
                return $this->escapeText($value) . ':';
            }
        }

        $class = $node->getAttribute('class') ?? '';
        foreach (explode(' ', $class) as $candidate) {
            if (in_array($candidate, self::LABELLED_DIV_CLASSES, true)) {
                return $this->escapeText(ucfirst($candidate)) . ':';
            }
        }

        return '';
    }

    /**
     * Carve's own delimiters, as produced by the core serializer. Used by the
     * `carve` fallback so an inexpressible mark stays visible as markup rather
     * than flattening into ordinary text.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const CARVE_DELIMITERS = [
        NodeType::EMPHASIS => ['/', '/'],
        NodeType::STRONG => ['*', '*'],
        NodeType::UNDERLINE => ['_', '_'],
        NodeType::STRIKE => ['~', '~'],
        NodeType::SUPERSCRIPT => ['{^', '^}'],
        NodeType::SUBSCRIPT => ['{,', ',}'],
        NodeType::HIGHLIGHT => ['{=', '=}'],
        NodeType::INSERT => ['{+', '+}'],
        NodeType::DELETE => ['{-', '-}'],
    ];

    private function carveFallback(Node $node): string
    {
        $inner = $this->renderChildren($node);
        $delimiters = self::CARVE_DELIMITERS[$node->getType()] ?? null;
        if ($delimiters === null || $inner === '') {
            return $inner;
        }

        return $delimiters[0] . $inner . $delimiters[1];
    }

    private function renderDelimited(Node $node): string
    {
        $config = $this->flavor->emission($node->getType()) ?? [];
        if (isset($config['template']) && is_string($config['template'])) {
            return $this->applyTemplate($config['template'], $node, $this->renderChildren($node));
        }

        if ($this->rangeMode) {
            return $this->markStyled($node->getType(), $this->renderChildren($node));
        }

        $open = is_string($config['open'] ?? null) ? $config['open'] : '';
        $close = is_string($config['close'] ?? null) ? $config['close'] : '';

        return $open . $this->renderChildren($node) . $close;
    }

    /**
     * An extension is addressed by a qualified key, so a flavor can map Carve's
     * spoiler without claiming every extension that exists.
     *
     * Spoiler is an extension rather than a core node, but several targets have
     * a real spoiler of their own, so it is worth mapping precisely.
     */
    private function renderInlineExtension(InlineExtension $node): string
    {
        $key = NodeType::INLINE_EXTENSION . ':' . $node->getExtensionType();
        if (!$this->flavor->supports($key)) {
            return $this->renderChildren($node);
        }

        if ($this->rangeMode && $this->flavor->styleFor($key) !== null) {
            return $this->markStyled($key, $this->renderChildren($node));
        }

        $config = $this->flavor->emission($key) ?? [];
        $open = is_string($config['open'] ?? null) ? $config['open'] : '';
        $close = is_string($config['close'] ?? null) ? $config['close'] : '';

        return $open . $this->renderChildren($node) . $close;
    }

    /**
     * Recovers the styled spans from the assembled message and strips the
     * markers back out.
     *
     * @return array{0: string, 1: array<\MarkupCarve\Chat\StyleRange>}
     */
    private function extractRanges(string $text): array
    {
        $clean = '';
        $open = [];
        $spans = [];
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            $plain = strcspn($text, "\x01\x03", $offset);
            if ($plain > 0) {
                $clean .= substr($text, $offset, $plain);
                $offset += $plain;

                continue;
            }

            $isOpen = $text[$offset] === "\x01";
            $end = strpos($text, $isOpen ? "\x02" : "\x04", $offset);
            if ($end === false) {
                $clean .= $text[$offset];
                $offset++;

                continue;
            }

            $id = (int)substr($text, $offset + 1, $end - $offset - 1);
            $offset = $end + 1;

            if ($isOpen) {
                $open[$id] = strlen($clean);

                continue;
            }

            if (!isset($open[$id], $this->styleNames[$id])) {
                continue;
            }

            $start = $open[$id];
            unset($open[$id]);
            if (strlen($clean) > $start) {
                $spans[] = [$start, strlen($clean) - $start, $this->styleNames[$id], $this->styleData[$id] ?? []];
            }
        }

        // Same start: the longer span is the outer one and must open first, or
        // the preview would nest them inside out.
        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $b[1] <=> $a[1]);

        $ranges = [];
        foreach ($spans as [$start, $len, $style, $data]) {
            $ranges[] = new StyleRange(
                start: $this->countUnits(substr($clean, 0, $start)),
                length: $this->countUnits(substr($clean, $start, $len)),
                style: $style,
                data: $data,
            );
        }

        return [$clean, $ranges];
    }

    /**
     * Measures a string in the flavor's offset unit.
     *
     * Telegram documents its entity offsets in UTF-16 code units, so a
     * character outside the BMP counts as two. Measuring in the wrong unit
     * shifts every range that follows such a character.
     */
    private function countUnits(string $text): int
    {
        return match ($this->flavor->offsetUnit()) {
            OffsetUnit::Utf8 => strlen($text),
            OffsetUnit::Codepoints => mb_strlen($text, 'UTF-8'),
            OffsetUnit::Utf16 => (int)(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2),
        };
    }

    /**
     * Wraps content in marker sentinels so its offsets can be recovered once
     * the whole message is assembled.
     *
     * The renderer concatenates return values, so a node cannot know its
     * absolute position while it renders. Control characters other than tab
     * and newline are stripped from all document text, so these markers can
     * never collide with content.
     */

    /**
     * @param string $nodeType
     * @param string $content
     * @param array<string, string> $data
     */
    private function markStyled(string $nodeType, string $content, array $data = []): string
    {
        $style = $this->flavor->styleFor($nodeType);
        if ($style === null || $content === '') {
            return $content;
        }

        $id = $this->markerId++;
        $this->styleNames[$id] = $style;
        $this->styleData[$id] = $data;

        return "\x01" . $id . "\x02" . $content . "\x03" . $id . "\x04";
    }

    private function renderHeading(Heading $node): string
    {
        $level = min(3, $node->getLevel());
        $content = trim((string)preg_replace('/\s*\n\s*/', ' ', $this->renderChildren($node)));
        $template = $this->templateFor($node, '{content}');

        return $this->applyTemplate($template, $node, $content, str_repeat('#', $level)) . "\n\n";
    }

    private function renderCodeBlock(CodeBlock $node): string
    {
        $config = $this->flavor->emission($node->getType()) ?? [];
        $open = is_string($config['open'] ?? null) ? $config['open'] : '```';
        $close = is_string($config['close'] ?? null) ? $config['close'] : '```';
        $language = $this->stripControls($node->getLanguage() ?? '');
        $language = preg_split('/\s/', $language, 2)[0] ?? '';

        // The language tag is part of the fence syntax. A flavor with no opening
        // delimiter has nowhere to hang it, and emitting it anyway would drop a
        // bare `php` line into the message body.
        if ($open === '') {
            $language = '';
        }

        $content = $this->flavor->escaper()->escapeVerbatim($this->stripControls($node->getContent()));

        if ($this->rangeMode && $this->flavor->styleFor($node->getType()) !== null) {
            $data = $language === '' ? [] : ['language' => $language];

            return $this->markStyled($node->getType(), $content, $data) . "\n\n";
        }

        if (self::isBacktickRun($open) && self::isBacktickRun($close)) {
            $fence = StringUtil::findSafeCodeFence($content, strlen($open));

            return $fence . $language . "\n" . $content . "\n" . $fence . "\n\n";
        }

        return $open . $language . "\n" . $content . "\n" . $close . "\n\n";
    }

    private function renderCode(Code $node): string
    {
        return $this->renderCodeLike($node->getContent(), $node->getType());
    }

    private function renderCodeLike(string $content, string $nodeType): string
    {
        $config = $this->flavor->emission($nodeType) ?? [];
        $open = is_string($config['open'] ?? null) ? $config['open'] : '`';
        $close = is_string($config['close'] ?? null) ? $config['close'] : '`';
        $content = $this->flavor->escaper()->escapeVerbatim($this->stripControls($content));

        if ($this->rangeMode) {
            return $this->markStyled($nodeType, $content);
        }

        if (!self::isBacktickRun($open) || !self::isBacktickRun($close)) {
            return $open . $content . $close;
        }

        $fence = StringUtil::findSafeCodeFence($content, strlen($open));
        $pad = str_starts_with($content, '`') || str_ends_with($content, '`') ? ' ' : '';

        return $fence . $pad . $content . $pad . $fence;
    }

    /**
     * Unwrapping emits the children without their markup. Nodes that carry
     * their payload as content rather than children - code blocks, math, raw -
     * have no children to emit, so fall back to that content instead of
     * silently dropping it.
     */
    private function unwrapFallback(Node $node): string
    {
        if ($node instanceof ListBlock) {
            return $this->renderList($node, true);
        }

        // A div is unsupported everywhere, so its label has to survive the
        // fallback path rather than the native one.
        if ($node instanceof Div) {
            return $this->renderDiv($node);
        }

        $rendered = $this->renderChildren($node);
        if ($rendered !== '') {
            return $rendered;
        }

        $inline = $node instanceof Code || $node instanceof RawInline
            || $node instanceof RawText || $node instanceof CitationGroup;

        $content = match (true) {
            $node instanceof CodeBlock, $node instanceof RawBlock, $node instanceof Math,
            $node instanceof Code, $node instanceof RawInline, $node instanceof RawText => $node->getContent(),
            $node instanceof CitationGroup => $node->getRaw(),
            default => '',
        };

        if ($content === '') {
            return '';
        }

        $content = $this->flavor->escaper()->escapeVerbatim($this->stripControls($content));

        return $inline ? $content : $content . "\n\n";
    }

    private function escapeText(string $content): string
    {
        $content = $this->stripControls($content);
        $escaper = $this->flavor->escaper();

        return $this->verbatim ? $escaper->escapeVerbatim($content) : $escaper->escape($content);
    }

    /**
     * Runs $render with markup escaping switched off, for content that ends up
     * inside a code fence.
     *
     * @param \Closure(): string $render
     */
    private function renderVerbatim(Closure $render): string
    {
        $previous = $this->verbatim;
        $this->verbatim = true;
        try {
            return $render();
        } finally {
            $this->verbatim = $previous;
        }
    }

    /**
     * A backtick-delimited flavor has to widen its fence when the payload
     * contains backticks. A tag-delimited one (HTML mode) does not - there the
     * entity escaping already neutralizes the closing sequence.
     */
    private static function isBacktickRun(string $delimiter): bool
    {
        return $delimiter !== '' && trim($delimiter, '`') === '';
    }

    private function renderBlockQuote(BlockQuote $node): string
    {
        if ($this->rangeMode && $this->flavor->styleFor($node->getType()) !== null) {
            return $this->markStyled($node->getType(), trim($this->renderChildren($node))) . "\n\n";
        }

        $config = $this->flavor->emission($node->getType()) ?? [];
        if (isset($config['template']) && is_string($config['template'])) {
            return $this->applyTemplate($config['template'], $node, trim($this->renderChildren($node))) . "\n\n";
        }

        if (isset($config['open'], $config['close']) && is_string($config['open']) && is_string($config['close'])) {
            return $config['open'] . trim($this->renderChildren($node)) . $config['close'] . "\n\n";
        }

        $prefix = is_string($config['prefix'] ?? null) ? $config['prefix'] : '> ';
        $lines = explode("\n", trim($this->renderChildren($node)));
        $quoted = array_map(static fn (string $line): string => $prefix . $line, $lines);

        return implode("\n", $quoted) . "\n\n";
    }

    private function renderList(ListBlock $node, bool $degraded): string
    {
        $this->listDepth++;
        $output = '';
        $counter = $node->getStart();
        $config = $this->flavor->emission($node->getType()) ?? [];
        $bullet = is_string($config['bullet'] ?? null) ? $config['bullet'] : '- ';
        $ordered = is_string($config['ordered'] ?? null) ? $config['ordered'] : '{number}. ';

        foreach ($node->getChildren() as $child) {
            if (!$child instanceof ListItem) {
                continue;
            }

            $prefix = $node->getListType() === ListBlock::TYPE_ORDERED
                ? str_replace('{number}', (string)$counter++, $ordered)
                : $bullet;
            $lines = explode("\n", trim($this->renderChildren($child)));
            $first = array_shift($lines);
            $output .= str_repeat('  ', $this->listDepth - 1) . $prefix . $first . "\n";
            foreach ($lines as $line) {
                $output .= str_repeat('  ', $this->listDepth - 1) . str_repeat(' ', strlen($prefix)) . $line . "\n";
            }
        }

        $this->listDepth--;

        return $output . ($this->listDepth === 0 && !$degraded ? "\n" : ($this->listDepth === 0 ? "\n" : ''));
    }

    private function renderLineBlock(LineBlock $node): string
    {
        $lines = [];
        foreach ($node->getChildren() as $child) {
            $lines[] = trim($this->renderNode($child));
        }

        return implode("\n", $lines) . "\n\n";
    }

    private function renderFootnote(Footnote $node): string
    {
        return '[' . $this->flavor->escaper()->escape($this->stripControls($node->getLabel())) . ']: '
            . trim($this->renderChildren($node)) . "\n";
    }

    private function renderMention(Mention $node): string
    {
        $config = $this->flavor->emission($node->getType()) ?? [];
        if (isset($config['template']) && is_string($config['template'])) {
            return $this->applyTemplate($config['template'], $node, $this->renderChildren($node));
        }

        return $this->renderLink($node);
    }

    private function renderLink(Link $node): string
    {
        $content = $this->renderChildren($node);
        $url = $this->stripControls((string)$node->getDestination());
        $title = $node->getTitle();

        if ($this->rangeMode && $this->flavor->styleFor(NodeType::LINK) !== null && $url !== '') {
            return $this->markStyled(NodeType::LINK, $content, ['url' => $url]);
        }

        return match ($this->flavor->linkStyle()) {
            LinkStyle::Markdown => '[' . $this->escapeMarkdownLabel($content) . '](' . $this->escapeMarkdownDestination($url, $title) . ')',
            LinkStyle::SlackPipe => '<' . $this->escapeSlackUrl($url) . '|' . $content . '>',
            LinkStyle::Html => '<a href="' . $this->escapeHtmlAttribute($url) . '">' . $content . '</a>',
            LinkStyle::None => $this->renderFallback($node),
        };
    }

    /**
     * A `]` inside the label closes the link early, so brackets are escaped
     * whatever the flavor's own escape character set happens to be.
     */
    private function escapeMarkdownLabel(string $label): string
    {
        return str_replace(['[', ']'], ['\[', '\]'], $label);
    }

    private function renderImageNative(Image $node): string
    {
        return $this->applyTemplate($this->templateFor($node, '{alt} ({url})'), $node, '');
    }

    private function renderInlineFallback(Node $node): string
    {
        return $this->applyTemplate($this->templateFor($node, '{content}'), $node, $this->renderChildren($node));
    }

    private function renderInlineBlockAwareFallback(Node $node): string
    {
        $rendered = $this->renderInlineFallback($node);
        if ($node instanceof Heading) {
            return $rendered . "\n\n";
        }

        return $rendered;
    }

    private function renderCodeBlockFallback(Node $node): string
    {
        return $this->wrapInCodeFence($this->renderVerbatim(fn (): string => trim($this->renderChildren($node))));
    }

    /**
     * Wraps pre-rendered text in the flavor's own code-block delimiters. An
     * HTML-mode target declares `<pre>`, and would show a Markdown fence
     * literally.
     */
    private function wrapInCodeFence(string $content): string
    {
        // A range-based target has no fence to wrap this in; the monospace
        // comes from a style over the block instead.
        if ($this->rangeMode && $this->flavor->styleFor(NodeType::CODE_BLOCK) !== null) {
            return $this->markStyled(NodeType::CODE_BLOCK, $content) . "\n\n";
        }

        $config = $this->flavor->emission(NodeType::CODE_BLOCK) ?? [];
        $open = is_string($config['open'] ?? null) ? $config['open'] : '```';
        $close = is_string($config['close'] ?? null) ? $config['close'] : '```';

        if (self::isBacktickRun($open) && self::isBacktickRun($close)) {
            $fence = StringUtil::findSafeCodeFence($content, strlen($open));

            return $fence . "\n" . $content . "\n" . $fence . "\n\n";
        }

        return $open . "\n" . $content . "\n" . $close . "\n\n";
    }

    private function appendixFallback(Node $node): string
    {
        $content = trim($this->renderInlineFallback($node));
        if ($content === '') {
            $content = trim($this->renderChildren($node));
        }
        if ($node instanceof Footnote) {
            $this->appendix[$this->footnoteSlot($node->getLabel())] = $content;

            return '';
        }

        $number = ++$this->appendixCounter;
        $this->appendix[$number] = $content;

        return '[' . $number . ']';
    }

    /**
     * Resolves a footnote label to its appendix number, assigning one on first
     * sight. References are rendered before definitions, so either side may be
     * the first to ask.
     */
    private function footnoteSlot(string $label): int
    {
        return $this->footnoteSlots[$label] ??= ++$this->appendixCounter;
    }

    private function renderTableCodeBlock(Table $node): string
    {
        $rows = $this->tableRows($node);
        if ($rows === []) {
            return $this->wrapInCodeFence('');
        }

        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, strlen($cell));
            }
        }

        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $index => $cell) {
                $cells[] = str_pad($cell, $widths[$index] ?? 0);
            }
            $lines[] = rtrim(implode('  ', $cells));
        }

        return $this->wrapInCodeFence(implode("\n", $lines));
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function tableRows(Table $node): array
    {
        $layout = TableLayout::expand(
            $node,
            fn (TableCell $cell): string => $this->renderVerbatim(
                fn (): string => trim($this->renderChildren($cell)),
            ),
        );
        $rows = [];
        foreach ($layout['rows'] as $row) {
            $cells = [];
            foreach ($row['cells'] as $cell) {
                $cells[] = is_string($cell) ? $cell : '';
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    private function renderChildren(Node $node): string
    {
        $output = '';
        foreach ($node->getChildren() as $child) {
            $output .= $this->renderNode($child);
        }

        return $output;
    }

    private function templateFor(Node $node, string $default): string
    {
        $config = $this->flavor->emission($node->getType()) ?? [];

        return is_string($config['template'] ?? null) ? $config['template'] : $default;
    }

    private function applyTemplate(string $template, Node $node, string $content, string $hashes = ''): string
    {
        $url = '';
        $alt = '';
        $title = '';
        if ($node instanceof Link) {
            $url = $this->stripControls((string)$node->getDestination());
            $title = $this->stripControls($node->getTitle() ?? '');
        }
        if ($node instanceof Image) {
            $url = $this->stripControls($node->getSource());
            $alt = $this->flavor->escaper()->escape($this->stripControls($node->getAlt()));
            $title = $this->stripControls($node->getTitle() ?? '');
        }

        return strtr($template, [
            '{content}' => $content,
            '{url}' => $this->flavor->escaper()->escapeVerbatim($url),
            '{alt}' => $alt,
            '{title}' => $this->flavor->escaper()->escape($title),
            '{hashes}' => $hashes,
        ]);
    }

    private function recordLoss(Node $node, Fallback $fallback, string $reason): void
    {
        $this->losses[] = new Loss($node->getType(), $this->sourceLine($node), $fallback, $reason);
    }

    private function sourceLine(Node $node): ?int
    {
        $line = $node->getAttribute('data-source-line');
        if ($line !== null && ctype_digit($line)) {
            return (int)$line;
        }

        if (method_exists($node, 'getSourceLine')) {
            $sourceLine = $node->getSourceLine();
            if (is_int($sourceLine)) {
                return $sourceLine;
            }
        }

        return null;
    }

    private function escapeMarkdownDestination(string $url, ?string $title): string
    {
        $destination = strtr($url, [
            ' ' => '%20',
            '(' => '%28',
            ')' => '%29',
            '<' => '%3C',
            '>' => '%3E',
        ]);
        if ($title === null) {
            return $destination;
        }

        return $destination . ' "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $this->stripControls($title)) . '"';
    }

    private function escapeSlackUrl(string $url): string
    {
        return str_replace(['&', '<', '>', '|'], ['&amp;', '&lt;', '&gt;', '%7C'], $url);
    }

    private function escapeHtmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function stripControls(string $text): string
    {
        return (string)preg_replace('/(?!\x{0009}|\x{000A})\p{Cc}/u', '', $text);
    }
}
