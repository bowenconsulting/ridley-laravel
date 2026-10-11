<?php

declare(strict_types=1);

namespace Ridley\Laravel\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Ridley\Laravel\Price;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\Subscription;
use Ridley\Laravel\SubscriptionItem;

/**
 * Optional entitlements computed from the catalogue `features` / `limits` of the
 * items on valid subscriptions, plus generic-trial access (`ridley.trial`).
 *
 * @mixin Model
 */
trait ManagesEntitlements
{
    /**
     * The features this billable may use.
     *
     * @return Collection<int, string>
     */
    public function ridleyFeatures(): Collection
    {
        $features = [];

        foreach ($this->entitlementSources() as [$price]) {
            foreach ($price->features ?? [] as $feature) {
                $features[] = (string) $feature;
            }
        }

        if ($this->onGenericTrial()) {
            foreach ((array) config('ridley.trial.features', []) as $feature) {
                $features[] = (string) $feature;
            }
        }

        return collect($features)->unique()->values();
    }

    public function hasRidleyFeature(string $feature): bool
    {
        return $this->ridleyFeatures()->contains($feature);
    }

    /**
     * A limit summed over all valid items (each multiplied by its quantity).
     * Returns null when any source makes it unlimited, and 0 when nothing grants it.
     */
    public function ridleyLimit(string $limit): ?int
    {
        $total = 0;

        foreach ($this->entitlementSources() as [$price, $quantity]) {
            $limits = $price->limits ?? [];

            if (! array_key_exists($limit, $limits)) {
                continue;
            }

            if ($limits[$limit] === null) {
                return null;
            }

            $total += (int) $limits[$limit] * $quantity;
        }

        if ($this->onGenericTrial()) {
            $trialLimits = (array) config('ridley.trial.limits', []);

            if (array_key_exists($limit, $trialLimits)) {
                if ($trialLimits[$limit] === null) {
                    return null;
                }

                $total = max($total, (int) $trialLimits[$limit]);
            }
        }

        return $total;
    }

    /**
     * Catalogue prices granting entitlements, with their quantities.
     *
     * @return list<array{0: Price, 1: int}>
     */
    protected function entitlementSources(): array
    {
        $sources = [];

        $subscriptions = $this->ridleySubscriptions()->with('items.price')->get()
            ->filter(fn (Subscription $subscription) => $subscription->valid());

        foreach ($subscriptions as $subscription) {
            foreach ($subscription->items as $item) {
                /** @var SubscriptionItem $item */
                if ($item->price !== null) {
                    $sources[] = [$item->price, max(1, $item->quantity ?? 1)];
                }
            }
        }

        if ($this->onGenericTrial() && is_string($trialItem = config('ridley.trial.catalogue_item')) && str_contains($trialItem, '.')) {
            [$group, $itemKey] = explode('.', $trialItem, 2);

            $price = Ridley::catalogue()->prices($group)->first(fn (Price $price) => $price->item_key === $itemKey);

            if ($price !== null) {
                $sources[] = [$price, 1];
            }
        }

        return $sources;
    }
}
