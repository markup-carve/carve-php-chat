<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

use MarkupCarve\Carve\NodeType;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Chat\Exception\InvalidFlavorException;
use ReflectionClass;

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
            $nodes[NodeType::LINK]['support'] = self::SUPPORT_NATIVE;
        }

        foreach ($nodes as $nodeType => $config) {
            if (!in_array($nodeType, self::knownNodeTypes(), true)) {
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

    public function toProfile(): Profile
    {
        $inline = [];
        foreach (NodeType::allInlineTypes() as $type) {
            if ($this->supports($type)) {
                $inline[] = $type;
            }
        }

        $block = [];
        foreach (NodeType::allBlockTypes() as $type) {
            if ($this->supports($type)) {
                $block[] = $type;
            }
        }

        return (new Profile())->allowInline($inline)->allowBlock($block);
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
        $merged['nodes'] = array_replace_recursive($parentNodes, $childNodes);

        return $merged;
    }

    /**
     * @return array<int, string>
     */
    private static function knownNodeTypes(): array
    {
        $values = [];
        foreach ((new ReflectionClass(NodeType::class))->getConstants() as $value) {
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
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
