<?php

declare(strict_types=1);

namespace Ridley\Laravel\Exceptions;

/**
 * The API key is not allowed to perform the request (HTTP 403).
 */
class PermissionException extends RidleyException
{
    /**
     * Whether the Ridley account must upgrade its plan to use this endpoint.
     */
    public function planUpgradeRequired(): bool
    {
        return $this->errorCode() === 'plan_upgrade_required';
    }
}
