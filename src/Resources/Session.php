<?php

declare(strict_types=1);

namespace Ridley\Laravel\Resources;

use Illuminate\Http\RedirectResponse;

/**
 * A hosted checkout session.
 *
 * @property-read string|null $mode
 * @property-read string|null $amount
 * @property-read string|null $currency
 * @property-read string|null $status
 * @property-read string|null $checkout_url
 * @property-read string|null $customer
 * @property-read string|null $payment_method
 * @property-read string|null $subscription
 * @property-read string|null $payment_id
 * @property-read string|null $reference
 * @property-read array<string, mixed>|null $metadata
 * @property-read string|null $expires_at
 */
class Session extends RidleyObject
{
    /**
     * The hosted checkout URL to send the customer to.
     */
    public function url(): ?string
    {
        $url = $this->checkout_url ?? $this->links()['checkout'] ?? null;

        return is_string($url) ? $url : null;
    }

    /**
     * Redirect the customer to the hosted checkout page.
     */
    public function redirect(): RedirectResponse
    {
        return new RedirectResponse((string) $this->url());
    }
}
