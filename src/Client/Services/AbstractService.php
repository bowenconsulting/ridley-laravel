<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Ridley\Laravel\Client\RidleyClient;
use Ridley\Laravel\Resources\Page;
use Ridley\Laravel\Resources\RidleyObject;

/**
 * @template TResource of RidleyObject
 */
abstract class AbstractService
{
    /**
     * The collection path relative to the base URL, e.g. `customers`.
     */
    protected string $path;

    /**
     * @var class-string<TResource>
     */
    protected string $resource;

    public function __construct(protected RidleyClient $client) {}

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     * @return TResource
     */
    protected function createResource(array $params, array $opts = []): RidleyObject
    {
        return $this->requestResource('POST', $this->path, $params, $opts);
    }

    /**
     * @return TResource
     */
    protected function retrieveResource(string $id): RidleyObject
    {
        return $this->requestResource('GET', $this->path.'/'.rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     * @return TResource
     */
    protected function updateResource(string $id, array $params, array $opts = []): RidleyObject
    {
        return $this->requestResource('PATCH', $this->path.'/'.rawurlencode($id), $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return TResource
     */
    protected function deleteResource(string $id, array $params = []): RidleyObject
    {
        $body = $this->client->request('DELETE', $this->path.'/'.rawurlencode($id), $params);
        $data = is_array($body['data'] ?? null) ? $body['data'] : ['id' => $id, 'deleted' => true];

        /** @var TResource */
        return RidleyObject::constructFrom($data, $this->resource);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<TResource>
     */
    protected function listResources(array $query = [], ?string $path = null): Page
    {
        return $this->requestPage('GET', $path ?? $this->path, $query);
    }

    /**
     * Iterate every resource across all pages, fetching lazily.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, TResource>
     */
    protected function allResources(array $query = [], ?string $path = null): LazyCollection
    {
        return LazyCollection::make(function () use ($query, $path) {
            $cursor = null;

            do {
                $pageQuery = $query;

                if ($cursor !== null) {
                    $pageQuery['page'] = array_merge(is_array($query['page'] ?? null) ? $query['page'] : [], ['after' => $cursor]);
                }

                $page = $this->listResources($pageQuery, $path);

                foreach ($page as $resource) {
                    yield $resource;
                }

                $cursor = $page->nextCursor();
            } while ($cursor !== null);
        });
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     * @return TResource
     */
    protected function requestResource(string $method, string $path, array $params = [], array $opts = []): RidleyObject
    {
        return $this->hydrate($this->client->request($method, $path, $params, $opts));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Page<TResource>
     */
    protected function requestPage(string $method, string $path, array $params = []): Page
    {
        $body = $this->client->request($method, $path, $params);

        /** @var list<mixed> $items */
        $items = is_array($body['data'] ?? null) ? $body['data'] : [];

        /** @var Collection<int, TResource> $data */
        $data = collect($items)
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => RidleyObject::constructFrom($item, $this->resource))
            ->values();

        return new Page(
            $data,
            is_array($body['links'] ?? null) ? $body['links'] : [],
            is_array($body['meta'] ?? null) ? $body['meta'] : [],
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TResource
     */
    protected function hydrate(array $body): RidleyObject
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        /** @var TResource */
        return RidleyObject::constructFrom($data, $this->resource);
    }
}
