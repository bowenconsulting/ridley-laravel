<?php

declare(strict_types=1);

namespace Ridley\Laravel\Resources;

/**
 * @property-read string|null $product
 * @property-read string|null $currency
 * @property-read string|null $unit_amount
 * @property-read string|null $interval
 * @property-read int|null $interval_count
 * @property-read string|null $lookup_key
 * @property-read bool|null $active
 * @property-read array<string, mixed>|null $metadata
 */
class Price extends RidleyObject
{
    /**
     * The price type: `one_time` or `recurring`.
     */
    public function priceType(): ?string
    {
        $type = $this->attributes['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    public function isRecurring(): bool
    {
        return $this->priceType() === 'recurring' || $this->interval !== null;
    }
}
