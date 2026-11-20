<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

use MarkupCarve\Chat\Exception\InvalidFlavorException;

final readonly class ChatFlavor
{
    /**
     * @var string
     */
    public const SUPPORT_NATIVE = 'native';

    /**
     * @var string
     */
    public const SUPPORT_NONE = 'none';

    /**
     * @param string $id
     * @param string $label
     * @param string|null $extends
     * @param array<string, array<string, mixed>> $nodes
     * @param array $data
     * @param int|null $messageLimit
     * @param \MarkupCarve\Chat\OffsetUnit $offsetUnit
     * @param \MarkupCarve\Chat\OutputMode $output
     * @param \MarkupCarve\Chat\Escaper $escaper
     * @param \MarkupCarve\Chat\LinkStyle $linkStyle
     */
    private function __construct(
        private string $id,
        private string $label,
        private ?string $extends,
        private array $nodes,
        private LinkStyle $linkStyle,
        private Escaper $escaper,
        private ?int $messageLimit,
        private OutputMode $output,
        private OffsetUnit $offsetUnit,
        /** @var array<string, mixed> */
        private array $data,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param \MarkupCarve\Chat\ChatFlavor|null $parent
     *
     * @throws \MarkupCarve\Chat\Exception\InvalidFlavorException
     */
    public static function fromArray(array $data, ?ChatFlavor $parent = null): self
    {
        if ($parent !== null) {
            $data = self::mergeParent($parent->toArray(), $data);
        }

        $id = self::stringValue($data, 'id');
        $label = self::stringValue($data, 'label');
        $extends = isset($data['extends']) && is_string($data['extends']) ? $data['extends'] : null;
        $nodes = self::nodesValue($data);

        $link = self::arrayValue($data, 'link');
        $style = LinkStyle::tryFrom((string)($link['style'] ?? 'none'));
        if ($style === null) {
            throw new InvalidFlavorException(sprintf('Unknown link style in flavor "%s".', $id));
        }
        if ($style !== LinkStyle::None) {
            $nodes['link']['support'] = self::SUPPORT_NATIVE;
        }

        foreach ($nodes as $nodeType => $config) {
            // An extension-qualified key such as `inline_extension:spoiler`
            // targets one extension rather than every extension at once.
            $base = str_contains($nodeType, ':') ? strstr($nodeType, ':', true) : $nodeType;
            if (!in_array($base, self::knownNodeTypes(), true)) {
                // FORWARD, not sideways: a snake_case name the installed core
                // does not know is most plausibly a node type a NEWER core
                // added - the bundled flavors have to load on the released
                // core and on dev-main alike, and the completeness test
                // already fails the moment the entry is genuinely missing on
                // the newer one. Anything else (camelCase, a stray word) is
                // still the typo this guard exists for.
                if (preg_match('/^[a-z][a-z0-9_]*$/', (string)$base) === 1) {
                    unset($nodes[$nodeType]);

                    continue;
                }

                throw new InvalidFlavorException(sprintf('Unknown node type "%s" in flavor "%s".', $nodeType, $id));
            }

            $support = $config['support'] ?? null;
            if ($support !== self::SUPPORT_NATIVE && $support !== self::SUPPORT_NONE) {
                throw new InvalidFlavorException(sprintf('Unknown support value for node "%s" in flavor "%s".', $nodeType, $id));
            }

            if (isset($config['fallback']) && Fallback::tryFrom((string)$config['fallback']) === null) {
                throw new InvalidFlavorException(sprintf('Unknown fallback value for node "%s" in flavor "%s".', $nodeType, $id));
            }
        }

        $escape = self::arrayValue($data, 'escape');
        $mechanism = EscapeMechanism::tryFrom((string)($escape['mechanism'] ?? 'none'));
        if ($mechanism === null) {
            throw new InvalidFlavorException(sprintf('Unknown escape mechanism in flavor "%s".', $id));
        }

        $output = OutputMode::tryFrom((string)($data['output'] ?? OutputMode::Markup->value));
        if ($output === null) {
            throw new InvalidFlavorException(sprintf('Unknown output mode in flavor "%s".', $id));
        }

        $offsetUnit = OffsetUnit::tryFrom((string)($data['offsets'] ?? OffsetUnit::Utf16->value));
        if ($offsetUnit === null) {
            throw new InvalidFlavorException(sprintf('Unknown offset unit in flavor "%s".', $id));
        }

        $limits = self::arrayValue($data, 'limits');
        $messageLimit = isset($limits['message']) && is_int($limits['message']) ? $limits['message'] : null;
        $data['nodes'] = $nodes;

        return new self(
            id: $id,
            label: $label,
            extends: $extends,
            nodes: $nodes,
            linkStyle: $style,
            escaper: new Escaper($mechanism, is_string($escape['chars'] ?? null) ? $escape['chars'] : ''),
            messageLimit: $messageLimit,
            output: $output,
            offsetUnit: $offsetUnit,
            data: $data,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function extendsId(): ?string
    {
        return $this->extends;
    }

    public function supports(string $nodeType): bool
    {
        return ($this->nodes[$nodeType]['support'] ?? self::SUPPORT_NONE) === self::SUPPORT_NATIVE;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function emission(string $nodeType): ?array
    {
        return $this->nodes[$nodeType] ?? null;
    }

    public function fallback(string $nodeType): string
    {
        $config = $this->nodes[$nodeType] ?? [];

        return is_string($config['fallback'] ?? null) ? $config['fallback'] : Fallback::Unwrap->value;
    }

    public function fallbackEnum(string $nodeType): Fallback
    {
        return Fallback::from($this->fallback($nodeType));
    }

    public function linkStyle(): LinkStyle
    {
        return $this->linkStyle;
    }

    public function escaper(): Escaper
    {
        return $this->escaper;
    }

    public function messageLimit(): ?int
    {
        return $this->messageLimit;
    }

    public function output(): OutputMode
    {
        return $this->output;
    }

    public function offsetUnit(): OffsetUnit
    {
        return $this->offsetUnit;
    }

    /**
     * The style name a range-based target uses for this node, e.g. Signal's
     * `BOLD` or Telegram's `bold`. Null when the node carries no style.
     */
    public function styleFor(string $nodeType): ?string
    {
        $style = $this->nodes[$nodeType]['style'] ?? null;

        return is_string($style) && $style !== '' ? $style : null;
    }

    /**
     * @return array<string>
     */
    public function supportedTypes(): array
    {
        return array_keys(array_filter($this->nodes, static fn (array $node): bool => ($node['support'] ?? '') === self::SUPPORT_NATIVE));
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $child
     *
     * @return array<string, mixed>
     */
    private static function mergeParent(array $parent, array $child): array
    {
        $merged = array_replace_recursive($parent, $child);
        $parentNodes = isset($parent['nodes']) && is_array($parent['nodes']) ? $parent['nodes'] : [];
        $childNodes = isset($child['nodes']) && is_array($child['nodes']) ? $child['nodes'] : [];

        // A declared node entry replaces the parent's outright rather than
        // merging into it. Merging meant an override had to null out every key
        // the parent happened to set - redeclaring `open`/`close` did not
        // dislodge an inherited `template`, so the parent's markup leaked
        // through. Node entries are small and self-contained; state one fully.
        $merged['nodes'] = array_replace($parentNodes, $childNodes);

        return $merged;
    }

    /**
     * @return array<int, string>
     */
    public static function knownNodeTypes(): array
    {
        return [
            'abbreviation',
            'abbreviation_def',
            'admonition',
            'autolink',
            'block_extension',
            'block_quote',
            'caption',
            'caption_number',
            'cell_paragraph',
            'citation',
            'citation_definition',
            'citation_group',
            'code',
            'code_block',
            'comment',
            'critic_comment',
            'definition_description',
            'definition_list',
            'definition_term',
            'delete',
            'directive',
            'div',
            'document',
            'emphasis',
            'figure_group',
            'footnote',
            'footnote_ref',
            'frontmatter',
            'hard_break',
            'heading',
            'highlight',
            'image',
            'include',
            'inline_extension',
            'inline_footnote',
            'insert',
            'line',
            'line_block',
            'link',
            'link_reference_definition',
            'list',
            'list_item',
            'literal_inline',
            'math',
            'mention',
            'non_breaking_space',
            'paragraph',
            'raw_block',
            'raw_inline',
            'ruby',
            'section',
            'small_caps',
            'smart_punctuation',
            'soft_break',
            'span',
            'strike',
            'strong',
            'subscript',
            'superscript',
            'symbol',
            'table',
            'table_body',
            'table_cell',
            'table_column',
            'table_foot',
            'table_head',
            'table_row',
            'tag',
            'text',
            'thematic_break',
            'underline',
            'xref',
            'ruby_pair',
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param string $key
     *
     * @throws \MarkupCarve\Chat\Exception\InvalidFlavorException
     */
    private static function stringValue(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
            throw new InvalidFlavorException(sprintf('Flavor field "%s" must be a non-empty string.', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     * @param string $key
     *
     * @return array<string, mixed>
     */
    private static function arrayValue(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return [];
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \MarkupCarve\Chat\Exception\InvalidFlavorException
     *
     * @return array<string, array<string, mixed>>
     */
    private static function nodesValue(array $data): array
    {
        if (!isset($data['nodes']) || !is_array($data['nodes'])) {
            throw new InvalidFlavorException('Flavor field "nodes" must be an object.');
        }

        $nodes = [];
        foreach ($data['nodes'] as $nodeType => $config) {
            if (!is_string($nodeType) || !is_array($config)) {
                throw new InvalidFlavorException('Flavor nodes must be keyed objects.');
            }
            $nodes[$nodeType] = $config;
        }

        return $nodes;
    }
}
