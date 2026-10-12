<?php

declare(strict_types=1);

namespace Ridley\Laravel\Events;

use Ridley\Laravel\Catalogue\SyncResult;

/**
 * Local catalogue prices changed: after `ridley:sync-catalogue` (every result) or a
 * `price.created` / `price.updated` webhook (one result). Listen to refresh anything
 * derived from prices, such as a public pricing page.
 */
class CatalogueSynced
{
    /**
     * @param  list<SyncResult>  $results
     */
    public function __construct(
        public readonly array $results,
    ) {}
}
