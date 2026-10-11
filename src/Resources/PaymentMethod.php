<?php

declare(strict_types=1);

namespace Ridley\Laravel\Resources;

use Carbon\CarbonImmutable;

/**
 * A saved card.
 *
 * @property-read string|null $customer
 * @property-read string|null $brand
 * @property-read string|null $last4
 * @property-read int|null $exp_month
 * @property-read int|null $exp_year
 * @property-read bool|null $is_default
 */
class PaymentMethod extends RidleyObject
{
    public function brand(): ?string
    {
        return $this->brand;
    }

    public function lastFour(): ?string
    {
        return $this->last4 !== null ? (string) $this->last4 : null;
    }

    /**
     * The last moment the card is valid (end of its expiry month).
     */
    public function expiresAt(): ?CarbonImmutable
    {
        if (! $this->exp_month || ! $this->exp_year) {
            return null;
        }

        $year = (int) $this->exp_year;
        $year = $year < 100 ? 2000 + $year : $year;

        return CarbonImmutable::create($year, (int) $this->exp_month, 1)?->endOfMonth();
    }

    public function isExpired(): bool
    {
        return $this->expiresAt()?->isPast() ?? false;
    }

    /**
     * Whether the card expires within the given number of days (and has not expired yet).
     */
    public function expiresSoon(int $days = 30): bool
    {
        $expiresAt = $this->expiresAt();

        return $expiresAt !== null && ! $expiresAt->isPast() && $expiresAt->lte(CarbonImmutable::now()->addDays($days));
    }
}
