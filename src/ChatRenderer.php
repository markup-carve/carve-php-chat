<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Output\OutputWriter;

final class ChatRenderer implements OutputWriter
{
    /**
     * @var int
     */
    public const MAX_RENDER_DEPTH = 512;

    /**
     * @var array<\MarkupCarve\Chat\Loss>
     */
    private array $losses = [];

    /**
     * @var array<int, string>
     */
    private array $appendix = [];

    /**
     * @var array<string, int>
     */
    private array $footnoteSlots = [];

    /**
     * @var array<int, string>
     */
    private array $styleNames = [];

    /**
     * @var array<int, array<string, string>>
     */
    private array $styleData = [];

    private int $markerId = 0;

    private int $depth = 0;

    private int $listDepth = 0;

    private bool $rangeMode = false;

    private bool $verbatim = false;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $positions = [];

    /**
     * @var array<int, array<array{file: string, line: int, column: int}>>
     */
    private array $includeSites = [];

    /**
     * @var array
     */
    /**
     * @var array<string, array{string, string}>
     */
    private const CARVE_DELIMITERS = [
        'emphasis' => ['/', '/'],
        'strong' => ['*', '*'],
        'underline' => ['_', '_'],
        'strike' => ['~', '~'],
        'superscript' => ['{^', '^}'],
        'subscript' => ['{,', ',}'],
        'highlight' => ['{=', '=}'],
        'insert' => ['{+', '+}'],
        'delete' => ['{-', '-}'],
    ];

    public function __construct(private readonly ChatFlavor $flavor)
    {
    }

    public function target(): string
    {
        return 'chat-' . $this->flavor->id();
    }

    public function write(Node $tree, array $options = []): string
    {
        if (array_diff(array_keys($options), ['sourceFile']) !== []) {
            throw new OptionError('chat writer takes no options');
        }

        return $this->render($tree, $options['sourceFile'] ?? null);
    }

    public function render(Node $document, ?string $sourceFile = null): string
    {
        return $this->renderResult($document, $sourceFile)->text;
    }

    public function renderResult(Node $document, ?string $sourceFile = null): ChatResult
    {
        $this->losses = $this->appendix = $this->footnoteSlots = [];
        $this->styleNames = $this->styleData = [];
        $this->markerId = $this->depth = $this->listDepth = 0;
        $this->rangeMode = $this->flavor->output() === OutputMode::Ranges;
        $this->positions = [];
        $this->includeSites = [];
        $this->indexPositions($document, json_decode(Carve::encodeTree($document), true), $sourceFile);
        $text = $this->renderNode($document);
        if ($this->appendix !== []) {
            ksort($this->appendix);
            $lines = [];
            foreach ($this->appendix as $number => $content) {
                $lines[] = '[' . $number . '] ' . $content;
            }
            $text = rtrim($text) . "\n\n" . implode("\n", $lines);
        }
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text) . "\n";
        $ranges = [];
        if ($this->rangeMode) {
            [$text, $ranges] = $this->extractRanges($text);
        }
        $limit = $this->flavor->messageLimit();
        if ($limit !== null && strlen($text) > $limit) {
            $this->losses[] = new Loss('document', null, Fallback::Unwrap, sprintf('Rendered message length %d exceeds limit %d.', strlen($text), $limit));
        }

        return new ChatResult($text, $this->losses, $ranges);
    }

    private function renderNode(Node $node): string
    {
        if ($this->depth >= self::MAX_RENDER_DEPTH) {
            return '';
        }
        $this->depth++;
        try {
            return $this->renderNodeInner($node);
        } finally {
            $this->depth--;
        }
    }

    private function renderNodeInner(Node $node): string
    {
        $type = $node->type;
        if ($type === 'document' || $type === 'include') {
            return $this->children($node);
        }
        if ($type === 'inline_extension') {
            $key = $type . ':' . ($node->f['name'] ?? '');
            $content = $this->children($node);
            if (!$this->flavor->supports($key)) {
                return $content;
            }
            if ($this->rangeMode) {
                return $this->markStyled($key, $content);
            }
            $config = $this->flavor->emission($key) ?? [];

            return ($config['open'] ?? '') . $content . ($config['close'] ?? '');
        }
        if (!$this->flavor->supports($type)) {
            return $this->fallback($node);
        }
        $value = $this->value($node);

        return match ($type) {
            'paragraph' => $this->children($node) . "\n\n",
            'cell_paragraph', 'table_cell', 'table_row', 'table_head', 'table_body', 'table_foot', 'list_item', 'section', 'figure', 'figure_group' => $this->children($node),
            'heading' => $this->heading($node),
            'code_block' => $this->codeBlock($node),
            'raw_block' => $this->stripControls($value) . "\n\n",
            'comment' => '',
            'block_quote' => $this->quote($node),
            'list' => $this->list($node),
            'definition_list' => $this->children($node) . "\n",
            'definition_term' => $this->children($node) . "\n",
            'definition_description' => '  ' . trim($this->children($node)) . "\n",
            'thematic_break' => "---\n\n",
            'div', 'admonition', 'directive' => $this->div($node),
            'block_extension' => $this->extension($node),
            'table' => $this->table($node),
            'line_block' => $this->lineBlock($node),
            'footnote' => '[' . $this->escapeText((string)($node->f['key'] ?? '')) . ']: ' . trim($this->children($node)) . "\n",
            'caption' => trim($this->children($node)) . "\n\n",
            'text', 'escaped_text', 'smart_punctuation', 'critic_comment', 'literal_inline' => $this->escapeText($value),
            'code', 'math' => $this->code($node),
            'image' => $this->image($node),
            'mention', 'tag' => $this->mention($node),
            'link' => $this->link($node),
            'non_breaking_space' => "\u{00A0}",
            'hard_break', 'soft_break' => "\n",
            'cell_wrap' => ' ',
            'strong', 'emphasis', 'underline', 'strike', 'highlight', 'insert', 'delete', 'superscript', 'subscript', 'span', 'inline_footnote' => $this->delimited($node),
            'symbol' => ':' . $this->escapeText((string)($node->f['name'] ?? '')) . ':',
            'footnote_ref' => '[' . ($node->f['number'] ?? $this->footnoteSlot((string)($node->f['key'] ?? ''))) . ']',
            'heading_ref' => '</#' . $this->escapeText((string)($node->f['target'] ?? '')) . '>',
            'caption_number' => (string)($node->f['number'] ?? '#'),
            'raw_inline', 'raw_text' => $this->flavor->escaper()->escape($this->stripControls($value)),
            'substitution' => $this->substitution($node),
            default => $this->children($node),
        };
    }

    private function fallback(Node $node): string
    {
        $type = $node->type;
        $fallback = $this->flavor->fallbackEnum($type);
        $position = $this->positions[spl_object_id($node)] ?? [];
        $this->losses[] = new Loss($type, $position['startLine'] ?? null, $fallback, sprintf('Node "%s" is not native in %s.', $type, $this->flavor->id()), $node->file, $this->includeSites[spl_object_id($node)] ?? null);
        $content = match ($fallback) {
            Fallback::Drop => '',
            Fallback::CodeBlock => $type === 'table' ? $this->table($node) : $this->fence(trim($this->children($node))),
            Fallback::Appendix => $this->appendix($node),
            Fallback::Carve => $this->carve($node),
            Fallback::Inline => $this->inlineFallback($node),
            Fallback::Unwrap => $this->unwrap($node),
        };
        if ($this->rangeMode && $content !== '') {
            $trimmed = rtrim($content, "\n");

            return $this->markStyled($type, $trimmed) . substr($content, strlen($trimmed));
        }

        return $content;
    }

    private function unwrap(Node $node): string
    {
        return match ($node->type) {
            'list' => $this->list($node),
            'div', 'admonition', 'directive' => $this->div($node),
            'link', 'image' => $this->inlineFallback($node),
            'mention' => $this->mention($node),
            'tag' => '#' . $this->escapeText((string)($node->f['name'] ?? '')),
            'comment', 'raw_block' => '',
            'code_block', 'code', 'math', 'raw_inline', 'raw_text', 'citation_group' => $this->escapeText($this->value($node)),
            'table_head', 'table_body', 'table_foot', 'cell_paragraph', 'table_cell', 'table_row', 'list_item' => $this->children($node),
            'block_extension' => $this->extension($node),
            default => $this->children($node),
        };
    }

    private function inlineFallback(Node $node): string
    {
        $content = trim($this->children($node));
        if ($node->type === 'heading') {
            return $this->template($node, $content, str_repeat('#', min(3, (int)($node->f['level'] ?? 1)))) . "\n\n";
        }

        return $this->template($node, $content);
    }

    private function carve(Node $node): string
    {
        $content = $this->children($node);
        $pair = self::CARVE_DELIMITERS[$node->type] ?? null;

        return $pair === null || $content === '' ? $content : $pair[0] . $content . $pair[1];
    }

    private function delimited(Node $node): string
    {
        $content = $this->children($node);
        $config = $this->flavor->emission($node->type) ?? [];
        if (is_string($config['template'] ?? null)) {
            return $this->template($node, $content);
        }
        if ($this->rangeMode) {
            return $this->markStyled($node->type, $content);
        }

        return ($config['open'] ?? '') . $content . ($config['close'] ?? '');
    }

    private function heading(Node $node): string
    {
        $content = trim((string)preg_replace('/\s*\n\s*/', ' ', $this->children($node)));

        return $this->template($node, $content, str_repeat('#', min(3, (int)($node->f['level'] ?? 1)))) . "\n\n";
    }

    private function codeBlock(Node $node): string
    {
        $content = $this->flavor->escaper()->escapeVerbatim($this->stripControls(rtrim($this->value($node), "\n")));
        if ($this->rangeMode) {
            $data = [];
            if (is_string($node->f['syntax'] ?? null)) {
                $data['language'] = $node->f['syntax'];
            }

            return $this->markStyled('code_block', $content, $data) . "\n\n";
        }

        return $this->fence($content, (string)($node->f['syntax'] ?? ''));
    }

    private function code(Node $node): string
    {
        $content = $this->flavor->escaper()->escapeVerbatim($this->stripControls($this->value($node)));
        if ($this->rangeMode) {
            return $this->markStyled($node->type, $content);
        }
        $config = $this->flavor->emission($node->type) ?? [];
        $open = (string)($config['open'] ?? '`');
        $close = (string)($config['close'] ?? $open);
        if ($open !== '' && str_contains($content, $open)) {
            $open = $close = str_repeat($open[0], max(strlen($open), $this->longestRun($content, $open[0]) + 1));
        }

        return $open . $content . $close;
    }

    private function fence(string $content, string $language = ''): string
    {
        if ($this->rangeMode && $this->flavor->styleFor('code_block') !== null) {
            return $this->markStyled('code_block', $content, $language === '' ? [] : ['language' => $language]) . "\n\n";
        }
        $config = $this->flavor->emission('code_block') ?? [];
        $open = (string)($config['open'] ?? '```');
        $close = (string)($config['close'] ?? $open);
        if (str_starts_with($open, '`') && $open === str_repeat('`', strlen($open))) {
            $open = $close = str_repeat('`', max(strlen($open), $this->longestRun($content, '`') + 1));
        }

        return $open . ($open === '' ? '' : $language) . "\n" . $content . "\n" . $close . "\n\n";
    }

    private function longestRun(string $text, string $char): int
    {
        preg_match_all('/' . preg_quote($char, '/') . '+/', $text, $matches);

        return max(array_map('strlen', $matches[0] ?: ['']));
    }

    private function quote(Node $node): string
    {
        $content = trim($this->children($node));
        $config = $this->flavor->emission('block_quote') ?? [];
        if ($this->rangeMode && $this->flavor->styleFor('block_quote') !== null) {
            return $this->markStyled('block_quote', $content) . "\n\n";
        }
        if (is_string($config['template'] ?? null)) {
            return $this->template($node, $content) . "\n\n";
        }
        if (isset($config['open'], $config['close'])) {
            return $config['open'] . $content . $config['close'] . "\n\n";
        }
        $prefix = (string)($config['prefix'] ?? '> ');

        return implode("\n", array_map(static fn (string $line): string => $prefix . $line, explode("\n", $content))) . "\n\n";
    }

    private function list(Node $node): string
    {
        $this->listDepth++;
        $config = $this->flavor->emission('list') ?? [];
        $ordered = ($node->f['marker']['kind'] ?? '') !== 'bullet';
        $number = (int)($node->f['start'] ?? 1);
        $out = '';
        foreach ($node->children ?? [] as $item) {
            if ($item->type !== 'list_item') {
                continue;
            }
            $prefix = $ordered ? str_replace('{number}', (string)$number++, (string)($config['ordered'] ?? '{number}. ')) : (string)($config['bullet'] ?? '- ');
            $content = trim($this->children($item));
            $lines = explode("\n", $content);
            $first = array_shift($lines);
            $out .= str_repeat('  ', $this->listDepth - 1) . $prefix . $first . "\n";
            foreach ($lines as $line) {
                $out .= str_repeat('  ', $this->listDepth - 1) . str_repeat(' ', strlen($prefix)) . $line . "\n";
            }
        }
        $this->listDepth--;

        return $out . ($this->listDepth === 0 ? "\n" : '');
    }

    private function div(Node $node): string
    {
        $body = $this->children($node);
        $title = $node->f['title'] ?? null;
        $label = is_array($title) ? $this->plainNodes($title) : (string)($node->f['label'] ?? $node->f['kind'] ?? '');
        $attrs = $node->f['attrs'] ?? null;
        if ($label === '' && $attrs instanceof Attrs) {
            $label = (string)($attrs->get('title') ?? $attrs->get('label') ?? '');
        }
        if ($label === '' && is_array($attrs)) {
            $label = (string)($attrs['title'] ?? $attrs['label'] ?? '');
        }

        return $label === '' ? $body : $this->escapeText(ucfirst($label)) . ":\n" . $body;
    }

    private function extension(Node $node): string
    {
        if (($node->f['name'] ?? '') === 'carve.tabs') {
            $out = '';
            foreach ($node->f['slots']['tabs'] ?? [] as $tab) {
                if (!$tab instanceof Node) {
                    continue;
                }
                $title = $this->plainNodes($tab->f['slots']['title'] ?? []);
                $body = $this->renderNodes($tab->f['slots']['body'] ?? []);
                $out .= $this->escapeText($title) . ":\n" . $body;
            }

            return $out;
        }

        return $this->renderNodes($node->f['fallback'] ?? []);
    }

    /**
     * @param array<mixed> $nodes
     */
    private function renderNodes(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if ($node instanceof Node) {
                $out .= $this->renderNode($node);
            }
        }

        return $out;
    }

    private function lineBlock(Node $node): string
    {
        $lines = [];
        foreach ($node->children ?? [] as $child) {
            $lines[] = trim($this->renderNode($child));
        }

        return implode("\n", $lines) . "\n\n";
    }

    private function link(Node $node): string
    {
        $content = $this->children($node);
        $url = $this->stripControls((string)($node->f['href'] ?? ''));
        if ($url === '' || !$this->safeUrl($url)) {
            return $content;
        }
        if ($this->rangeMode && $this->flavor->styleFor('link') !== null) {
            return $this->markStyled('link', $content, ['url' => $url]);
        }

        return match ($this->flavor->linkStyle()) {
            LinkStyle::Markdown => '[' . str_replace(['\\', '[', ']'], ['\\\\', '\[', '\]'], $content) . '](' . $this->escapeMarkdownDestination($url, $node->f['title'] ?? null) . ')',
            LinkStyle::SlackPipe => '<' . $this->escapeSlackUrl($url) . '|' . $content . '>',
            LinkStyle::Html => '<a href="' . $this->escapeHtmlAttribute($url) . '">' . $content . '</a>',
            LinkStyle::None => $this->template($node, $content),
        };
    }

    private function image(Node $node): string
    {
        return $this->template($node, '');
    }

    private function mention(Node $node): string
    {
        $target = (string)($node->f['target'] ?? $node->f['name'] ?? '');

        return $target === '' ? $this->children($node) : '@' . $this->escapeText($target);
    }

    private function substitution(Node $node): string
    {
        return $this->children($node);
    }

    private function appendix(Node $node): string
    {
        $key = (string)($node->f['key'] ?? '');
        $slot = $this->footnoteSlot($key);
        $this->appendix[$slot] = trim($this->children($node));

        return '';
    }

    private function footnoteSlot(string $key): int
    {
        return $this->footnoteSlots[$key] ??= count($this->footnoteSlots) + 1;
    }

    private function table(Node $node): string
    {
        $rows = [];
        $scan = function (Node $part) use (&$scan, &$rows): void {
            if ($part->type === 'table_row') {
                $cells = [];
                foreach ($part->children ?? [] as $cell) {
                    if ($cell->type === 'table_cell') {
                        $cells[] = trim($this->plain($cell));
                    }
                }
                $rows[] = $cells;

                return;
            }
            foreach ($part->children ?? [] as $child) {
                $scan($child);
            }
        };
        $scan($node);
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
            }
        }
        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $i => $cell) {
                $cells[] = str_pad($cell, $widths[$i] ?? 0);
            }
            $lines[] = rtrim(implode('  ', $cells));
        }

        return $this->fence($this->flavor->escaper()->escapeVerbatim(implode("\n", $lines)));
    }

    private function plain(Node $node): string
    {
        if (in_array($node->type, ['text', 'escaped_text', 'code', 'raw_text'], true)) {
            return $this->value($node);
        }
        if (in_array($node->type, ['cell_wrap', 'soft_break'], true)) {
            return ' ';
        }
        $out = '';
        foreach ($node->children ?? [] as $child) {
            $out .= $this->plain($child);
        }

        return $out;
    }

    /**
     * @param array<mixed> $nodes
     */
    private function plainNodes(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if ($node instanceof Node) {
                $out .= $this->plain($node);
            }
        }

        return $out;
    }

    private function children(Node $node): string
    {
        $out = '';
        $children = $node->children ?? [];
        foreach ($children as $index => $child) {
            if ($child->type === 'soft_break' && ($children[$index + 1]->type ?? '') === 'comment') {
                continue;
            }
            if ($child->type === 'delete' && ($children[$index + 1]->type ?? '') === 'insert') {
                $content = $this->children($child);
                $strike = $this->flavor->emission('strike') ?? [];
                $out .= $this->rangeMode
                    ? $this->markStyled('strike', $content)
                    : ($strike['open'] ?? '{~') . $content . ($strike['close'] ?? '~}');
                $out .= ' ' . $this->children($children[$index + 1]);

                continue;
            }
            if ($child->type === 'insert' && ($children[$index - 1]->type ?? '') === 'delete') {
                continue;
            }
            $out .= $this->renderNode($child);
        }

        return $out;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param array<string, mixed>|null $encoded
     * @param string|null $sourceFile
     * @param array<array{file: string, line: int, column: int}> $sites
     */
    private function indexPositions(Node $node, ?array $encoded, ?string $sourceFile = null, array $sites = []): void
    {
        $this->positions[spl_object_id($node)] = $encoded['pos'] ?? [];
        if ($sites !== []) {
            $this->includeSites[spl_object_id($node)] = $sites;
        }
        if ($node->type === 'include' && is_string($node->f['file'] ?? null)) {
            $sites[] = [
                'file' => $sourceFile ?? '',
                'line' => (int)($encoded['pos']['startLine'] ?? 1),
                'column' => (int)($encoded['pos']['startColumn'] ?? 1),
            ];
            $sourceFile = $node->f['file'];
        }
        foreach ($node->children ?? [] as $index => $child) {
            $this->indexPositions($child, $encoded['children'][$index] ?? null, $sourceFile, $sites);
        }
    }

    private function value(Node $node): string
    {
        return (string)($node->f['value'] ?? $node->f['raw'] ?? '');
    }

    private function template(Node $node, string $content, string $hashes = ''): string
    {
        $config = $this->flavor->emission($node->type) ?? [];
        $template = (string)($config['template'] ?? '{content}');
        $url = (string)($node->f['href'] ?? $node->f['src'] ?? '');
        $alt = (string)($node->f['alt'] ?? '');
        if ($url === '' || !$this->safeUrl($url)) {
            $template = str_replace([' ({url})', '({url})'], '', $template);
            $url = '';
        }

        return strtr($template, [
            '{content}' => $content,
            '{url}' => $this->flavor->escaper()->escapeVerbatim($this->stripControls($url)),
            '{alt}' => $this->escapeText($alt),
            '{title}' => $this->escapeText((string)($node->f['title'] ?? '')),
            '{hashes}' => $hashes,
        ]);
    }

    private function safeUrl(string $url): bool
    {
        return preg_match('#^(https?://|mailto:|/|\#)#i', trim($url)) === 1;
    }

    private function escapeText(string $content): string
    {
        $content = $this->stripControls($content);

        return $this->verbatim ? $this->flavor->escaper()->escapeVerbatim($content) : $this->flavor->escaper()->escape($content);
    }

    /**
     * @return array{string, array<\MarkupCarve\Chat\StyleRange>}
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
