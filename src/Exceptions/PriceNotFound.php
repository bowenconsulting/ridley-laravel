<?php

declare(strict_types=1);

namespace Ridley\Laravel\Exceptions;

/**
 * No current catalogue price exists for a group/item in a currency and interval.
 */
class PriceNotFound extends InvalidRequestException
{
    public string $group = '';

    public string $key = '';

    public string $currency = '';

    public string $interval = '';

    public int $intervalCount = 1;

    public static function for(string $group, string $key, string $currency, string $interval, int $intervalCount = 1): static
    {
        $every = $intervalCount > 1 ? "{$intervalCount} {$interval}s" : $interval;

        $exception = new static(
            "No current catalogue price for [{$group}.{$key}] in ".strtoupper($currency)." per {$every}. Run `php artisan ridley:sync-catalogue`.",
            422,
            ['code' => 'resource_missing', 'param' => 'price'],
        );

        $exception->group = $group;
        $exception->key = $key;
        $exception->currency = strtoupper($currency);
        $exception->interval = $interval;
        $exception->intervalCount = $intervalCount;

        return $exception;
    }
}
