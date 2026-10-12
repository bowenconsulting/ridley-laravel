<?php

declare(strict_types=1);

namespace Ridley\Laravel\Catalogue;

use InvalidArgumentException;

/**
 * Something the app sells (a plan or an add-on) and what it grants. Prices live in
 * Ridley and are matched to items by lookup key.
 */
final readonly class ItemDefinition
{
    /**
     * @param  list<string>  $features
     * @param  array<string, int|null>  $limits  `null` = unlimited; multiplied by quantity.
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $group,
        public string $key,
        public ?string $name = null,
        public array $features = [],
        public array $limits = [],
        public ?int $maxQuantity = null,
        public array $metadata = [],
    ) {
        if ($group === '' || $key === '') {
            throw new InvalidArgumentException('Catalogue items need a group and a key.');
        }
    }

    /**
     * Build from an array: group, key (or item_key), name, features, limits, max_quantity, metadata.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            group: (string) ($attributes['group'] ?? ''),
            key: (string) ($attributes['key'] ?? $attributes['item_key'] ?? ''),
            name: isset($attributes['name']) ? (string) $attributes['name'] : null,
            features: is_array($attributes['features'] ?? null) ? array_values(array_map('strval', $attributes['features'])) : [],
            limits: is_array($attributes['limits'] ?? null) ? array_map(fn ($limit) => $limit === null ? null : (int) $limit, $attributes['limits']) : [],
            maxQuantity: isset($attributes['max_quantity']) ? (int) $attributes['max_quantity'] : null,
            metadata: is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [],
        );
    }

    /**
     * Expand the grouped config shape `[group => [key => [name, features, limits, max_quantity]]]`.
     * Any `prices` on an item are ignored here (see PriceDefinition).
     *
     * @param  array<string, mixed>  $groups
     * @return list<self>
     */
    public static function fromGroupedArray(array $groups): array
    {
        $items = [];

        foreach ($groups as $group => $groupItems) {
            if (! is_array($groupItems) || array_is_list($groupItems)) {
                continue;
            }

            foreach ($groupItems as $key => $item) {
                if (is_array($item)) {
                    $items[] = self::fromArray(['group' => (string) $group, 'key' => (string) $key] + $item);
                }
            }
        }

        return $items;
    }

    public function id(): string
    {
        return "{$this->group}.{$this->key}";
    }
}
