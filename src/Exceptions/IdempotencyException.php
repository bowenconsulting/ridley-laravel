<?php

declare(strict_types=1);

namespace Ridley\Laravel\Exceptions;

/**
 * An Idempotency-Key was reused with different parameters, or is still in progress (HTTP 409).
 */
class IdempotencyException extends RidleyException
{
    //
}
