<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use DateTimeInterface;
use Illuminate\Support\LazyCollection;
use Ridley\Laravel\Resources\Page;
use Ridley\Laravel\Resources\TestClock;

/**
 * @extends AbstractService<TestClock>
 */
class TestClockService extends AbstractService
{
    protected string $path = 'test-clocks';

    protected string $resource = TestClock::class;

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function create(array $params, array $opts = []): TestClock
    {
        return $this->createResource($params, $opts);
    }

    public function retrieve(string $id): TestClock
    {
        return $this->retrieveResource($id);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function delete(string $id, array $params = []): TestClock
    {
        return $this->deleteResource($id, $params);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<TestClock>
     */
    public function list(array $query = []): Page
    {
        return $this->listResources($query);
    }

    /**
     * Every TestClock across all pages, fetched lazily.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, TestClock>
     */
    public function all(array $query = []): LazyCollection
    {
        return $this->allResources($query);
    }

    /**
     * Move the clock forward to `$frozenTime` (unix timestamp, date string or DateTime).
     *
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function advance(string $id, int|string|DateTimeInterface $frozenTime, array $opts = []): TestClock
    {
        $frozenTime = $frozenTime instanceof DateTimeInterface ? $frozenTime->getTimestamp() : $frozenTime;

        return $this->requestResource('POST', "test-clocks/{$id}/advance", ['frozen_time' => $frozenTime], $opts);
    }
}
