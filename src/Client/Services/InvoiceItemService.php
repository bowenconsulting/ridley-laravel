<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Illuminate\Support\LazyCollection;
use Ridley\Laravel\Resources\InvoiceItem;
use Ridley\Laravel\Resources\Page;

/**
 * @extends AbstractService<InvoiceItem>
 */
class InvoiceItemService extends AbstractService
{
    protected string $path = 'invoice-items';

    protected string $resource = InvoiceItem::class;

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function create(array $params, array $opts = []): InvoiceItem
    {
        return $this->createResource($params, $opts);
    }

    public function retrieve(string $id): InvoiceItem
    {
        return $this->retrieveResource($id);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function update(string $id, array $params, array $opts = []): InvoiceItem
    {
        return $this->updateResource($id, $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function delete(string $id, array $params = []): InvoiceItem
    {
        return $this->deleteResource($id, $params);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<InvoiceItem>
     */
    public function list(array $query = []): Page
    {
        return $this->listResources($query);
    }

    /**
     * Every InvoiceItem across all pages, fetched lazily.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, InvoiceItem>
     */
    public function all(array $query = []): LazyCollection
    {
        return $this->allResources($query);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<InvoiceItem>
     */
    public function listForInvoice(string $invoiceId, array $query = []): Page
    {
        return $this->listResources($query, 'invoices/'.rawurlencode($invoiceId).'/lines');
    }
}
