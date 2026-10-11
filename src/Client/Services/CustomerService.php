<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Illuminate\Support\LazyCollection;
use Ridley\Laravel\Resources\Customer;
use Ridley\Laravel\Resources\Page;
use Ridley\Laravel\Resources\PaymentMethod;

/**
 * @extends AbstractService<Customer>
 */
class CustomerService extends AbstractService
{
    protected string $path = 'customers';

    protected string $resource = Customer::class;

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function create(array $params, array $opts = []): Customer
    {
        return $this->createResource($params, $opts);
    }

    public function retrieve(string $id): Customer
    {
        return $this->retrieveResource($id);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function update(string $id, array $params, array $opts = []): Customer
    {
        return $this->updateResource($id, $params, $opts);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function delete(string $id, array $params = []): Customer
    {
        return $this->deleteResource($id, $params);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<Customer>
     */
    public function list(array $query = []): Page
    {
        return $this->listResources($query);
    }

    /**
     * Every Customer across all pages, fetched lazily.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, Customer>
     */
    public function all(array $query = []): LazyCollection
    {
        return $this->allResources($query);
    }

    /**
     * The customer's saved payment methods.
     *
     * @param  array<string, mixed>  $query
     * @return Page<PaymentMethod>
     */
    public function paymentMethods(string $id, array $query = []): Page
    {
        return (new PaymentMethodService($this->client))->listForCustomer($id, $query);
    }

    /**
     * Make a saved payment method the customer's default.
     *
     * @param  array{idempotency_key?: string|null}  $opts
     */
    public function setDefaultPaymentMethod(string $id, string $paymentMethod, array $opts = []): Customer
    {
        return $this->requestResource('POST', "customers/{$id}/default-payment-method", ['payment_method' => $paymentMethod], $opts);
    }
}
