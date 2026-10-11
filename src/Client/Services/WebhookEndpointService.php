<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Illuminate\Support\LazyCollection;
use Ridley\Laravel\Resources\Page;
use Ridley\Laravel\Resources\WebhookEndpoint;

/**
 * @extends AbstractService<WebhookEndpoint>
 */
class WebhookEndpointService extends AbstractService
{
    protected string $path = 'webhook-endpoints';

    protected string $resource = WebhookEndpoint::class;

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function create(array $params, array $opts = []): WebhookEndpoint
    {
        return $this->createResource($params, $opts);
    }

    public function retrieve(string $id): WebhookEndpoint
    {
        return $this->retrieveResource($id);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function update(string $id, array $params, array $opts = []): WebhookEndpoint
    {
        return $this->updateResource($id, $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function delete(string $id, array $params = []): WebhookEndpoint
    {
        return $this->deleteResource($id, $params);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<WebhookEndpoint>
     */
    public function list(array $query = []): Page
    {
        return $this->listResources($query);
    }

    /**
     * Every WebhookEndpoint across all pages, fetched lazily.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, WebhookEndpoint>
     */
    public function all(array $query = []): LazyCollection
    {
        return $this->allResources($query);
    }

    /**
     * Roll the signing secret; the response includes the new `secret`.
     *
     * @param  array<string, mixed>  $params
     */
    public function rollSecret(string $id, array $params = []): WebhookEndpoint
    {
        return $this->requestResource('POST', "webhook-endpoints/{$id}/roll-secret", $params);
    }

    /**
     * Send a test event to the endpoint.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function test(string $id, array $params = []): array
    {
        return $this->client->request('POST', "webhook-endpoints/{$id}/test", $params);
    }
}
