<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

use JsonException;
use MarkupCarve\Chat\Exception\InvalidFlavorException;

final class FlavorRegistry
{
    private string $bundledDir;

    /**
     * @var array<string, \MarkupCarve\Chat\ChatFlavor>
     */
    private array $flavors = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $definitions = [];

    public function __construct(?string $bundledDir = null)
    {
        $this->bundledDir = $bundledDir ?? dirname(__DIR__) . '/resources/flavors';
        $this->loadDefinitions();
    }

    public function get(string $id): ChatFlavor
    {
        return $this->resolve($id, []);
    }

    public function has(string $id): bool
    {
        return isset($this->flavors[$id]) || isset($this->definitions[$id]);
    }

    public function register(ChatFlavor $flavor): void
    {
        $this->flavors[$flavor->id()] = $flavor;
    }

    /**
     * Loads a flavor definition from disk.
     *
     * The definition is registered and then resolved through the same path as a
     * bundled flavor, so `extends` (and cycle detection) behave identically for
     * custom flavors. A bundled flavor gets no privileged code path.
     */
    public function fromJsonFile(string $path): ChatFlavor
    {
        $data = $this->readDefinition($path);
        $id = isset($data['id']) && is_string($data['id']) && $data['id'] !== ''
            ? $data['id']
            : pathinfo($path, PATHINFO_FILENAME);

        $this->definitions[$id] = $data;
        unset($this->flavors[$id]);

        return $this->resolve($id, []);
    }

    /**
     * @return array<string>
     */
    public function ids(): array
    {
        $ids = array_values(array_unique(array_merge(array_keys($this->definitions), array_keys($this->flavors))));
        sort($ids);

        return $ids;
    }

    /**
     * @param string $id
     * @param array<string> $stack
     *
     * @throws \MarkupCarve\Chat\Exception\InvalidFlavorException
     */
    private function resolve(string $id, array $stack): ChatFlavor
    {
        if (isset($this->flavors[$id])) {
            return $this->flavors[$id];
        }
        if (!isset($this->definitions[$id])) {
            throw new InvalidFlavorException(sprintf('Unknown flavor "%s".', $id));
        }
        if (in_array($id, $stack, true)) {
            throw new InvalidFlavorException(sprintf('Flavor extends cycle detected at "%s".', $id));
        }

        $data = $this->definitions[$id];
        $parent = null;
        if (isset($data['extends']) && is_string($data['extends']) && $data['extends'] !== '') {
            $parent = $this->resolve($data['extends'], [...$stack, $id]);
        }

        $flavor = ChatFlavor::fromArray($data, $parent);
        $this->flavors[$id] = $flavor;

        return $flavor;
    }

    private function loadDefinitions(): void
    {
        $paths = glob($this->bundledDir . '/*.json') ?: [];
        foreach ($paths as $path) {
            $data = $this->readDefinition($path);
            $id = isset($data['id']) && is_string($data['id']) ? $data['id'] : pathinfo($path, PATHINFO_FILENAME);
            $this->definitions[$id] = $data;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readDefinition(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new InvalidFlavorException(sprintf('Unable to read flavor file "%s".', $path));
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidFlavorException(sprintf('Invalid JSON in flavor file "%s".', $path), 0, $exception);
        }

        if (!is_array($data)) {
            throw new InvalidFlavorException(sprintf('Flavor file "%s" must contain a JSON object.', $path));
        }

        return $data;
    }
}
