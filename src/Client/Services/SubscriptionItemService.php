<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Illuminate\Support\LazyCollection;
use Ridley\Laravel\Resources\Page;
use Ridley\Laravel\Resources\SubscriptionItem;

/**
 * @extends AbstractService<SubscriptionItem>
 */
class SubscriptionItemService extends AbstractService
{
    protected string $path = 'subscription-items';

    protected string $resource = SubscriptionItem::class;

    public function retrieve(string $id): SubscriptionItem
    {
        return $this->retrieveResource($id);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<SubscriptionItem>
     */
    public function list(array $query = []): Page
    {
        return $this->listResources($query);
    }

    /**
     * Every SubscriptionItem across all pages, fetched lazily.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, SubscriptionItem>
     */
    public function all(array $query = []): LazyCollection
    {
        return $this->allResources($query);
    }
}
