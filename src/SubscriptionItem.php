<?php

declare(strict_types=1);

namespace Ridley\Laravel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ridley\Laravel\Support\PriceResolver;

/**
 * @property int $id
 * @property int $subscription_id
 * @property string $ridley_id
 * @property string $ridley_price
 * @property string|null $ridley_product
 * @property int|null $quantity
 * @property array<string, mixed>|null $metadata
 * @property-read Subscription $subscription
 * @property-read Price|null $price
 */
class SubscriptionItem extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function getTable(): string
    {
        return (string) config('ridley.tables.subscription_items', 'ridley_subscription_items');
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Ridley::subscriptionModel(), 'subscription_id');
    }

    /**
     * The local catalogue price for this item, when it was synced through the catalogue.
     *
     * @return BelongsTo<Price, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Ridley::priceModel(), 'ridley_price', 'ridley_price_id');
    }

    /**
     * Alias of {@see price()}.
     *
     * @return BelongsTo<Price, $this>
     */
    public function catalogPrice(): BelongsTo
    {
        return $this->price();
    }

    /**
     * The catalogue group of the item's price (e.g. `plan`, `addon`), or null when unknown.
     */
    public function group(): ?string
    {
        return $this->price?->group;
    }

    /**
     * The catalogue item key of the item's price (e.g. `professional`), or null when unknown.
     */
    public function itemKey(): ?string
    {
        return $this->price?->item_key;
    }

    public function incrementQuantity(int $count = 1): Subscription
    {
        return $this->subscription->incrementQuantity($count, $this->ridley_price);
    }

    public function decrementQuantity(int $count = 1): Subscription
    {
        return $this->subscription->decrementQuantity($count, $this->ridley_price);
    }

    public function updateQuantity(int $quantity): Subscription
    {
        return $this->subscription->updateQuantity($quantity, $this->ridley_price);
    }

    /**
     * Swap this item to another price, keeping the item.
     *
     * @param  array<string, mixed>  $options  Item options (`quantity`, `metadata`) and `proration_behavior`.
     */
    public function swap(string|Price $price, array $options = []): Subscription
    {
        $item = array_filter([
            'id' => $this->ridley_id,
            'price' => PriceResolver::resolve($price),
            'quantity' => $options['quantity'] ?? $this->quantity,
            'metadata' => $options['metadata'] ?? null,
        ], fn ($value) => $value !== null);

        return $this->subscription->updateItems([$item], array_intersect_key($options, array_flip(['proration_behavior', 'proration_date'])));
    }
}
