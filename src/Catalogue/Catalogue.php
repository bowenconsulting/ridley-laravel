<?php

declare(strict_types=1);

namespace Ridley\Laravel\Catalogue;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ridley\Laravel\Exceptions\PriceNotFound;
use Ridley\Laravel\Price;
use Ridley\Laravel\Resources\Price as RidleyPrice;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\Support\Money;

/**
 * Maps the app's price definitions onto Ridley prices, mirrored in the local prices table.
 */
class Catalogue
{
    public const string GROUP_METADATA_KEY = 'ridley_laravel_group';

    public const string ITEM_METADATA_KEY = 'ridley_laravel_item';

    /**
     * All definitions, keyed by lookup key: config first, then the runtime resolver (which wins).
     *
     * @return Collection<string, PriceDefinition>
     */
    public function definitions(): Collection
    {
        $definitions = collect();

        foreach ($this->configDefinitions() as $definition) {
            $definitions->put($definition->lookupKey, $definition);
        }

        $resolver = Ridley::catalogueResolver();

        if ($resolver !== null) {
            foreach ($resolver() as $definition) {
                $definition = $definition instanceof PriceDefinition ? $definition : PriceDefinition::fromArray($definition);

                $definitions->put($definition->lookupKey, $definition);
            }
        }

        return $definitions;
    }

    /**
     * Push the definitions to Ridley and mirror them locally. Ridley prices are never
     * deactivated; replaced prices stay locally with is_current = false.
     *
     * @return list<SyncResult>
     */
    public function sync(bool $dryRun = false): array
    {
        $results = [];

        foreach ($this->definitions() as $definition) {
            $results[] = $this->syncDefinition($definition, $dryRun);
        }

        return $results;
    }

    /**
     * Definitions whose local mirror is missing or differs (no API calls).
     *
     * @return list<array{lookup_key: string, reason: string}>
     */
    public function drift(): array
    {
        $drift = [];

        foreach ($this->definitions() as $definition) {
            $local = $this->priceQuery()->where('lookup_key', $definition->lookupKey)->where('is_current', true)->latest('id')->first();

            if ($local === null) {
                $drift[] = ['lookup_key' => $definition->lookupKey, 'reason' => 'not synced'];
            } elseif (! $this->localMatches($local, $definition)) {
                $drift[] = ['lookup_key' => $definition->lookupKey, 'reason' => 'amount or interval changed'];
            }
        }

        return $drift;
    }

    /**
     * The current price for a catalogue item.
     */
    public function price(string $group, string $itemKey, string $currency, string $interval, int $intervalCount = 1): ?Price
    {
        return $this->priceQuery()
            ->where('group', $group)
            ->where('item_key', $itemKey)
            ->where('currency', strtoupper($currency))
            ->where('interval', $interval)
            ->where('interval_count', $intervalCount)
            ->where('is_current', true)
            ->latest('id')
            ->first();
    }

    /**
     * @throws PriceNotFound
     */
    public function priceOrFail(string $group, string $itemKey, string $currency, string $interval, int $intervalCount = 1): Price
    {
        return $this->price($group, $itemKey, $currency, $interval, $intervalCount)
            ?? throw PriceNotFound::for($group, $itemKey, $currency, $interval, $intervalCount);
    }

    /**
     * A local price by Ridley ID, current or retired.
     */
    public function findByRidleyId(?string $id): ?Price
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->priceQuery()->where('ridley_price_id', $id)->first();
    }

    /**
     * Current prices, optionally filtered.
     *
     * @return Collection<int, Price>
     */
    public function prices(?string $group = null, ?string $currency = null, ?string $interval = null): Collection
    {
        return $this->priceQuery()
            ->where('is_current', true)
            ->when($group !== null, fn ($query) => $query->where('group', $group))
            ->when($currency !== null, fn ($query) => $query->where('currency', strtoupper((string) $currency)))
            ->when($interval !== null, fn ($query) => $query->where('interval', $interval))
            ->orderBy('group')
            ->orderBy('unit_amount')
            ->get();
    }

    /**
     * Resolve a selection into swap shape: `[ridleyPriceId => ['quantity' => n]]`.
     * Items with a quantity of 0 or less are skipped; duplicates are merged.
     *
     * @param  list<array{group: string, key: string, quantity?: int|null}>  $items
     * @return array<string, array{quantity: int}>
     *
     * @throws PriceNotFound
     */
    public function resolve(string $currency, string $interval, array $items, int $intervalCount = 1): array
    {
        $resolved = [];

        foreach ($items as $item) {
            $quantity = (int) ($item['quantity'] ?? 1);

            if ($quantity <= 0) {
                continue;
            }

            $price = $this->priceOrFail((string) $item['group'], (string) $item['key'], $currency, $interval, $intervalCount);

            $resolved[$price->ridley_price_id] = ['quantity' => ($resolved[$price->ridley_price_id]['quantity'] ?? 0) + $quantity];
        }

        return $resolved;
    }

    /**
     * @return list<PriceDefinition>
     */
    protected function configDefinitions(): array
    {
        $config = config('ridley.catalogue', []);

        if (! is_array($config)) {
            return [];
        }

        $definitions = [];
        $flat = $config['prices'] ?? null;

        if (is_array($flat) && array_is_list($flat)) {
            foreach ($flat as $attributes) {
                if (is_array($attributes)) {
                    $definitions[] = PriceDefinition::fromArray($attributes);
                }
            }

            unset($config['prices']);
        }

        return array_merge($definitions, PriceDefinition::fromGroupedArray($config));
    }

    protected function syncDefinition(PriceDefinition $definition, bool $dryRun): SyncResult
    {
        $remote = Ridley::client()->prices()->findByLookupKey($definition->lookupKey);

        if ($remote !== null && $this->remoteMatches($remote, $definition)) {
            if (! $dryRun) {
                $this->record($definition, (string) $remote->id(), is_string($remote->product) ? $remote->product : null);
            }

            return new SyncResult($definition->lookupKey, $definition->decimalAmount(), SyncResult::UNCHANGED, $remote->id());
        }

        if ($dryRun) {
            return new SyncResult($definition->lookupKey, $definition->decimalAmount(), $remote !== null ? SyncResult::WOULD_REPLACE : SyncResult::WOULD_CREATE, $remote?->id());
        }

        $productId = is_string($remote?->product) && $remote->product !== '' ? $remote->product : $this->productFor($definition);

        $price = Ridley::client()->prices()->create(array_filter([
            'product' => $productId,
            'type' => 'recurring',
            'currency' => $definition->currency,
            'unit_amount' => $definition->decimalAmount(),
            'interval' => $definition->interval,
            'interval_count' => $definition->intervalCount,
            'lookup_key' => $definition->lookupKey,
            'transfer_lookup_key' => true,
            'nickname' => $definition->nickname,
            'metadata' => $this->ridleyMetadata($definition),
        ], fn ($value) => $value !== null));

        $this->record($definition, (string) $price->id(), $productId);

        return new SyncResult($definition->lookupKey, $definition->decimalAmount(), $remote !== null ? SyncResult::REPLACED : SyncResult::CREATED, $price->id());
    }

    protected function productFor(PriceDefinition $definition): string
    {
        $local = $this->priceQuery()
            ->where('group', $definition->group)
            ->where('item_key', $definition->itemKey)
            ->whereNotNull('ridley_product_id')
            ->latest('id')
            ->first();

        if ($local !== null && $local->ridley_product_id !== null) {
            return $local->ridley_product_id;
        }

        $existing = Ridley::client()->products()->list([
            'filter' => ['metadata' => [
                self::GROUP_METADATA_KEY => $definition->group,
                self::ITEM_METADATA_KEY => $definition->itemKey,
            ]],
            'page' => ['size' => 1],
        ])->data()->first();

        if ($existing !== null && $existing->id() !== null) {
            return $existing->id();
        }

        return (string) Ridley::client()->products()->create([
            'name' => $definition->productName,
            'metadata' => $this->ridleyMetadata($definition),
        ])->id();
    }

    /**
     * Ridley metadata values must be scalars of at most 500 characters, so features
     * and limits are JSON-encoded (and left out when too long).
     *
     * @return array<string, scalar|null>
     */
    protected function ridleyMetadata(PriceDefinition $definition): array
    {
        $metadata = array_filter($definition->metadata, fn ($value) => $value === null || is_scalar($value));

        $metadata[self::GROUP_METADATA_KEY] = $definition->group;
        $metadata[self::ITEM_METADATA_KEY] = $definition->itemKey;

        foreach (['ridley_laravel_features' => $definition->features, 'ridley_laravel_limits' => $definition->limits] as $key => $value) {
            $encoded = $value === [] ? null : json_encode($value);

            if (is_string($encoded) && strlen($encoded) <= 500) {
                $metadata[$key] = $encoded;
            }
        }

        return $metadata;
    }

    protected function remoteMatches(RidleyPrice $remote, PriceDefinition $definition): bool
    {
        return Money::toMinor($remote->unit_amount) === $definition->unitAmount
            && strtoupper((string) $remote->currency) === $definition->currency
            && (string) $remote->interval === $definition->interval
            && (int) ($remote->interval_count ?? 1) === $definition->intervalCount;
    }

    protected function localMatches(Price $local, PriceDefinition $definition): bool
    {
        return $local->unit_amount === $definition->unitAmount
            && $local->currency === $definition->currency
            && $local->interval === $definition->interval
            && $local->interval_count === $definition->intervalCount;
    }

    protected function record(PriceDefinition $definition, string $ridleyPriceId, ?string $productId): void
    {
        DB::connection($this->newPrice()->getConnectionName())->transaction(function () use ($definition, $ridleyPriceId, $productId) {
            $this->priceQuery()->updateOrCreate(['ridley_price_id' => $ridleyPriceId], [
                'ridley_product_id' => $productId,
                'lookup_key' => $definition->lookupKey,
                'group' => $definition->group,
                'item_key' => $definition->itemKey,
                'currency' => $definition->currency,
                'interval' => $definition->interval,
                'interval_count' => $definition->intervalCount,
                'unit_amount' => $definition->unitAmount,
                'is_current' => true,
                'max_quantity' => $definition->maxQuantity,
                'features' => $definition->features,
                'limits' => $definition->limits,
                'metadata' => $definition->metadata === [] ? null : $definition->metadata,
            ]);

            $this->priceQuery()
                ->where('lookup_key', $definition->lookupKey)
                ->where('ridley_price_id', '!=', $ridleyPriceId)
                ->update(['is_current' => false]);
        });
    }

    /**
     * @return Builder<Price>
     */
    protected function priceQuery(): Builder
    {
        return $this->newPrice()->newQuery();
    }

    protected function newPrice(): Price
    {
        $model = Ridley::priceModel();

        return new $model;
    }
}
