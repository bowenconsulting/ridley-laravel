<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Illuminate\Support\LazyCollection;
use Ridley\Laravel\Resources\Page;
use Ridley\Laravel\Resources\Price;

/**
 * @extends AbstractService<Price>
 */
class PriceService extends AbstractService
{
    protected string $path = 'prices';

    protected string $resource = Price::class;

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function create(array $params, array $opts = []): Price
    {
        return $this->createResource($params, $opts);
    }

    public function retrieve(string $id): Price
    {
        return $this->retrieveResource($id);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function update(string $id, array $params, array $opts = []): Price
    {
        return $this->updateResource($id, $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<Price>
     */
    public function list(array $query = []): Page
    {
        return $this->listResources($query);
    }

    /**
     * Every Price across all pages, fetched lazily.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, Price>
     */
    public function all(array $query = []): LazyCollection
    {
        return $this->allResources($query);
    }

    /**
     * Find the active price carrying a lookup key.
     */
    public function findByLookupKey(string $lookupKey): ?Price
    {
        return $this->list(['filter' => ['lookup_key' => $lookupKey], 'page' => ['size' => 1]])->data()->first();
    }
}
