<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Illuminate\Support\LazyCollection;
use Ridley\Laravel\Resources\Page;
use Ridley\Laravel\Resources\Session;

/**
 * @extends AbstractService<Session>
 */
class SessionService extends AbstractService
{
    protected string $path = 'sessions';

    protected string $resource = Session::class;

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function create(array $params, array $opts = []): Session
    {
        return $this->createResource($params, $opts);
    }

    public function retrieve(string $id): Session
    {
        return $this->retrieveResource($id);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<Session>
     */
    public function list(array $query = []): Page
    {
        return $this->listResources($query);
    }

    /**
     * Every Session across all pages, fetched lazily.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, Session>
     */
    public function all(array $query = []): LazyCollection
    {
        return $this->allResources($query);
    }

    /**
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function expire(string $id, array $opts = []): Session
    {
        return $this->requestResource('POST', "sessions/{$id}/expire", [], $opts);
    }
}
