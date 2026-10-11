<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Illuminate\Support\LazyCollection;
use Ridley\Laravel\Resources\Invoice;
use Ridley\Laravel\Resources\InvoiceItem;
use Ridley\Laravel\Resources\Page;

/**
 * @extends AbstractService<Invoice>
 */
class InvoiceService extends AbstractService
{
    protected string $path = 'invoices';

    protected string $resource = Invoice::class;

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function create(array $params, array $opts = []): Invoice
    {
        return $this->createResource($params, $opts);
    }

    public function retrieve(string $id): Invoice
    {
        return $this->retrieveResource($id);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function update(string $id, array $params, array $opts = []): Invoice
    {
        return $this->updateResource($id, $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function delete(string $id, array $params = []): Invoice
    {
        return $this->deleteResource($id, $params);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<Invoice>
     */
    public function list(array $query = []): Page
    {
        return $this->listResources($query);
    }

    /**
     * Every Invoice across all pages, fetched lazily.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, Invoice>
     */
    public function all(array $query = []): LazyCollection
    {
        return $this->allResources($query);
    }

    /**
     * Preview the next invoice for a customer or subscription, optionally quoting
     * a proposed change through `subscription_items`.
     *
     * @param  array<string, mixed>  $query
     */
    public function upcoming(array $query): Invoice
    {
        return $this->requestResource('GET', 'invoices/upcoming', $query);
    }

    /**
     * The invoice PDF as raw bytes.
     */
    public function pdf(string $id): string
    {
        return $this->client->requestRaw('GET', "invoices/{$id}/pdf", [], ['headers' => ['Accept' => 'application/pdf']]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function pay(string $id, array $params = [], array $opts = []): Invoice
    {
        return $this->action($id, 'pay', $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function finalize(string $id, array $params = [], array $opts = []): Invoice
    {
        return $this->action($id, 'finalize', $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function send(string $id, array $params = [], array $opts = []): Invoice
    {
        return $this->action($id, 'send', $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function void(string $id, array $params = [], array $opts = []): Invoice
    {
        return $this->action($id, 'void', $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function markPaid(string $id, array $params = [], array $opts = []): Invoice
    {
        return $this->action($id, 'mark-paid', $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function markUncollectible(string $id, array $params = [], array $opts = []): Invoice
    {
        return $this->action($id, 'mark-uncollectible', $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<InvoiceItem>
     */
    public function lines(string $id, array $query = []): Page
    {
        return (new InvoiceItemService($this->client))->listForInvoice($id, $query);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    protected function action(string $id, string $action, array $params, array $opts): Invoice
    {
        return $this->requestResource('POST', "invoices/{$id}/{$action}", $params, $opts);
    }
}
