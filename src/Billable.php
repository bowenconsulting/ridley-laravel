<?php

declare(strict_types=1);

namespace Ridley\Laravel;

use Illuminate\Database\Eloquent\Relations\MorphOne;
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
 * Customer state (Ridley ID, locked currency, default card, generic trial) lives in the
 * `ridley_customers` table, reached through {@see ridleyCustomer()}.
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
     * The local Ridley customer record.
     *
     * @return MorphOne<Customer, $this>
     */
    public function ridleyCustomer(): MorphOne
    {
        return $this->morphOne(Ridley::ridleyCustomerModel(), 'billable');
    }

    /**
     * Create or update the local Ridley customer record.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateRidleyCustomerRecord(array $attributes): Customer
    {
        /** @var Customer $record */
        $record = $this->ridleyCustomer ?? $this->ridleyCustomer()->make();
        $record->forceFill($attributes);

        if (! $record->exists || $record->isDirty()) {
            $this->ridleyCustomer()->save($record);
        }

        $this->setRelation('ridleyCustomer', $record);

        return $record;
    }

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
