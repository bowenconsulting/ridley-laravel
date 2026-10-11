<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Ridley\Laravel\Resources\Payment;

/**
 * @extends AbstractService<Payment>
 */
class ChargeService extends AbstractService
{
    protected string $path = 'charges';

    protected string $resource = Payment::class;

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function create(array $params, array $opts = []): Payment
    {
        return $this->createResource($params, $opts);
    }
}
