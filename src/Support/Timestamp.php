<?php

declare(strict_types=1);

namespace Ridley\Laravel\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Parses the ISO-8601 strings and unix timestamps Ridley returns, in the app's timezone.
 *
 * Ridley sends offsets in the merchant's timezone (e.g. `-05:00`). Eloquent stores a
 * date's wall-clock time without converting it, so it must be in the app's timezone.
 *
 * @internal
 */
final class Timestamp
{
    public static function parse(mixed $value): ?CarbonImmutable
    {
        return self::toDate($value)?->setTimezone(date_default_timezone_get());
    }

    private static function toDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return CarbonImmutable::createFromTimestamp((int) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
