<?php

declare(strict_types=1);

namespace Ridley\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Ridley\Laravel\Billable;

/**
 * A billable whose own `subscription()` relation shadows the trait alias.
 */
class Organization extends Model
{
    use Billable;

    protected $guarded = [];

    /**
     * @return HasOne<LegacyPlan, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(LegacyPlan::class);
    }
}
