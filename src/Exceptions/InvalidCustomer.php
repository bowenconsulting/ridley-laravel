<?php

declare(strict_types=1);

namespace Ridley\Laravel\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;

class InvalidCustomer extends Exception
{
    /**
     * The billable model has not been created as a Ridley customer yet.
     */
    public static function notYetCreated(Model $owner): static
    {
        return new static(class_basename($owner).' is not a Ridley customer yet. See the createAsRidleyCustomer method.');
    }

    /**
     * The billable model is already a Ridley customer.
     */
    public static function exists(Model $owner): static
    {
        return new static(class_basename($owner)." is already a Ridley customer with ID {$owner->ridleyId()}.");
    }
}
