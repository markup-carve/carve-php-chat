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
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\Delete;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Highlight;
use MarkupCarve\Carve\Node\Inline\Image;
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
    private const MAX_RENDER_DEPTH = 512;

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

        $limit = $this->flavor->messageLimit();
        if ($limit !== null && strlen($text) > $limit) {
            $this->losses[] = new Loss(
                nodeType: 'document',
                sourceLine: null,
                fallback: Fallback::Unwrap,
                reason: sprintf('Rendered message length %d exceeds limit %d.', strlen($text), $limit),
            );
        }

        return new ChatResult($text, $this->losses);
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
            $node instanceof Div => $this->renderChildren($node),
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
        $fallback = $this->flavor->fallbackEnum($node->getType());
        $this->recordLoss($node, $fallback, sprintf('Node "%s" is not native in %s.', $node->getType(), $this->flavor->id()));

        return match ($fallback) {
            Fallback::Unwrap => $node instanceof ListBlock ? $this->renderList($node, true) : $this->renderChildren($node),
            Fallback::Inline => $this->renderInlineBlockAwareFallback($node),
            Fallback::CodeBlock => $node instanceof Table ? $this->renderTableCodeBlock($node) : $this->renderCodeBlockFallback($node),
            Fallback::Appendix => $this->appendixFallback($node),
            Fallback::Drop => '',
        };
    }

    private function renderDelimited(Node $node): string
    {
        $config = $this->flavor->emission($node->getType()) ?? [];
        if (isset($config['template']) && is_string($config['template'])) {
            return $this->applyTemplate($config['template'], $node, $this->renderChildren($node));
        }

        $open = is_string($config['open'] ?? null) ? $config['open'] : '';
        $close = is_string($config['close'] ?? null) ? $config['close'] : '';

        return $open . $this->renderChildren($node) . $close;
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

        $content = $this->flavor->escaper()->escapeVerbatim($this->stripControls($node->getContent()));

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

        if (!self::isBacktickRun($open) || !self::isBacktickRun($close)) {
            return $open . $content . $close;
        }

        $fence = StringUtil::findSafeCodeFence($content, strlen($open));
        $pad = str_starts_with($content, '`') || str_ends_with($content, '`') ? ' ' : '';

        return $fence . $pad . $content . $pad . $fence;
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
