<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Illuminate\Support\LazyCollection;
use Ridley\Laravel\Resources\Page;
use Ridley\Laravel\Resources\Subscription;

/**
 * @extends AbstractService<Subscription>
 */
class SubscriptionService extends AbstractService
{
    protected string $path = 'subscriptions';

    protected string $resource = Subscription::class;

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function create(array $params, array $opts = []): Subscription
    {
        return $this->createResource($params, $opts);
    }

    public function retrieve(string $id): Subscription
    {
        return $this->retrieveResource($id);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function update(string $id, array $params, array $opts = []): Subscription
    {
        return $this->updateResource($id, $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function delete(string $id, array $params = []): Subscription
    {
        return $this->deleteResource($id, $params);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<Subscription>
     */
    public function list(array $query = []): Page
    {
        return $this->listResources($query);
    }

    /**
     * Every Subscription across all pages, fetched lazily.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, Subscription>
     */
    public function all(array $query = []): LazyCollection
    {
        return $this->allResources($query);
    }

    /**
     * Cancel a subscription immediately (`prorate`, `invoice_now`).
     *
     * @param  array<string, mixed>  $params
     */
    public function cancel(string $id, array $params = []): Subscription
    {
        return $this->delete($id, $params);
    }

    /**
     * Resume a paused subscription.
     *
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function resume(string $id, array $opts = []): Subscription
    {
        return $this->requestResource('POST', "subscriptions/{$id}/resume", [], $opts);
    }
}
