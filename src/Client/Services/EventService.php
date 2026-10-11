<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Illuminate\Support\LazyCollection;
use Ridley\Laravel\Resources\Event;
use Ridley\Laravel\Resources\Page;

/**
 * @extends AbstractService<Event>
 */
class EventService extends AbstractService
{
    protected string $path = 'events';

    protected string $resource = Event::class;

    public function retrieve(string $id): Event
    {
        return $this->retrieveResource($id);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<Event>
     */
    public function list(array $query = []): Page
    {
        return $this->listResources($query);
    }

    /**
     * Every Event across all pages, fetched lazily.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, Event>
     */
    public function all(array $query = []): LazyCollection
    {
        return $this->allResources($query);
    }

    /**
     * Redeliver an event to your webhook endpoints.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function redeliver(string $id, array $params = []): array
    {
        return $this->client->request('POST', "events/{$id}/redeliver", $params);
    }
}
