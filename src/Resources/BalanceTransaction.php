<?php

declare(strict_types=1);

namespace Ridley\Laravel\Resources;

/**
 * A customer balance adjustment. Negative amounts are credit.
 *
 * @property-read string|null $customer
 * @property-read string|null $amount
 * @property-read string|null $currency
 * @property-read string|null $ending_balance
 * @property-read string|null $description
 */
class BalanceTransaction extends RidleyObject
{
    //
}
