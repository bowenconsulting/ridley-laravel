<?php

declare(strict_types=1);

namespace Ridley\Laravel\Tests\Fixtures;

use Carbon\CarbonImmutable;

/**
 * A billable that keeps its trial date somewhere other than the Ridley customer record.
 */
class ExternalTrialUser extends User
{
    public static ?CarbonImmutable $trialEndsAt = null;

    protected $table = 'users';

    public function genericTrialEndsAt(): ?CarbonImmutable
    {
        return static::$trialEndsAt;
    }
}
