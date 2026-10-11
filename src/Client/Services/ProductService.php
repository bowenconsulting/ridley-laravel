<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Illuminate\Support\LazyCollection;
use Ridley\Laravel\Resources\Page;
use Ridley\Laravel\Resources\Product;

/**
 * @extends AbstractService<Product>
 */
class ProductService extends AbstractService
{
    protected string $path = 'products';

    protected string $resource = Product::class;

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function create(array $params, array $opts = []): Product
    {
        return $this->createResource($params, $opts);
    }

    public function retrieve(string $id): Product
    {
        return $this->retrieveResource($id);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function update(string $id, array $params, array $opts = []): Product
    {
        return $this->updateResource($id, $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<Product>
     */
    public function list(array $query = []): Page
    {
        return $this->listResources($query);
    }

    /**
     * Every Product across all pages, fetched lazily.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, Product>
     */
    public function all(array $query = []): LazyCollection
    {
        return $this->allResources($query);
    }
}
