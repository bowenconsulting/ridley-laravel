<?php

declare(strict_types=1);

namespace Ridley\Laravel;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Ridley\Laravel\Support\Money;

/**
 * A Ridley price synced from the catalogue. Retired prices stay (is_current = false)
 * so existing subscribers can still be mapped back to their group and item.
 *
 * @property int $id
 * @property string $ridley_price_id
 * @property string|null $ridley_product_id
 * @property string|null $lookup_key
 * @property string $group
 * @property string $item_key
 * @property string $currency
 * @property string|null $interval
 * @property int $interval_count
 * @property int $unit_amount
 * @property bool $is_current
 * @property int|null $max_quantity
 * @property list<string>|null $features
 * @property array<string, int|null>|null $limits
 * @property array<string, mixed>|null $metadata
 */
class Price extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interval_count' => 'integer',
            'unit_amount' => 'integer',
            'is_current' => 'boolean',
            'max_quantity' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'metadata' => 'array',
        ];
    }

    public function getTable(): string
    {
        return (string) config('ridley.tables.prices', 'ridley_prices');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }

    /**
     * The amount as a Ridley decimal string, e.g. "49.00".
     */
    public function decimalAmount(): string
    {
        return Money::fromMinor($this->unit_amount);
    }

    /**
     * The formatted amount, e.g. "$49.00".
     */
    public function amount(): string
    {
        return Ridley::formatAmount($this->decimalAmount(), $this->currency);
    }
}
