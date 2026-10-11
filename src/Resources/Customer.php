<?php

declare(strict_types=1);

namespace Ridley\Laravel\Resources;

use Ridley\Laravel\Support\Money;

/**
 * @property-read string|null $name
 * @property-read string|null $email
 * @property-read string|null $phone
 * @property-read array<string, string|null>|null $address
 * @property-read string|null $balance
 * @property-read string|null $currency
 * @property-read string|null $default_payment_method
 * @property-read array<string, mixed>|null $metadata
 */
class Customer extends RidleyObject
{
    /**
     * The balance in integer minor units; negative is credit owed to the customer.
     */
    public function rawBalance(): int
    {
        return Money::toMinor($this->balance);
    }
}
