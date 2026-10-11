<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Ridley\Laravel\Resources\PortalSession;

/**
 * @extends AbstractService<PortalSession>
 */
class PortalSessionService extends AbstractService
{
    protected string $path = 'portal-sessions';

    protected string $resource = PortalSession::class;

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function create(array $params, array $opts = []): PortalSession
    {
        return $this->createResource($params, $opts);
    }
}
