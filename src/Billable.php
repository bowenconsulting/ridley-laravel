<?php

declare(strict_types=1);

namespace Ridley\Laravel;

use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\RoutesNotifications;
use Ridley\Laravel\Concerns\ManagesCustomer;
use Ridley\Laravel\Concerns\ManagesEntitlements;
use Ridley\Laravel\Concerns\ManagesInvoices;
use Ridley\Laravel\Concerns\ManagesPaymentMethods;
use Ridley\Laravel\Concerns\ManagesSubscriptions;
use Ridley\Laravel\Concerns\PerformsCharges;

/**
 * Add to an Eloquent model to make it billable through Ridley.
 *
 * Required columns: ridley_customer_id, pm_brand, pm_last_four, pm_exp_month, pm_exp_year.
 */
trait Billable
{
    use ManagesCustomer;
    use ManagesEntitlements;
    use ManagesInvoices;
    use ManagesPaymentMethods;
    use ManagesSubscriptions;
    use PerformsCharges;

    /**
     * Who receives the package's billing notifications for this billable.
     *
     * @return iterable<int, mixed>
     */
    public function ridleyNotifiables(): iterable
    {
        $traits = class_uses_recursive($this);

        return in_array(Notifiable::class, $traits, true) || in_array(RoutesNotifications::class, $traits, true)
            ? [$this]
            : [];
    }
}
