<?php

declare(strict_types=1);

namespace Ridley\Laravel\Resources;

use ArrayIterator;
use Countable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;

/**
 * One page of a cursor-paginated Ridley list.
 *
 * @template TResource of RidleyObject
 *
 * @implements IteratorAggregate<int, TResource>
 */
class Page implements Countable, IteratorAggregate
{
    /**
     * @param  Collection<int, TResource>  $data
     * @param  array<string, mixed>  $links
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        protected Collection $data,
        protected array $links = [],
        protected array $meta = [],
    ) {}

    /**
     * @return Collection<int, TResource>
     */
    public function data(): Collection
    {
        return $this->data;
    }

    public function hasMore(): bool
    {
        return (bool) ($this->meta['has_more'] ?? false);
    }

    /**
     * The `page[after]` cursor for the next page, or null on the last page.
     */
    public function nextCursor(): ?string
    {
        return $this->hasMore() ? $this->data->last()?->id() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function links(): array
    {
        return $this->links;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    public function count(): int
    {
        return $this->data->count();
    }

    /**
     * @return Traversable<int, TResource>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->data->all());
    }
}
