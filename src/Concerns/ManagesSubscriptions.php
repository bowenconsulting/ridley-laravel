<?php

declare(strict_types=1);

namespace Ridley\Laravel\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Ridley\Laravel\Price;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\Subscription;
use Ridley\Laravel\SubscriptionBuilder;
use Ridley\Laravel\SubscriptionItem;
use Ridley\Laravel\Support\Timestamp;

/**
 * Package internals only ever call the `ridley*` relation names, so models that already
 * define their own `subscription()` / `subscriptions()` keep working.
 *
 * @mixin Model
 */
trait ManagesSubscriptions
{
    /**
     * Begin creating a new subscription.
     *
     * @param  string|Price|array<int, string|Price>  $prices  Price IDs, lookup keys or catalogue Prices.
     */
    public function newSubscription(string $type, string|Price|array $prices = []): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $type, $prices);
    }

    /**
     * All of the model's Ridley subscriptions, newest first.
     *
     * @return MorphMany<Subscription, $this>
     */
    public function ridleySubscriptions(): MorphMany
    {
        return $this->morphMany(Ridley::subscriptionModel(), 'billable', 'billable_type', 'billable_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * The newest subscription of the given type.
     */
    public function ridleySubscription(string $type = 'default'): ?Subscription
    {
        return $this->ridleySubscriptions()->where('type', $type)->first();
    }

    /**
     * Cashier-style alias of {@see ridleySubscriptions()}. Models defining their own
     * `subscriptions()` keep theirs; the package never calls this method.
     *
     * @return MorphMany<Subscription, $this>
     */
    public function subscriptions(): MorphMany
    {
        return $this->ridleySubscriptions();
    }

    /**
     * Cashier-style alias of {@see ridleySubscription()}. Models defining their own
     * `subscription()` keep theirs; the package never calls this method.
     */
    public function subscription(string $type = 'default'): ?Subscription
    {
        return $this->ridleySubscription($type);
    }

    /**
     * Whether the model has a valid subscription of the type (optionally on a price).
     */
    public function subscribed(string $type = 'default', string|Price|null $price = null): bool
    {
        $subscription = $this->ridleySubscription($type);

        if ($subscription === null || ! $subscription->valid()) {
            return false;
        }

        return $price === null || $subscription->hasPrice($price);
    }

    /**
     * @param  string|Price|array<int, string|Price>  $prices
     */
    public function subscribedToPrice(string|Price|array $prices, string $type = 'default'): bool
    {
        $subscription = $this->ridleySubscription($type);

        if ($subscription === null || ! $subscription->valid()) {
            return false;
        }

        foreach ((is_array($prices) ? $prices : [$prices]) as $price) {
            if ($subscription->hasPrice($price)) {
                return true;
            }
        }

        return false;
    }

    /**
     * On a subscription trial, or (for the default type without a price) on a generic trial.
     */
    public function onTrial(string $type = 'default', string|Price|null $price = null): bool
    {
        if ($type === 'default' && $price === null && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->ridleySubscription($type);

        if ($subscription === null || ! $subscription->onTrial()) {
            return false;
        }

        return $price === null || $subscription->hasPrice($price);
    }

    /**
     * On a trial without a subscription (`trial_ends_at` on the Ridley customer record is in the future).
     */
    public function onGenericTrial(): bool
    {
        $endsAt = $this->genericTrialEndsAt();

        return $endsAt !== null && $endsAt->isFuture();
    }

    public function hasExpiredGenericTrial(): bool
    {
        $endsAt = $this->genericTrialEndsAt();

        return $endsAt !== null && $endsAt->isPast();
    }

    public function genericTrialEndsAt(): ?CarbonImmutable
    {
        return Timestamp::parse($this->ridleyCustomer?->trial_ends_at);
    }

    /**
     * Whether a valid subscription includes the catalogue item.
     */
    public function subscribedToCatalogue(string $group, string $itemKey, string $type = 'default'): bool
    {
        $subscription = $this->ridleySubscription($type);

        return $subscription !== null && $subscription->valid() && $subscription->hasCatalogueItem($group, $itemKey);
    }

    public function hasExpiredTrial(string $type = 'default'): bool
    {
        return (bool) $this->ridleySubscription($type)?->hasExpiredTrial();
    }

    /**
     * Whether any valid subscription includes the price.
     */
    public function onPrice(string|Price $price): bool
    {
        return $this->ridleySubscriptions()->with('items')->get()
            ->contains(fn (Subscription $subscription) => $subscription->valid() && $subscription->hasPrice($price));
    }

    public function hasIncompletePayment(string $type = 'default'): bool
    {
        return (bool) $this->ridleySubscription($type)?->hasIncompletePayment();
    }

    /**
     * The subscription item for a catalogue group across valid subscriptions (e.g. the current `plan`).
     */
    public function ridleyItemInGroup(string $group, string $type = 'default'): ?SubscriptionItem
    {
        $subscription = $this->ridleySubscription($type);

        return $subscription !== null && $subscription->valid() ? $subscription->itemInGroup($group) : null;
    }
}
