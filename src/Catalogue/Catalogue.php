<?php

declare(strict_types=1);

namespace Ridley\Laravel\Catalogue;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ridley\Laravel\Events\CatalogueSynced;
use Ridley\Laravel\Exceptions\NotFoundException;
use Ridley\Laravel\Exceptions\PriceNotFound;
use Ridley\Laravel\Price;
use Ridley\Laravel\Resources\Price as RidleyPrice;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\Support\Money;

/**
 * The app's catalogue: items (what's sold and what it grants) defined in the app, priced
 * on Ridley and mirrored into the local prices table by lookup key.
 */
class Catalogue
{
    public const string GROUP_METADATA_KEY = 'ridley_laravel_group';

    public const string ITEM_METADATA_KEY = 'ridley_laravel_item';

    /**
     * Push definitions (for `ridley:push-catalogue`), keyed by lookup key: config first,
     * then Ridley::defineCatalogueUsing() (which wins).
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
     * The items the app sells, keyed `group.key`: from push definitions, then config
     * `ridley.catalogue`, then Ridley::defineItemsUsing() (which wins).
     *
     * @return Collection<string, ItemDefinition>
     */
    public function items(): Collection
    {
        $items = collect();

        foreach ($this->definitions() as $definition) {
            $items->put("{$definition->group}.{$definition->itemKey}", new ItemDefinition(
                $definition->group,
                $definition->itemKey,
                $definition->productName,
                $definition->features,
                $definition->limits,
                $definition->maxQuantity,
                $definition->metadata,
            ));
        }

        $config = config('ridley.catalogue', []);

        foreach (is_array($config) ? ItemDefinition::fromGroupedArray($config) : [] as $item) {
            $items->put($item->id(), $item);
        }

        $resolver = Ridley::itemsResolver();

        if ($resolver !== null) {
            foreach ($resolver() as $item) {
                $item = $item instanceof ItemDefinition ? $item : ItemDefinition::fromArray($item);

                $items->put($item->id(), $item);
            }
        }

        return $items;
    }

    public function item(string $group, string $key): ?ItemDefinition
    {
        return $this->items()->get("{$group}.{$key}");
    }

    /**
     * Pull this app's prices from Ridley. Active recurring prices whose lookup key follows
     * the convention (see LookupKey) become current locally; local prices Ridley no longer
     * sells are retired but kept, so existing subscribers stay mapped to their item.
     *
     * @return list<SyncResult>
     */
    public function sync(): array
    {
        $items = $this->items();
        $results = [];
        $current = [];

        $remotePrices = Ridley::client()->prices()->all([
            'filter' => ['active' => 'true', 'type' => 'recurring'],
            'page' => ['size' => 100],
        ]);

        foreach ($remotePrices as $remote) {
            $result = $this->syncPrice($remote, $items, announce: false);

            if ($result === null) {
                continue;
            }

            $results[] = $result;

            if (in_array($result->action, [SyncResult::IMPORTED, SyncResult::UPDATED, SyncResult::UNCHANGED], true)) {
                $current[] = (string) $result->ridleyPriceId;
            }
        }

        $stale = $this->priceQuery()->where('is_current', true)->whereNotIn('ridley_price_id', $current)->get();

        foreach ($stale as $price) {
            if (collect($results)->contains(fn (SyncResult $result) => $result->ridleyPriceId === $price->ridley_price_id)) {
                continue;
            }

            $price->forceFill(['is_current' => false])->save();
            $results[] = new SyncResult($price->lookup_key ?? $price->ridley_price_id, $price->decimalAmount(), SyncResult::RETIRED, $price->ridley_price_id, 'no longer an active price on Ridley');
        }

        $this->applyItems($items);

        event(new CatalogueSynced($results));

        return $results;
    }

    /**
     * Mirror one Ridley price (from the API or a `price.*` webhook). Returns null when the
     * price isn't part of this app's catalogue and nothing changed locally.
     *
     * @param  Collection<string, ItemDefinition>|null  $items
     */
    public function syncPrice(RidleyPrice $remote, ?Collection $items = null, bool $announce = true): ?SyncResult
    {
        $items ??= $this->items();
        $id = (string) $remote->id();
        $local = $this->findByRidleyId($id);
        $lookupKey = is_string($remote->lookup_key) && $remote->lookup_key !== '' ? $remote->lookup_key : null;
        $amount = Money::decimal($remote->unit_amount ?? 0);
        $sellable = $remote->active !== false && $remote->isRecurring();
        $parsed = $sellable ? LookupKey::parse($lookupKey) : null;
        $item = $parsed !== null ? $items->get("{$parsed['group']}.{$parsed['item_key']}") : null;

        $problem = match (true) {
            ! $sellable => 'no longer an active recurring price on Ridley',
            $parsed === null => LookupKey::belongsToApp($lookupKey) ? 'lookup key must look like '.LookupKey::make('{group}', '{item}', '{ccy}', '{interval}') : 'lookup key removed or moved',
            $parsed['currency'] !== strtoupper((string) $remote->currency) || $parsed['interval'] !== (string) $remote->interval || $parsed['interval_count'] !== (int) ($remote->interval_count ?? 1) => 'lookup key does not match the price currency or interval',
            $item === null && $items->isNotEmpty() => "no item [{$parsed['group']}.{$parsed['item_key']}] is defined in the app",
            default => null,
        };

        if ($problem !== null) {
            $result = null;

            if ($local?->is_current) {
                $local->forceFill(['is_current' => false])->save();
                $result = new SyncResult($lookupKey ?? $local->lookup_key ?? $id, $amount, SyncResult::RETIRED, $id, $problem);
            } elseif ($sellable && LookupKey::belongsToApp($lookupKey)) {
                $result = new SyncResult((string) $lookupKey, $amount, SyncResult::SKIPPED, $id, $problem);
            }

            if ($result !== null && $announce) {
                event(new CatalogueSynced([$result]));
            }

            return $result;
        }

        /** @var array{group: string, item_key: string, currency: string, interval: string, interval_count: int} $parsed */
        $attributes = [
            'ridley_product_id' => is_string($remote->product) && $remote->product !== '' ? $remote->product : $local?->ridley_product_id,
            'lookup_key' => $lookupKey,
            'group' => $parsed['group'],
            'item_key' => $parsed['item_key'],
            'currency' => $parsed['currency'],
            'interval' => $parsed['interval'],
            'interval_count' => $parsed['interval_count'],
            'unit_amount' => Money::toMinor($amount),
            'is_current' => true,
            'max_quantity' => $item?->maxQuantity,
            'features' => $item === null ? [] : $item->features,
            'limits' => $item === null ? [] : $item->limits,
            'metadata' => is_array($remote->metadata) && $remote->metadata !== [] ? $remote->metadata : null,
        ];

        $action = match (true) {
            $local === null => SyncResult::IMPORTED,
            ! $local->is_current || $local->lookup_key !== $lookupKey || $local->group !== $parsed['group'] || $local->item_key !== $parsed['item_key'] => SyncResult::UPDATED,
            default => SyncResult::UNCHANGED,
        };

        DB::connection($this->newPrice()->getConnectionName())->transaction(function () use ($id, $attributes, $lookupKey) {
            $this->priceQuery()->updateOrCreate(['ridley_price_id' => $id], $attributes);

            $this->priceQuery()
                ->where('ridley_price_id', '!=', $id)
                ->where('is_current', true)
                ->where(fn (Builder $query) => $query
                    ->where('lookup_key', $lookupKey)
                    ->orWhere(fn (Builder $slot) => $slot
                        ->where('group', $attributes['group'])
                        ->where('item_key', $attributes['item_key'])
                        ->where('currency', $attributes['currency'])
                        ->where('interval', $attributes['interval'])
                        ->where('interval_count', $attributes['interval_count'])))
                ->update(['is_current' => false]);
        });

        $result = new SyncResult((string) $lookupKey, $amount, $action, $id);

        if ($announce && $result->changed()) {
            event(new CatalogueSynced([$result]));
        }

        return $result;
    }

    /**
     * Copy each item's features, limits and max quantity onto its local prices, current
     * and retired, so changing what an item grants reaches existing subscribers too.
     *
     * @param  Collection<string, ItemDefinition>|null  $items
     */
    public function applyItems(?Collection $items = null): void
    {
        foreach ($items ?? $this->items() as $item) {
            $this->priceQuery()
                ->where('group', $item->group)
                ->where('item_key', $item->key)
                ->update([
                    'features' => json_encode($item->features),
                    'limits' => json_encode((object) $item->limits),
                    'max_quantity' => $item->maxQuantity,
                ]);
        }
    }

    /**
     * Create the price definitions on Ridley: a bootstrap for a fresh account. Lookup keys
     * Ridley already prices differently are left alone unless $replace is true (which
     * creates a new price and takes the lookup key over). Pulls the catalogue afterwards.
     *
     * @return list<SyncResult>
     */
    public function push(bool $dryRun = false, bool $replace = false): array
    {
        $results = [];

        foreach ($this->definitions() as $definition) {
            $results[] = $this->pushDefinition($definition, $dryRun, $replace);
        }

        if (! $dryRun && $results !== []) {
            $this->sync();
        }

        return $results;
    }

    /**
     * Defined items with no current price (no API calls).
     *
     * @return list<array{item: string, reason: string}>
     */
    public function drift(): array
    {
        $drift = [];

        foreach ($this->items() as $item) {
            $priced = $this->priceQuery()->where('group', $item->group)->where('item_key', $item->key)->where('is_current', true)->exists();

            if (! $priced) {
                $drift[] = ['item' => $item->id(), 'reason' => 'no current price; add one on Ridley with a lookup key like '.LookupKey::make($item->group, $item->key, (string) config('ridley.currency', 'USD'), 'month')];
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

    protected function pushDefinition(PriceDefinition $definition, bool $dryRun, bool $replace): SyncResult
    {
        $remote = Ridley::client()->prices()->findByLookupKey($definition->lookupKey);

        if ($remote !== null && $this->remoteMatches($remote, $definition)) {
            if (! $dryRun) {
                $this->record($definition, (string) $remote->id(), is_string($remote->product) ? $remote->product : null);
            }

            return new SyncResult($definition->lookupKey, $definition->decimalAmount(), SyncResult::UNCHANGED, $remote->id());
        }

        if ($remote !== null && ! $replace) {
            return new SyncResult($definition->lookupKey, $definition->decimalAmount(), SyncResult::DIFFERS, $remote->id(), 'Ridley has '.Money::decimal($remote->unit_amount ?? 0).' '.strtoupper((string) $remote->currency).'; manage it on Ridley, or push with --replace');
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

        // The cached product may belong to the other mode (a test key's product after switching to a live key)
        if ($local !== null && $local->ridley_product_id !== null && $this->productExists($local->ridley_product_id)) {
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

    protected function productExists(string $productId): bool
    {
        try {
            Ridley::client()->products()->retrieve($productId);

            return true;
        } catch (NotFoundException) {
            return false;
        }
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
