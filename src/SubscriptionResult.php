<?php

declare(strict_types=1);

namespace Ridley\Laravel;

/**
 * The outcome of {@see SubscriptionBuilder::createOrCheckout()}: either a created
 * subscription, or a checkout the customer must complete.
 */
final readonly class SubscriptionResult
{
    public function __construct(
        private ?Subscription $subscription = null,
        private ?Checkout $checkout = null,
    ) {}

    public static function created(Subscription $subscription): self
    {
        return new self(subscription: $subscription);
    }

    public static function checkoutRequired(Checkout $checkout): self
    {
        return new self(checkout: $checkout);
    }

    public function subscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function checkout(): ?Checkout
    {
        return $this->checkout;
    }

    public function requiresCheckout(): bool
    {
        return $this->checkout !== null;
    }

    public function redirectUrl(): ?string
    {
        return $this->checkout?->url();
    }
}
