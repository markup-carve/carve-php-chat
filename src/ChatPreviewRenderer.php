<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Node;

final class ChatPreviewRenderer
{
    /**
     * @var int
     */
    public const MAX_RENDER_DEPTH = 512;

    /**
     * @var array
     */
    /**
     * @var array<string, string>
     */
    private const MARK_TAGS = [
        'strong' => 'strong',
        'emphasis' => 'em',
        'strike' => 's',
        'underline' => 'u',
        'highlight' => 'mark',
        'insert' => 'ins',
        'delete' => 'del',
    ];

    /**
     * @var string
     */
    private const SPOILER_KEY = 'inline_extension:spoiler';

    private int $depth = 0;

    public function __construct(private readonly ChatFlavor $flavor)
    {
    }

    public function render(Node $document): string
    {
        $this->depth = 0;
        if ($this->flavor->output() === OutputMode::Ranges) {
            return $this->renderFromRanges((new ChatRenderer($this->flavor))->renderResult($document));
        }

        return trim($this->children($document));
    }

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
            self::SPOILER_KEY => ['<span class="spoiler">', '</span>'],
            'code' => ['<code>', '</code>'],
            'code_block' => ['<pre><code>', '</code></pre>'],
            'block_quote' => ['<blockquote>', '</blockquote>'],
            'link' => $this->linkOpenTag($range),
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
            ['code', 'code_block', 'block_quote', 'link', self::SPOILER_KEY],
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

    private function children(Node $node): string
    {
        $out = '';
        $children = $node->children ?? [];
        foreach ($children as $index => $child) {
            if ($child->type === 'soft_break' && ($children[$index + 1]->type ?? '') === 'comment') {
                continue;
            }
            $out .= $this->node($child);
        }

        return $out;
    }

    private function node(Node $node): string
    {
        if ($this->depth >= self::MAX_RENDER_DEPTH) {
            return '';
        }
        $this->depth++;
        try {
            return $this->nodeInner($node);
        } finally {
            $this->depth--;
        }
    }

    private function nodeInner(Node $node): string
    {
        $type = $node->type;
        if (isset(self::MARK_TAGS[$type])) {
            $inner = $this->children($node);
            if (!$this->flavor->supports($type)) {
                return $inner;
            }
            $tag = self::MARK_TAGS[$type];

            return '<' . $tag . '>' . $inner . '</' . $tag . '>';
        }

        return match ($type) {
            'text', 'escaped_text', 'smart_punctuation', 'literal_inline' => $this->escape((string)($node->f['value'] ?? '')),
            'paragraph' => '<p>' . $this->children($node) . '</p>',
            'heading' => $this->heading($node),
            'block_quote' => $this->quote($node),
            'list' => $this->list($node),
            'list_item' => '<li>' . $this->itemContent($node) . '</li>',
            'code_block' => '<pre><code>' . $this->escape(rtrim((string)($node->f['value'] ?? ''), "\n")) . '</code></pre>',
            'inline_extension' => $this->inlineExtension($node),
            'code' => $this->flavor->supports('code') ? '<code>' . $this->escape((string)($node->f['value'] ?? '')) . '</code>' : $this->escape((string)($node->f['value'] ?? '')),
            'table' => $this->table($node),
            'link' => $this->link($node),
            'image' => $this->image($node),
            'soft_break', 'hard_break' => '<br>',
            'cell_wrap' => ' ',
            'raw_inline', 'raw_text' => $this->escape((string)($node->f['value'] ?? '')),
            default => $this->children($node),
        };
    }

    private function heading(Node $node): string
    {
        $inner = $this->children($node);
        if (!$this->flavor->supports('heading')) {
            $template = (string)($this->flavor->emission('heading')['template'] ?? '');

            return '<p>' . (str_contains($template, '*') || str_contains($template, '<b>') ? '<strong>' . $inner . '</strong>' : $inner) . '</p>';
        }
        $level = min(max((int)($node->f['level'] ?? 1), 1), 6);

        return '<h' . $level . '>' . $inner . '</h' . $level . '>';
    }

    private function quote(Node $node): string
    {
        if ($this->flavor->supports('block_quote')) {
            return '<blockquote>' . $this->children($node) . '</blockquote>';
        }
        $out = '';
        foreach ($node->children ?? [] as $child) {
            $out .= '<div>&gt; ' . $this->children($child) . '</div>';
        }

        return $out;
    }

    private function list(Node $node): string
    {
        $ordered = ($node->f['marker']['kind'] ?? 'bullet') !== 'bullet';
        if (!$this->flavor->supports('list')) {
            $lines = '';
            $number = (int)($node->f['start'] ?? 1);
            foreach ($node->children ?? [] as $item) {
                $prefix = $ordered ? $number++ . '. ' : '- ';
                $lines .= '<div>' . $this->escape($prefix) . $this->itemContent($item) . '</div>';
            }

            return $lines;
        }
        $tag = $ordered ? 'ol' : 'ul';

        return '<' . $tag . '>' . $this->children($node) . '</' . $tag . '>';
    }

    private function itemContent(Node $item): string
    {
        $out = '';
        foreach ($item->children ?? [] as $child) {
            $out .= $child->type === 'paragraph' ? $this->children($child) : $this->node($child);
        }

        return $out;
    }

    private function inlineExtension(Node $node): string
    {
        $content = $this->children($node);

        return ($node->f['name'] ?? '') === 'spoiler' && $this->flavor->supports(self::SPOILER_KEY)
            ? '<span class="spoiler">' . $content . '</span>' : $content;
    }

    private function table(Node $node): string
    {
        $rows = [];
        foreach (Carve::tableGrid($node) as $row) {
            $cells = [];
            foreach ($row['cells'] as $cell) {
                $cells[] = $cell === null ? '' : trim($this->plain($cell));
            }
            $rows[] = $cells;
        }
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

        return '<pre><code>' . $this->escape(implode("\n", $lines)) . '</code></pre>';
    }

    private function plain(Node $node): string
    {
        if (in_array($node->type, ['text', 'code', 'escaped_text'], true)) {
            return (string)($node->f['value'] ?? '');
        }
        if ($node->type === 'cell_wrap') {
            return ' ';
        }
        $out = '';
        foreach ($node->children ?? [] as $child) {
            $out .= $this->plain($child);
        }

        return $out;
    }

    private function link(Node $node): string
    {
        $inner = $this->children($node);
        $url = (string)($node->f['href'] ?? '');
        $safe = $this->sanitizeUrl($url);
        $inlined = $this->flavor->linkStyle() === LinkStyle::None;
        if ($safe === '') {
            return $inner;
        }
        $anchor = '<a href="' . $this->escape($safe) . '" rel="nofollow noopener" target="_blank">';

        return $inlined ? $inner . ' (' . $anchor . $this->escape($url) . '</a>)' : $anchor . $inner . '</a>';
    }

    private function image(Node $node): string
    {
        return $this->escape((string)($node->f['alt'] ?? '')) . ' (' . $this->escape((string)($node->f['src'] ?? '')) . ')';
    }

    private function sanitizeUrl(string $url): string
    {
        $trimmed = trim($url);

        return preg_match('#^(https?://|mailto:|/|\#)#i', $trimmed) === 1 ? $trimmed : '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
