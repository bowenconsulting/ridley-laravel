<?php

declare(strict_types=1);

namespace Ridley\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class LegacyPlan extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
