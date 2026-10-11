<?php

declare(strict_types=1);

namespace Ridley\Laravel\Exceptions;

/**
 * A card could not be charged: declined, insufficient funds, unusable or missing payment method.
 */
class CardException extends RidleyException
{
    //
}
