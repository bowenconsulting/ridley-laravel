<?php

declare(strict_types=1);

namespace Ridley\Laravel\Resources;

/**
 * The account behind the API key (`GET /me`).
 *
 * @property-read string|null $name
 * @property-read string|null $mode
 * @property-read string|null $default_currency
 */
class Account extends RidleyObject
{
    //
}
