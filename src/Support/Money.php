<?php

declare(strict_types=1);

namespace Ridley\Laravel\Support;

/**
 * Converts between Ridley's decimal-string amounts and integer minor units.
 *
 * @internal
 */
final class Money
{
    /**
     * Normalise a major-unit amount (e.g. 19.99, "19.99", 20) to a two decimal string.
     */
    public static function decimal(string|int|float $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Convert a major-unit amount (decimal string) to integer minor units.
     */
    public static function toMinor(string|int|float|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    /**
     * Convert integer minor units to a two decimal string.
     */
    public static function fromMinor(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
