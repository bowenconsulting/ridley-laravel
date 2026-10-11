<?php

declare(strict_types=1);

namespace Ridley\Laravel\Exceptions;

/**
 * Too many requests were sent to Ridley (HTTP 429).
 */
class RateLimitException extends RidleyException
{
    //
}
