<?php

declare(strict_types=1);

namespace Ridley\Laravel\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Ridley\Laravel\Exceptions\NotFoundException;
use Ridley\Laravel\Resources\PaymentMethod;
use Ridley\Laravel\Resources\Session;
use Ridley\Laravel\Ridley;

/**
 * @mixin Model
 */
trait ManagesPaymentMethods
{
    /**
     * The customer's saved cards.
     *
     * @param  array<string, mixed>  $query
     * @return Collection<int, PaymentMethod>
     */
    public function paymentMethods(array $query = []): Collection
    {
        if (! $this->hasRidleyId()) {
            return collect();
        }

        return Ridley::client()->customers()
            ->paymentMethods((string) $this->ridleyId(), array_merge(['page' => ['size' => 100]], $query))
            ->data();
    }

    public function hasPaymentMethod(): bool
    {
        return $this->paymentMethods()->isNotEmpty();
    }

    /**
     * The customer's default card, fetched from Ridley.
     */
    public function defaultPaymentMethod(): ?PaymentMethod
    {
        if (! $this->hasRidleyId()) {
            return null;
        }

        $paymentMethod = $this->asRidleyCustomer()->default_payment_method;

        return $paymentMethod ? $this->findPaymentMethod($paymentMethod) : null;
    }

    /**
     * Whether a default card is stored locally (no API call).
     */
    public function hasDefaultPaymentMethod(): bool
    {
        return $this->getAttribute('pm_last_four') !== null;
    }

    /**
     * Make a saved card the default and store its details locally.
     */
    public function updateDefaultPaymentMethod(string $paymentMethod): PaymentMethod
    {
        $this->assertCustomerExists();

        Ridley::client()->customers()->setDefaultPaymentMethod((string) $this->ridleyId(), $paymentMethod);

        $method = Ridley::client()->paymentMethods()->retrieve($paymentMethod);

        $this->fillPaymentMethodDetails($method)->save();

        return $method;
    }

    /**
     * Refresh the local pm_* columns from the customer's default card on Ridley.
     */
    public function updateDefaultPaymentMethodFromRidley(): static
    {
        if (! $this->hasRidleyId()) {
            return $this;
        }

        return $this->syncDefaultPaymentMethod($this->asRidleyCustomer()->default_payment_method);
    }

    /**
     * Store the details of the given default card locally (null clears them).
     */
    public function syncDefaultPaymentMethod(?string $paymentMethodId): static
    {
        $method = null;

        if ($paymentMethodId !== null && $paymentMethodId !== '') {
            try {
                $method = Ridley::client()->paymentMethods()->retrieve($paymentMethodId);
            } catch (NotFoundException) {
                $method = null;
            }
        }

        $this->fillPaymentMethodDetails($method);

        if ($this->isDirty()) {
            $this->save();
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function fillPaymentMethodDetails(?PaymentMethod $method): static
    {
        if ($method === null || $method->detached) {
            return $this->forceFill([
                'pm_brand' => null,
                'pm_last_four' => null,
                'pm_exp_month' => null,
                'pm_exp_year' => null,
            ]);
        }

        return $this->forceFill([
            'pm_brand' => $method->brand(),
            'pm_last_four' => $method->lastFour(),
            'pm_exp_month' => $method->exp_month !== null ? (int) $method->exp_month : null,
            'pm_exp_year' => $method->exp_year !== null ? (int) $method->exp_year : null,
        ]);
    }

    /**
     * Detach a card belonging to this customer.
     */
    public function deletePaymentMethod(string $paymentMethod): void
    {
        $method = $this->findPaymentMethod($paymentMethod);

        if ($method === null) {
            return;
        }

        Ridley::client()->paymentMethods()->delete($paymentMethod);

        if ($method->is_default || $this->getAttribute('pm_last_four') === $method->lastFour()) {
            $this->updateDefaultPaymentMethodFromRidley();
        }
    }

    /**
     * Find a card belonging to this customer.
     */
    public function findPaymentMethod(string $paymentMethod): ?PaymentMethod
    {
        if (! $this->hasRidleyId()) {
            return null;
        }

        try {
            $method = Ridley::client()->paymentMethods()->retrieve($paymentMethod);
        } catch (NotFoundException) {
            return null;
        }

        return $method->customer === $this->ridleyId() ? $method : null;
    }

    /**
     * A hosted checkout session that saves a card (and makes it the default).
     *
     * @param  array<string, mixed>  $options
     */
    public function paymentMethodSetupSession(string $successUrl, string $cancelUrl, array $options = []): Session
    {
        $customer = $this->createOrGetRidleyCustomer();

        return Ridley::createCheckoutSession($this, array_merge([
            'mode' => 'setup',
            'customer' => $customer->id(),
            'set_default' => true,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'currency' => $this->billingCurrency(),
            'metadata' => $this->ridleyMetadata(),
        ], $options));
    }

    /**
     * The hosted URL where the customer can add or replace their card.
     *
     * @param  array<string, mixed>  $options
     */
    public function updatePaymentMethodUrl(string $successUrl, string $cancelUrl, array $options = []): string
    {
        return (string) $this->paymentMethodSetupSession($successUrl, $cancelUrl, $options)->url();
    }
}
