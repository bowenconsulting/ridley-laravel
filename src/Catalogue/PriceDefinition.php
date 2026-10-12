<?php

declare(strict_types=1);

namespace Ridley\Laravel\Catalogue;

use InvalidArgumentException;
use Ridley\Laravel\Support\Money;

/**
 * A recurring price to create on Ridley with `ridley:push-catalogue` (a one-off bootstrap;
 * afterwards manage prices in Ridley and pull them with `ridley:sync-catalogue`).
 */
final readonly class PriceDefinition
{
    public string $currency;

    public string $lookupKey;

    /**
     * @param  int  $unitAmount  Minor units (e.g. 4900 = 49.00).
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $features
     * @param  array<string, int|null>  $limits  `null` = unlimited.
     */
    public function __construct(
        public string $group,
        public string $itemKey,
        public string $productName,
        string $currency,
        public string $interval,
        public int $unitAmount,
        public int $intervalCount = 1,
        public ?string $nickname = null,
        public array $metadata = [],
        ?string $lookupKey = null,
        public array $features = [],
        public array $limits = [],
        public ?int $maxQuantity = null,
    ) {
        if ($group === '' || $itemKey === '') {
            throw new InvalidArgumentException('Catalogue prices need a group and an item key.');
        }

        if ($unitAmount < 0 || $intervalCount < 1) {
            throw new InvalidArgumentException("Invalid amount or interval count for catalogue price [{$group}.{$itemKey}].");
        }

        $this->currency = strtoupper($currency);
        $this->lookupKey = $lookupKey ?? self::defaultLookupKey($group, $itemKey, $currency, $interval, $intervalCount);
    }

    public static function defaultLookupKey(string $group, string $itemKey, string $currency, string $interval, int $intervalCount = 1): string
    {
        return LookupKey::make($group, $itemKey, $currency, $interval, $intervalCount);
    }

    /**
     * Build from a flat array: group, item_key, product_name (or name), currency, interval,
     * interval_count, unit_amount (minor units) or amount (decimal string), nickname,
     * metadata, lookup_key, features, limits, max_quantity.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        foreach (['group', 'item_key', 'currency', 'interval'] as $required) {
            if (! isset($attributes[$required]) || $attributes[$required] === '') {
                throw new InvalidArgumentException("Catalogue price definitions require [{$required}].");
            }
        }

        if (isset($attributes['unit_amount'])) {
            $unitAmount = (int) $attributes['unit_amount'];
        } elseif (isset($attributes['amount'])) {
            $unitAmount = Money::toMinor($attributes['amount']);
        } else {
            throw new InvalidArgumentException('Catalogue price definitions require [unit_amount] (minor units) or [amount] (decimal).');
        }

        return new self(
            group: (string) $attributes['group'],
            itemKey: (string) $attributes['item_key'],
            productName: (string) ($attributes['product_name'] ?? $attributes['name'] ?? $attributes['item_key']),
            currency: (string) $attributes['currency'],
            interval: (string) $attributes['interval'],
            unitAmount: $unitAmount,
            intervalCount: (int) ($attributes['interval_count'] ?? 1),
            nickname: isset($attributes['nickname']) ? (string) $attributes['nickname'] : null,
            metadata: is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [],
            lookupKey: isset($attributes['lookup_key']) ? (string) $attributes['lookup_key'] : null,
            features: is_array($attributes['features'] ?? null) ? array_values(array_map('strval', $attributes['features'])) : [],
            limits: is_array($attributes['limits'] ?? null) ? array_map(fn ($limit) => $limit === null ? null : (int) $limit, $attributes['limits']) : [],
            maxQuantity: isset($attributes['max_quantity']) ? (int) $attributes['max_quantity'] : null,
        );
    }

    /**
     * Expand the grouped config shape
     * `[group => [key => ['name' => …, 'prices' => [[currency, interval, amount], …], 'features' => […], 'limits' => […]]]]`.
     *
     * @param  array<string, mixed>  $groups
     * @return list<self>
     */
    public static function fromGroupedArray(array $groups): array
    {
        $definitions = [];

        foreach ($groups as $group => $items) {
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $itemKey => $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (is_array($item['prices'] ?? null) ? $item['prices'] : [] as $price) {
                    if (! is_array($price)) {
                        continue;
                    }

                    $definitions[] = self::fromArray(array_merge([
                        'group' => (string) $group,
                        'item_key' => (string) $itemKey,
                        'product_name' => $item['name'] ?? $item['product_name'] ?? null,
                        'features' => $item['features'] ?? [],
                        'limits' => $item['limits'] ?? [],
                        'max_quantity' => $item['max_quantity'] ?? null,
                        'metadata' => $item['metadata'] ?? [],
                    ], array_filter($price, fn ($value) => $value !== null)));
                }
            }
        }

        return $definitions;
    }

    /**
     * The amount as a Ridley decimal string.
     */
    public function decimalAmount(): string
    {
        return Money::fromMinor($this->unitAmount);
    }
}
