<?php

declare(strict_types=1);

namespace Ridley\Laravel\Exceptions;

/**
 * The API key is missing, invalid, revoked or expired (HTTP 401).
 */
class AuthenticationException extends RidleyException
{
    //
}
