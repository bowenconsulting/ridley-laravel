<?php

declare(strict_types=1);

namespace Ridley\Laravel;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The local record of a billable's Ridley customer: its ID, locked currency, default card and generic trial.
 *
 * @property int $id
 * @property string $billable_type
 * @property int|string $billable_id
 * @property string|null $ridley_id
 * @property string|null $currency
 * @property string|null $pm_brand
 * @property string|null $pm_last_four
 * @property int|null $pm_exp_month
 * @property int|null $pm_exp_year
 * @property Carbon|null $trial_ends_at
 * @property-read Model $billable
 */
class Customer extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pm_exp_month' => 'integer',
            'pm_exp_year' => 'integer',
            'trial_ends_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return (string) config('ridley.tables.customers', 'ridley_customers');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function hasDefaultPaymentMethod(): bool
    {
        return $this->pm_last_four !== null;
    }

    /**
     * Whether the stored default card expired before the current month (unknown expiry counts as valid).
     */
    public function hasExpiredCard(): bool
    {
        if ($this->pm_exp_month === null || $this->pm_exp_year === null) {
            return false;
        }

        return CarbonImmutable::create($this->pm_exp_year, $this->pm_exp_month)->endOfMonth()->isPast();
    }

    public function onGenericTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function hasExpiredGenericTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
    }
}
