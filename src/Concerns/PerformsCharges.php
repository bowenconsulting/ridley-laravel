<?php

declare(strict_types=1);

namespace Ridley\Laravel\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Ridley\Laravel\Checkout;
use Ridley\Laravel\Exceptions\CardException;
use Ridley\Laravel\Exceptions\IncompletePayment;
use Ridley\Laravel\PaymentResult;
use Ridley\Laravel\Resources\Payment;
use Ridley\Laravel\Resources\Session;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\Support\Money;

/**
 * Amounts are in major units (e.g. 19.99 or "19.99"), matching the Ridley API.
 *
 * @mixin Model
 */
trait PerformsCharges
{
    /**
     * Charge the customer's default card.
     *
     * Options: currency, reference (default ULID), description, metadata, purpose,
     * payment_method, invoice (bool), apply_tax, idempotency_key.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws IncompletePayment
     * @throws CardException
     */
    public function charge(string|int|float $amount, array $options = []): Payment
    {
        $this->assertCustomerExists();

        $opts = isset($options['idempotency_key']) ? ['idempotency_key' => (string) $options['idempotency_key']] : [];
        unset($options['idempotency_key']);

        $payload = array_merge([
            'customer' => $this->ridleyId(),
            'amount' => Money::decimal($amount),
            'currency' => $this->billingCurrency(),
            'reference' => (string) Str::ulid(),
        ], $options, [
            'metadata' => $this->chargeMetadata($options),
        ]);

        unset($payload['purpose']);
        $payload['currency'] = strtoupper((string) $payload['currency']);

        $payment = Ridley::client()->charges()->create(array_filter($payload, fn ($value) => $value !== null), $opts);

        if (! empty($payload['invoice']) && $payment->isSucceeded()) {
            $this->lockBillingCurrency((string) $payload['currency']);
        }

        return $payment;
    }

    /**
     * Charge the default card and record a paid invoice for it.
     *
     * @param  array<string, mixed>  $options
     */
    public function chargeWithInvoice(string|int|float $amount, array $options = []): Payment
    {
        return $this->charge($amount, array_merge($options, ['invoice' => true]));
    }

    /**
     * A hosted checkout page for a one-off payment.
     *
     * @param  array<string, mixed>  $options  currency, reference, metadata, purpose, customer_email, …
     */
    public function checkoutCharge(string|int|float $amount, string $description, string $successUrl, string $cancelUrl, array $options = []): Session
    {
        $customer = $this->createOrGetRidleyCustomer();

        $payload = array_merge([
            'mode' => 'payment',
            'customer' => $customer->id(),
            'amount' => Money::decimal($amount),
            'currency' => $this->billingCurrency(),
            'reference' => (string) Str::ulid(),
            'description' => $description,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $this->ridleyEmail(),
            'customer_name' => $this->ridleyName(),
        ], $options, [
            'metadata' => $this->chargeMetadata($options),
        ]);

        unset($payload['purpose']);

        return Ridley::createCheckoutSession($this, array_filter($payload, fn ($value) => $value !== null));
    }

    /**
     * Charge the default card when there is one, otherwise start a hosted checkout.
     *
     * `invoice => true` is only honoured when the currency is the billing currency
     * (Ridley locks a customer's invoices to one currency); otherwise it is a plain charge.
     * `purpose` and `metadata` are carried through both paths.
     *
     * @param  array<string, mixed>  $options  currency, description, purpose, metadata, invoice, reference, …
     *
     * @throws IncompletePayment
     * @throws CardException
     */
    public function payOrCheckout(string|int|float $amount, array $options, string $successUrl, string $cancelUrl): PaymentResult
    {
        $currency = strtoupper((string) ($options['currency'] ?? $this->billingCurrency()));
        $invoice = (bool) ($options['invoice'] ?? false) && $currency === $this->billingCurrency();

        $options = array_merge($options, ['currency' => $currency]);
        unset($options['invoice']);

        if ($this->hasRidleyId() && $this->hasDefaultPaymentMethod()) {
            return PaymentResult::charged($this->charge($amount, $invoice ? array_merge($options, ['invoice' => true]) : $options));
        }

        $description = (string) ($options['description'] ?? 'Payment');
        unset($options['description'], $options['payment_method'], $options['apply_tax'], $options['idempotency_key']);

        return PaymentResult::checkoutRequired(new Checkout($this->checkoutCharge($amount, $description, $successUrl, $cancelUrl, $options)));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function chargeMetadata(array $options): array
    {
        return array_filter(array_merge(
            is_array($options['metadata'] ?? null) ? $options['metadata'] : [],
            [
                'purpose' => isset($options['purpose']) ? (string) $options['purpose'] : null,
                'billable_type' => $this->getMorphClass(),
                'billable_id' => (string) $this->getKey(),
            ],
        ), fn ($value) => $value !== null);
    }
}
