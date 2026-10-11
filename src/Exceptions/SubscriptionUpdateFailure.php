<?php

declare(strict_types=1);

namespace Ridley\Laravel\Exceptions;

use Exception;
use Ridley\Laravel\Subscription;

class SubscriptionUpdateFailure extends Exception
{
    public static function incompleteSubscription(Subscription $subscription): static
    {
        return new static("The subscription [{$subscription->ridley_id}] cannot be updated because its payment is incomplete.");
    }

    public static function duplicatePrice(Subscription $subscription, string $price): static
    {
        return new static("The price [{$price}] is already attached to subscription [{$subscription->ridley_id}].");
    }

    public static function cannotDeleteLastPrice(Subscription $subscription): static
    {
        return new static("The price on subscription [{$subscription->ridley_id}] cannot be removed because it is the last one.");
    }

    public static function priceNotFound(Subscription $subscription, string $price): static
    {
        return new static("The price [{$price}] is not attached to subscription [{$subscription->ridley_id}].");
    }

    public static function multiplePrices(Subscription $subscription): static
    {
        return new static("Subscription [{$subscription->ridley_id}] has multiple prices; pass the price to update.");
    }

    public static function ended(Subscription $subscription): static
    {
        return new static("Subscription [{$subscription->ridley_id}] has ended and can no longer be changed.");
    }
}
