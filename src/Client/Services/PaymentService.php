<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Ridley\Laravel\Resources\Page;
use Ridley\Laravel\Resources\Payment;
use Ridley\Laravel\Resources\Refund;
use Ridley\Laravel\Resources\RidleyObject;

/**
 * @extends AbstractService<Payment>
 */
class PaymentService extends AbstractService
{
    protected string $path = 'payments';

    protected string $resource = Payment::class;

    public function retrieve(string $id): Payment
    {
        return $this->retrieveResource($id);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<Payment>
     */
    public function list(array $query = []): Page
    {
        return $this->listResources($query);
    }

    /**
     * Every Payment across all pages, fetched lazily.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, Payment>
     */
    public function all(array $query = []): LazyCollection
    {
        return $this->allResources($query);
    }

    /**
     * Capture an authorised payment (optionally a partial `amount`).
     *
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function capture(string $reference, array $params = [], array $opts = []): Payment
    {
        return $this->requestResource('POST', "payments/{$reference}/capture", $params, $opts);
    }

    /**
     * Void an authorised payment.
     *
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function void(string $reference, array $params = [], array $opts = []): Payment
    {
        return $this->requestResource('POST', "payments/{$reference}/void", $params, $opts);
    }

    /**
     * Refund a payment, fully or partially (`amount`, `reason`).
     *
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function refund(string $reference, array $params = [], array $opts = []): Refund
    {
        $body = $this->client->request('POST', "payments/{$reference}/refunds", $params, $opts);

        /** @var Refund */
        return RidleyObject::constructFrom(is_array($body['data'] ?? null) ? $body['data'] : $body, Refund::class);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Collection<int, Refund>
     */
    public function refunds(string $reference, array $query = []): Collection
    {
        $body = $this->client->request('GET', "payments/{$reference}/refunds", $query);

        /** @var list<array<string, mixed>> $items */
        $items = is_array($body['data'] ?? null) ? $body['data'] : [];

        /** @var Collection<int, Refund> */
        return collect($items)->map(fn (array $item) => RidleyObject::constructFrom($item, Refund::class))->values();
    }
}
