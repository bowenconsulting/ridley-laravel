<?php

declare(strict_types=1);

namespace Ridley\Laravel\Catalogue;

/**
 * The lookup key convention that ties a Ridley price to a catalogue item:
 * `[{prefix}.]{group}.{item}.{currency}.{every}`, e.g. `acme.plan.pro.usd.month`
 * or `plan.pro.usd.3month`. The prefix (config `ridley.catalogue_prefix`) keeps
 * apps that share one Ridley account from importing each other's prices.
 */
final class LookupKey
{
    public static function prefix(): ?string
    {
        $prefix = trim((string) config('ridley.catalogue_prefix', ''), '. ');

        return $prefix === '' ? null : $prefix;
    }

    public static function make(string $group, string $itemKey, string $currency, string $interval, int $intervalCount = 1): string
    {
        $every = $intervalCount > 1 ? $intervalCount.$interval : $interval;
        $key = "{$group}.{$itemKey}.".strtolower($currency).".{$every}";
        $prefix = self::prefix();

        return $prefix === null ? $key : "{$prefix}.{$key}";
    }

    /**
     * Whether a lookup key belongs to this app (carries the prefix, when one is set).
     */
    public static function belongsToApp(?string $lookupKey): bool
    {
        if ($lookupKey === null || $lookupKey === '') {
            return false;
        }

        $prefix = self::prefix();

        return $prefix === null || str_starts_with($lookupKey, "{$prefix}.");
    }

    /**
     * Read a lookup key, or null when it doesn't belong to this app or follow the convention.
     *
     * @return array{group: string, item_key: string, currency: string, interval: string, interval_count: int}|null
     */
    public static function parse(?string $lookupKey): ?array
    {
        if (! self::belongsToApp($lookupKey)) {
            return null;
        }

        $prefix = self::prefix();
        $key = $prefix === null ? (string) $lookupKey : substr((string) $lookupKey, strlen($prefix) + 1);
        $segments = explode('.', $key);

        if (count($segments) < 4 || in_array('', $segments, true)) {
            return null;
        }

        $every = (string) array_pop($segments);
        $currency = (string) array_pop($segments);
        $group = (string) array_shift($segments);

        if (! preg_match('/^(\d*)(day|week|month|year)$/', $every, $matches) || ! preg_match('/^[a-z]{3}$/i', $currency)) {
            return null;
        }

        $count = $matches[1] === '' ? 1 : (int) $matches[1];

        if ($count < 1) {
            return null;
        }

        return [
            'group' => $group,
            'item_key' => implode('.', $segments),
            'currency' => strtoupper($currency),
            'interval' => $matches[2],
            'interval_count' => $count,
        ];
    }
}
