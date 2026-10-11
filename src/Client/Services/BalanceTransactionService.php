<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Illuminate\Support\LazyCollection;
use Ridley\Laravel\Resources\BalanceTransaction;
use Ridley\Laravel\Resources\Page;

/**
 * Customer balance adjustments. Negative amounts are credit owed to the customer.
 *
 * @extends AbstractService<BalanceTransaction>
 */
class BalanceTransactionService extends AbstractService
{
    protected string $path = 'balance-transactions';

    protected string $resource = BalanceTransaction::class;

    /**
     * @param  array<string, mixed>  $params  `amount` (decimal string, negative = credit), `currency`, `description`, `metadata`.
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function create(string $customerId, array $params, array $opts = []): BalanceTransaction
    {
        return $this->requestResource('POST', $this->pathFor($customerId), $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<BalanceTransaction>
     */
    public function list(string $customerId, array $query = []): Page
    {
        return $this->listResources($query, $this->pathFor($customerId));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, BalanceTransaction>
     */
    public function all(string $customerId, array $query = []): LazyCollection
    {
        return $this->allResources($query, $this->pathFor($customerId));
    }

    protected function pathFor(string $customerId): string
    {
        return 'customers/'.rawurlencode($customerId).'/balance-transactions';
    }
}
