<?php

declare(strict_types=1);

namespace Ridley\Laravel\Resources;

/**
 * @property-read string|null $customer
 * @property-read string|null $status
 * @property-read string|null $currency
 * @property-read string|null $interval
 * @property-read int|null $interval_count
 * @property-read string|null $current_period_start
 * @property-read string|null $current_period_end
 * @property-read string|null $trial_end
 * @property-read bool|null $cancel_at_period_end
 * @property-read string|null $ended_at
 * @property-read string|null $latest_invoice
 * @property-read list<array<string, mixed>>|null $items
 * @property-read array<string, mixed>|null $metadata
 */
class Subscription extends RidleyObject
{
    //
}
