<?php

declare(strict_types=1);

namespace Ridley\Laravel\Support;

use Ridley\Laravel\Exceptions\InvalidRequestException;
use Ridley\Laravel\Price;
use Ridley\Laravel\Ridley;

/**
 * Turns a price argument (Ridley price ID, lookup key or local Price model) into a Ridley price ID.
 *
 * @internal
 */
final class PriceResolver
{
    public static function resolve(string|Price $price): string
    {
        if ($price instanceof Price) {
            return $price->ridley_price_id;
        }

        if (str_starts_with($price, 'price_')) {
            return $price;
        }

        $model = Ridley::priceModel();

        $local = $model::query()
            ->where('lookup_key', $price)
            ->where('is_current', true)
            ->latest('id')
            ->first();

        if ($local !== null) {
            return $local->ridley_price_id;
        }

        $remote = Ridley::client()->prices()->findByLookupKey($price);

        if ($remote === null || $remote->id() === null) {
            throw new InvalidRequestException("No Ridley price was found for lookup key [{$price}].", 422, [
                'code' => 'resource_missing',
                'param' => 'price',
            ]);
        }

        return $remote->id();
    }

    /**
     * Normalise `'price_a'`, `['price_a', 'price_b']` or `['price_a' => ['quantity' => 2]]`
     * into `[priceId => options]`.
     *
     * @param  string|Price|array<int|string, string|Price|array<string, mixed>>  $prices
     * @return array<string, array<string, mixed>>
     */
    public static function normalise(string|Price|array $prices): array
    {
        $prices = is_array($prices) ? $prices : [$prices];
        $normalised = [];

        foreach ($prices as $key => $value) {
            if (is_string($key)) {
                $normalised[self::resolve($key)] = is_array($value) ? $value : [];
            } elseif (is_string($value) || $value instanceof Price) {
                $normalised[self::resolve($value)] = [];
            }
        }

        return $normalised;
    }
}
