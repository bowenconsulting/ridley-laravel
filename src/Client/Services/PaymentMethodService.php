<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client\Services;

use Ridley\Laravel\Resources\Page;
use Ridley\Laravel\Resources\PaymentMethod;

/**
 * @extends AbstractService<PaymentMethod>
 */
class PaymentMethodService extends AbstractService
{
    protected string $path = 'payment-methods';

    protected string $resource = PaymentMethod::class;

    public function retrieve(string $id): PaymentMethod
    {
        return $this->retrieveResource($id);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function delete(string $id, array $params = []): PaymentMethod
    {
        return $this->deleteResource($id, $params);
    }

    /**
     * Detach a payment method from its customer.
     */
    public function detach(string $id): PaymentMethod
    {
        return $this->delete($id);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Page<PaymentMethod>
     */
    public function listForCustomer(string $customerId, array $query = []): Page
    {
        return $this->listResources($query, 'customers/'.rawurlencode($customerId).'/payment-methods');
    }
}
