<?php

declare(strict_types=1);

namespace Ridley\Laravel\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Ridley\Laravel\Exceptions\CurrencyLocked;
use Ridley\Laravel\Exceptions\IncompletePayment;
use Ridley\Laravel\Exceptions\PriceNotFound;
use Ridley\Laravel\Price;
use Ridley\Laravel\Resources\Invoice;
use Ridley\Laravel\Resources\Payment;
use Ridley\Laravel\Resources\Session;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\Subscription;
use Ridley\Laravel\Support\Money;
use Ridley\Laravel\Support\SubscriptionMirror;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional headless billing endpoints (`ridley.routes.enabled`).
 *
 * JSON requests get JSON; other requests are redirected (back, or to
 * {@see Ridley::afterActionRedirect()}) with a flashed `status`. Hosted pages
 * (checkout, portal) answer 409 + Location for JSON, `Inertia::location` for
 * Inertia requests and a plain redirect otherwise.
 */
class BillingController extends Controller
{
    public function subscribe(Request $request): Response
    {
        $billable = $this->billable($request, 'subscribe');
        $data = $this->validateSelection($request, $billable, withType: true);
        $type = (string) ($data['type'] ?? 'default');

        $existing = $billable->ridleySubscription($type);

        if ($existing?->incomplete()) {
            throw ValidationException::withMessages(['items' => 'Your subscription is waiting for its first payment; pay that invoice instead.']);
        }

        if ($existing?->live()) {
            throw ValidationException::withMessages(['items' => 'You already have a subscription; change it instead.']);
        }

        try {
            $result = $billable->newSubscription($type)
                ->currency($data['currency'])
                ->catalogueItems($data['items'], $data['interval'], $data['currency'], $data['interval_count'])
                ->trialUntilIfFuture()
                ->createOrCheckout($this->successUrl($request), $this->cancelUrl($request));
        } catch (CurrencyLocked $exception) {
            throw ValidationException::withMessages(['currency' => $exception->getMessage()]);
        } catch (IncompletePayment $exception) {
            return $this->incomplete($request, $exception);
        }

        if ($result->requiresCheckout()) {
            return $this->external($request, (string) $result->redirectUrl());
        }

        return $this->done($request, 'subscribe', 'Subscribed.', ['subscription' => $this->summary($result->subscription())]);
    }

    public function preview(Request $request): JsonResponse
    {
        $billable = $this->billable($request, 'preview');
        $data = $this->validateSelection($request, $billable, withType: true);
        $subscription = $billable->ridleySubscription((string) ($data['type'] ?? 'default'));

        if ($subscription === null || ! $subscription->manageable()) {
            return response()->json($this->newSubscriptionPreview($data));
        }

        $prices = $this->resolve($data, $subscription->currency ?? $data['currency']);
        $behavior = $subscription->changeProrationBehaviorFor($prices);
        $invoice = $subscription->previewChange($prices);

        return response()->json($this->previewPayload($invoice, $behavior));
    }

    public function change(Request $request): Response
    {
        $billable = $this->billable($request, 'change');
        $data = $this->validateSelection($request, $billable, withType: true);
        $subscription = $this->manageableSubscription($billable, (string) ($data['type'] ?? 'default'));

        try {
            $subscription->change($this->resolve($data, $subscription->currency ?? $data['currency']));
        } catch (IncompletePayment $exception) {
            return $this->incomplete($request, $exception);
        }

        return $this->done($request, 'change', 'Subscription updated.', ['subscription' => $this->summary($subscription->refresh())]);
    }

    public function cancel(Request $request): Response
    {
        $billable = $this->billable($request, 'cancel');
        $subscription = $this->manageableSubscription($billable, (string) $request->input('type', 'default'));

        $subscription->cancel();

        return $this->done($request, 'cancel', 'Subscription cancelled.', ['subscription' => $this->summary($subscription)]);
    }

    public function resume(Request $request): Response
    {
        $billable = $this->billable($request, 'resume');
        $subscription = $billable->ridleySubscription((string) $request->input('type', 'default'));

        if ($subscription === null || ! $subscription->onGracePeriod()) {
            throw ValidationException::withMessages(['subscription' => 'There is no cancelled subscription to resume.']);
        }

        $subscription->resume();

        return $this->done($request, 'resume', 'Subscription resumed.', ['subscription' => $this->summary($subscription)]);
    }

    public function paymentMethod(Request $request): Response
    {
        $billable = $this->billable($request, 'payment-method');

        $session = $billable->paymentMethodSetupSession($this->successUrl($request), $this->cancelUrl($request));

        return $this->external($request, (string) $session->url());
    }

    public function portal(Request $request): Response
    {
        $billable = $this->billable($request, 'portal');

        $billable->createOrGetRidleyCustomer();

        return $this->external($request, $billable->billingPortalUrl(Ridley::redirectUrlAfter($request, 'portal') ?? url()->previous()));
    }

    public function invoices(Request $request): JsonResponse
    {
        $billable = $this->billable($request, 'invoices');
        $parameters = $this->prefixParameters($request);

        return response()->json([
            'data' => $billable->invoices()->map(fn (Invoice $invoice) => [
                'id' => $invoice->id(),
                'number' => $invoice->number(),
                'status' => $invoice->status,
                'currency' => $invoice->currency,
                'total' => $invoice->total,
                'total_formatted' => $invoice->total(),
                'amount_due' => $invoice->amount_due,
                'created_at' => $invoice->created_at,
                'download_url' => route('ridley.invoices.show', array_merge($parameters, ['invoice' => $invoice->id()])),
            ])->values(),
        ]);
    }

    public function invoice(Request $request): Response
    {
        $billable = $this->billable($request, 'invoices');

        return $billable->downloadInvoice((string) $request->route('invoice'));
    }

    public function details(Request $request): Response
    {
        $billable = $this->billable($request, 'details');

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'array'],
            'address.line1' => ['nullable', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.city' => ['nullable', 'string', 'max:255'],
            'address.state' => ['nullable', 'string', 'max:255'],
            'address.postal_code' => ['nullable', 'string', 'max:32'],
            'address.country' => ['nullable', 'string', 'size:2'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $billable->createOrGetRidleyCustomer();

        $payload = array_intersect_key($data, array_flip(['name', 'email', 'address']));

        // Ridley customers have no tax ID field; it is kept in metadata.
        if (array_key_exists('tax_id', $data)) {
            $payload['metadata'] = ['tax_id' => $data['tax_id']];
        }

        $billable->updateRidleyCustomer($payload);

        return $this->done($request, 'details', 'Billing details updated.');
    }

    /**
     * The customer is back from a hosted checkout. The session is found via the
     * `ridley_checkout` reference the package appended to the success URL (or an
     * explicit `?session_id=`); its subscription and payment are only used when they
     * belong to this billable. Without a session every subscription is re-synced.
     */
    public function checkoutSuccess(Request $request): Response
    {
        $billable = $this->billable($request, 'checkout-success');

        if (! $billable->hasRidleyId()) {
            return $this->done($request, 'checkout-success', 'Nothing to sync.');
        }

        $session = Ridley::checkoutSession($request, $billable);
        $sessionId = $request->query('session_id');

        if ($session === null && is_string($sessionId) && $sessionId !== '') {
            $session = Ridley::client()->sessions()->retrieve($sessionId);

            abort_if($session->customer !== null && $session->customer !== $billable->ridleyId(), 403);
        }

        $payment = null;

        if ($session !== null) {
            if (is_string($session->subscription) && $session->subscription !== '') {
                $subscription = Ridley::client()->subscriptions()->retrieve($session->subscription);

                abort_unless($subscription->customer === $billable->ridleyId(), 403);

                SubscriptionMirror::sync($subscription, $billable);
            }

            if (is_string($session->payment_id) && $session->payment_id !== '') {
                $payment = Ridley::client()->payments()->retrieve($session->payment_id);

                abort_unless($this->paymentBelongsTo($payment, $billable, $session), 403);
            }
        } else {
            foreach (Ridley::client()->subscriptions()->all(['filter' => ['customer' => $billable->ridleyId()]]) as $subscription) {
                SubscriptionMirror::sync($subscription, $billable);
            }
        }

        $billable->updateDefaultPaymentMethodFromRidley();

        return $this->done($request, 'checkout-success', 'Thanks! Your billing has been updated.', [
            'subscription' => $this->summary($billable->ridleySubscription()),
            'payment' => $payment === null ? null : [
                'id' => $payment->id(),
                'status' => $payment->status,
                'succeeded' => $payment->isSucceeded(),
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'purpose' => $payment->metadata['purpose'] ?? null,
            ],
        ]);
    }

    /**
     * Session payments carry no customer ID, so ownership is proven by the billable
     * morph the package writes into the payment metadata, and the session's customer.
     */
    protected function paymentBelongsTo(Payment $payment, Model $billable, Session $session): bool
    {
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];

        return $session->customer === $billable->ridleyId()
            && ($metadata['billable_type'] ?? null) === $billable->getMorphClass()
            && (string) ($metadata['billable_id'] ?? '') === (string) $billable->getKey();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function billable(Request $request, string $ability): Model
    {
        $billable = Ridley::resolveBillable($request);

        abort_if($billable === null || ! method_exists($billable, 'newSubscription'), 401);
        abort_unless(Ridley::authorize($request, $billable, $ability), 403);

        return $billable;
    }

    /**
     * Validate `items` / `interval` / `currency` / `type`, check each item is sellable,
     * exists in the catalogue and respects `max_quantity`.
     *
     * @return array{items: list<array{group: string, key: string, quantity: int}>, interval: string, interval_count: int, currency: string, type?: string|null}
     */
    protected function validateSelection(Request $request, Model $billable, bool $withType = false): array
    {
        $data = $request->validate(array_filter([
            'items' => ['required', 'array', 'min:1'],
            'items.*.group' => ['required', 'string', 'max:64'],
            'items.*.key' => ['required', 'string', 'max:64'],
            'items.*.quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'interval' => ['required', 'string', Rule::in(['day', 'week', 'month', 'year'])],
            'interval_count' => ['nullable', 'integer', 'min:1', 'max:36'],
            'currency' => ['nullable', 'string', 'size:3'],
            'type' => $withType ? ['nullable', 'string', 'max:64'] : null,
        ]));

        $currency = strtoupper((string) ($data['currency'] ?? '')) ?: $billable->billingCurrency();
        $subscription = $billable->ridleySubscription((string) ($data['type'] ?? 'default'));

        if ($subscription?->live() && $subscription->currency !== null) {
            $currency = $subscription->currency;
        }

        $intervalCount = (int) ($data['interval_count'] ?? 1);
        $items = [];
        $errors = [];

        foreach ($data['items'] as $index => $item) {
            $quantity = (int) ($item['quantity'] ?? 1);

            if ($quantity <= 0) {
                continue;
            }

            $price = Ridley::catalogue()->price($item['group'], $item['key'], $currency, $data['interval'], $intervalCount);

            if ($price === null || ! Ridley::isSellable($billable, $item['group'], $item['key'])) {
                $errors["items.{$index}.key"] = "[{$item['group']}.{$item['key']}] is not available in {$currency} per {$data['interval']}.";

                continue;
            }

            if ($price->max_quantity !== null && $quantity > $price->max_quantity) {
                $errors["items.{$index}.quantity"] = "The quantity may not be greater than {$price->max_quantity}.";

                continue;
            }

            $items[] = ['group' => (string) $item['group'], 'key' => (string) $item['key'], 'quantity' => $quantity];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Select at least one item.']);
        }

        return [
            'items' => $items,
            'interval' => (string) $data['interval'],
            'interval_count' => $intervalCount,
            'currency' => $currency,
            'type' => $data['type'] ?? null,
        ];
    }

    /**
     * @param  array{items: list<array{group: string, key: string, quantity: int}>, interval: string, interval_count: int, currency: string}  $data
     * @return array<string, array{quantity: int}>
     */
    protected function resolve(array $data, string $currency): array
    {
        try {
            return Ridley::catalogue()->resolve($currency, $data['interval'], $data['items'], $data['interval_count']);
        } catch (PriceNotFound $exception) {
            throw ValidationException::withMessages(['items' => $exception->getMessage()]);
        }
    }

    protected function manageableSubscription(Model $billable, string $type): Subscription
    {
        $subscription = $billable->ridleySubscription($type);

        if ($subscription === null || ! $subscription->manageable()) {
            throw ValidationException::withMessages(['subscription' => 'There is no active subscription.']);
        }

        return $subscription;
    }

    /**
     * A local estimate for a billable without a subscription.
     *
     * @param  array{items: list<array{group: string, key: string, quantity: int}>, interval: string, interval_count: int, currency: string}  $data
     * @return array<string, mixed>
     */
    protected function newSubscriptionPreview(array $data): array
    {
        $lines = [];
        $total = 0;

        foreach ($data['items'] as $item) {
            /** @var Price $price */
            $price = Ridley::catalogue()->price($item['group'], $item['key'], $data['currency'], $data['interval'], $data['interval_count']);
            $amount = (int) $price->unit_amount * $item['quantity'];
            $total += $amount;

            $lines[] = [
                'description' => $price->nickname ?? "{$item['group']}.{$item['key']}",
                'quantity' => $item['quantity'],
                'unit_amount' => Money::fromMinor((int) $price->unit_amount),
                'amount' => Money::fromMinor($amount),
                'price' => $price->ridley_price_id,
                'proration' => false,
            ];
        }

        return [
            'currency' => $data['currency'],
            'amount_due_now' => Money::fromMinor($total),
            'amount_due_now_formatted' => Ridley::formatAmount(Money::fromMinor($total), $data['currency']),
            'next_invoice' => ['total' => Money::fromMinor($total), 'total_formatted' => Ridley::formatAmount(Money::fromMinor($total), $data['currency'])],
            'lines' => $lines,
            'proration_behavior' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function previewPayload(Invoice $invoice, string $behavior): array
    {
        $lines = collect($invoice->items ?? []);
        $prorations = $lines->filter(fn ($line) => is_array($line) && ! empty($line['proration']))
            ->sum(fn (array $line) => Money::toMinor($line['amount'] ?? '0'));

        $dueNow = match (true) {
            $invoice->billing_reason === 'subscription_update' => Money::toMinor($invoice->amount_due ?? $invoice->total ?? '0'),
            $behavior === 'always_invoice' => max(0, $prorations),
            default => 0,
        };

        $currency = (string) $invoice->currency;

        return [
            'currency' => $currency,
            'amount_due_now' => Money::fromMinor($dueNow),
            'amount_due_now_formatted' => Ridley::formatAmount(Money::fromMinor($dueNow), $currency),
            'next_invoice' => [
                'total' => $invoice->total,
                'total_formatted' => $invoice->total(),
                'amount_due' => $invoice->amount_due,
                'period_start' => $invoice->period_start,
                'period_end' => $invoice->period_end,
                'billing_reason' => $invoice->billing_reason,
            ],
            'lines' => $lines->filter(fn ($line) => is_array($line))->map(fn (array $line) => [
                'description' => $line['description'] ?? null,
                'quantity' => $line['quantity'] ?? null,
                'unit_amount' => $line['unit_price'] ?? null,
                'amount' => $line['amount'] ?? null,
                'price' => $line['price'] ?? null,
                'proration' => (bool) ($line['proration'] ?? false),
            ])->values()->all(),
            'proration_behavior' => $behavior,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function summary(?Subscription $subscription): ?array
    {
        if ($subscription === null) {
            return null;
        }

        return [
            'id' => $subscription->ridley_id,
            'type' => $subscription->type,
            'status' => $subscription->ridley_status,
            'currency' => $subscription->currency,
            'interval' => $subscription->interval,
            'selection' => $subscription->selection(),
            'on_trial' => $subscription->onTrial(),
            'on_grace_period' => $subscription->onGracePeriod(),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function done(Request $request, string $action, string $status, array $data = []): Response
    {
        if ($request->expectsJson()) {
            return response()->json(array_merge(['status' => $status], $data));
        }

        $url = Ridley::redirectUrlAfter($request, $action);

        $redirect = $url !== null ? redirect()->to($url) : redirect()->back();

        return $redirect->with('status', $status);
    }

    /**
     * Send the browser to a hosted Ridley page.
     */
    protected function external(Request $request, string $url): Response
    {
        if ($request->header('X-Inertia')) {
            return class_exists(Inertia::class)
                ? Inertia::location($url)
                : new Response('', 409, ['X-Inertia-Location' => $url]);
        }

        if ($request->expectsJson()) {
            return response()->json(['requires_redirect' => true, 'redirect_url' => $url], 409, ['Location' => $url]);
        }

        return new RedirectResponse($url);
    }

    protected function incomplete(Request $request, IncompletePayment $exception): Response
    {
        $url = $exception->paymentUrl();

        if ($url !== null && ! $request->expectsJson()) {
            return $this->external($request, $url);
        }

        return response()->json([
            'message' => $exception->getMessage(),
            'requires_payment' => true,
            'payment_url' => $url,
        ], 402);
    }

    protected function successUrl(Request $request): string
    {
        return route('ridley.checkout.success', $this->prefixParameters($request));
    }

    protected function cancelUrl(Request $request): string
    {
        return Ridley::redirectUrlAfter($request, 'checkout-cancelled') ?? url()->previous();
    }

    /**
     * Route parameters that come from the configured prefix (e.g. `{account}`).
     *
     * @return array<string, mixed>
     */
    protected function prefixParameters(Request $request): array
    {
        $parameters = $request->route()?->parameters() ?? [];

        unset($parameters['invoice']);

        return array_map(fn ($value) => $value instanceof Model ? $value->getRouteKey() : $value, $parameters);
    }
}
