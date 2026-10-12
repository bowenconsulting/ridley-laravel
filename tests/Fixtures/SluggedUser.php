<?php

declare(strict_types=1);

namespace Ridley\Laravel\Tests\Fixtures;

/**
 * A billable whose billing page depends on the billable itself.
 */
class SluggedUser extends User
{
    protected $table = 'users';

    public function ridleyBillingUrl(): ?string
    {
        return "https://app.example.com/accounts/{$this->getKey()}/billing";
    }
}
