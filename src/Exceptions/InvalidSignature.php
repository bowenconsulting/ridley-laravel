<?php

declare(strict_types=1);

namespace Ridley\Laravel\Exceptions;

use Exception;

/**
 * A webhook signature header was missing, malformed, expired or did not match.
 */
class InvalidSignature extends Exception
{
    //
}
