<?php

declare(strict_types=1);

namespace Ridley\Laravel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Ridley\Laravel\Billable;

class User extends Authenticatable
{
    use Billable;
    use Notifiable;

    protected $guarded = [];
}
