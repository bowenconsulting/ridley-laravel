<?php

declare(strict_types=1);

namespace Ridley\Laravel\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Ridley\Laravel\Events;
use Ridley\Laravel\Events\SubscriptionSynced;
use Ridley\Laravel\Notifications;
use Ridley\Laravel\Notifications\RidleyBillingNotification;
use Ridley\Laravel\Resources\Price;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\Subscription;
use Ridley\Laravel\Support\SubscriptionMirror;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives Ridley webhooks. Extend it and add `handle{StudlyEventType}` methods
 * (e.g. `handleInvoicePaid`) to handle more events; call the parent for built-in ones.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return $this->handleWebhook($request);
    }

    public function handleWebhook(Request $request): Response
    {
        $decoded = json_decode($request->getContent(), true);

        if (! is_array($decoded)) {
            return new Response('Invalid payload', 400);
        }

        $payload = $this->normalisePayload($decoded);
        $type = (string) ($payload['type'] ?? '');
        $eventId = isset($payload['id']) && $payload['id'] !== '' ? (string) $payload['id'] : null;

        if ($eventId !== null && $this->alreadyProcessed($eventId)) {
            return new Response('Webhook Already Processed', 200);
        }

        event(new Events\WebhookReceived($payload));

        $method = 'handle'.Str::studly(str_replace('.', '_', $type));

        if ($type === '' || ! method_exists($this, $method)) {
            $this->markProcessed($eventId, $type);

            return $this->missingMethod();
        }

        $response = $this->{$method}($payload);

        event(new Events\WebhookHandled($payload));

        $this->markProcessed($eventId, $type);

        return $response instanceof Response ? $response : $this->successMethod();
    }

    // ---------------------------------------------------------------------
    // Subscriptions
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        $subscription = $this->syncSubscription($payload);

        event(new Events\SubscriptionCreated($payload, $this->object($payload), $this->previous($payload), $subscription->owner ?? $this->resolveBillable($this->object($payload)), $subscription));

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        $subscription = $this->syncSubscription($payload);

        event(new Events\SubscriptionUpdated($payload, $this->object($payload), $this->previous($payload), $subscription->owner ?? $this->resolveBillable($this->object($payload)), $subscription));

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionPaused(array $payload): Response
    {
        return $this->handleCustomerSubscriptionUpdated($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionResumed(array $payload): Response
    {
        return $this->handleCustomerSubscriptionUpdated($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionTrialWillEnd(array $payload): Response
    {
        $subscription = $this->syncSubscription($payload);
        $billable = $subscription->owner ?? $this->resolveBillable($this->object($payload));

        event(new Events\SubscriptionTrialWillEnd($payload, $this->object($payload), $this->previous($payload), $billable, $subscription));

        $this->notify($billable, fn () => new Notifications\TrialWillEnd($this->object($payload), $subscription));

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $object = $this->object($payload);
        $object['status'] = Subscription::STATUS_CANCELLED;
        $object['ended_at'] ??= Carbon::now()->toIso8601String();
        $payload['data']['object'] = $object;

        $subscription = $this->syncSubscription($payload, createIfMissing: false);

        event(new Events\SubscriptionDeleted($payload, $object, $this->previous($payload), $subscription->owner ?? $this->resolveBillable($object), $subscription));

        return $this->successMethod();
    }

    // ---------------------------------------------------------------------
    // Customers & payment methods
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerUpdated(array $payload): Response
    {
        $billable = $this->resolveBillable($this->object($payload));

        if ($billable !== null) {
            $billable->syncDefaultPaymentMethod($this->object($payload)['default_payment_method'] ?? null);

            $previous = $this->previous($payload);

            if ($previous !== null && array_key_exists('default_payment_method', $previous)) {
                event(new Events\PaymentMethodUpdated($payload, $this->object($payload), $previous, $billable));
            }
        }

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerDeleted(array $payload): Response
    {
        $billable = $this->resolveBillable($this->object($payload));

        if ($billable !== null) {
            $billable->ridleySubscriptions()->get()->each(function (Subscription $subscription) {
                $subscription->forceFill([
                    'ridley_status' => Subscription::STATUS_CANCELLED,
                    'ends_at' => $subscription->ends_at !== null && $subscription->ends_at->isPast() ? $subscription->ends_at : Carbon::now(),
                    'cancel_at_period_end' => false,
                ])->save();

                $subscription->afterSync(SubscriptionSynced::SOURCE_WEBHOOK);
            });

            $billable->ridleyCustomer()->first()?->forceFill([
                'ridley_id' => null,
                'pm_brand' => null,
                'pm_last_four' => null,
                'pm_exp_month' => null,
                'pm_exp_year' => null,
            ])->save();
        }

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handlePaymentMethodAttached(array $payload): Response
    {
        return $this->paymentMethodChanged($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handlePaymentMethodUpdated(array $payload): Response
    {
        return $this->paymentMethodChanged($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handlePaymentMethodDetached(array $payload): Response
    {
        return $this->paymentMethodChanged($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function paymentMethodChanged(array $payload): Response
    {
        $billable = $this->resolveBillable($this->object($payload));

        if ($billable !== null) {
            $billable->updateDefaultPaymentMethodFromRidley();

            event(new Events\PaymentMethodUpdated($payload, $this->object($payload), $this->previous($payload), $billable));
        }

        return $this->successMethod();
    }

    // ---------------------------------------------------------------------
    // Invoices
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleInvoicePaid(array $payload): Response
    {
        event(new Events\InvoicePaid($payload, $this->object($payload), $this->previous($payload), $this->resolveBillable($this->object($payload))));

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleInvoicePaymentFailed(array $payload): Response
    {
        $billable = $this->resolveBillable($this->object($payload));

        event(new Events\InvoicePaymentFailed($payload, $this->object($payload), $this->previous($payload), $billable));

        $this->notify($billable, fn () => new Notifications\InvoicePaymentFailed($this->object($payload)));

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleInvoicePaymentActionRequired(array $payload): Response
    {
        $billable = $this->resolveBillable($this->object($payload));

        event(new Events\InvoicePaymentActionRequired($payload, $this->object($payload), $this->previous($payload), $billable));

        $this->notify($billable, fn () => new Notifications\InvoicePaymentActionRequired($this->object($payload)));

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleInvoiceFinalized(array $payload): Response
    {
        event(new Events\InvoiceFinalized($payload, $this->object($payload), $this->previous($payload), $this->resolveBillable($this->object($payload))));

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleInvoiceUpcoming(array $payload): Response
    {
        event(new Events\InvoiceUpcoming($payload, $this->object($payload), $this->previous($payload), $this->resolveBillable($this->object($payload))));

        return $this->successMethod();
    }

    // ---------------------------------------------------------------------
    // Prices
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handlePriceCreated(array $payload): Response
    {
        return $this->syncPrice($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handlePriceUpdated(array $payload): Response
    {
        return $this->syncPrice($payload);
    }

    /**
     * Mirror a price into the local catalogue, ignoring prices from the other mode.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function syncPrice(array $payload): Response
    {
        $object = $this->object($payload);
        $object['id'] ??= $object['price_id'] ?? null;
        $key = (string) config('ridley.key', '');

        if (! is_string($object['id']) || $object['id'] === '') {
            return $this->successMethod();
        }

        if (is_bool($object['livemode'] ?? null) && (str_starts_with($key, 'sk_live_') || str_starts_with($key, 'sk_test_')) && $object['livemode'] !== str_starts_with($key, 'sk_live_')) {
            return $this->successMethod();
        }

        Ridley::catalogue()->syncPrice(new Price($object));

        return $this->successMethod();
    }

    // ---------------------------------------------------------------------
    // Payments
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handlePaymentCompleted(array $payload): Response
    {
        event(new Events\PaymentCompleted($payload, $this->object($payload), $this->previous($payload), $this->resolveBillable($this->object($payload))));

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handlePaymentFailed(array $payload): Response
    {
        event(new Events\PaymentFailed($payload, $this->object($payload), $this->previous($payload), $this->resolveBillable($this->object($payload))));

        return $this->successMethod();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Find (or create, when the billable is known) the local subscription and sync it.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function syncSubscription(array $payload, bool $createIfMissing = true): ?Subscription
    {
        return SubscriptionMirror::sync($this->object($payload), null, SubscriptionSynced::SOURCE_WEBHOOK, $createIfMissing);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    protected function resolveBillable(array $object): ?Model
    {
        return SubscriptionMirror::billable($object);
    }

    /**
     * Send a dunning notification to the billable's notifiables when enabled.
     *
     * @param  callable(): RidleyBillingNotification  $notification
     */
    protected function notify(?Model $billable, callable $notification): void
    {
        if ($billable === null || ! config('ridley.notifications.enabled')) {
            return;
        }

        /** @var iterable<int, object> $recipients */
        $recipients = $billable->ridleyNotifiables();
        $notifiables = collect($recipients)->filter()->values();

        if ($notifiables->isNotEmpty()) {
            Notification::send($notifiables, $notification()->forBillable($billable));
        }
    }

    /**
     * Normalise legacy flat payloads (`{"event_id", "event", ...fields}`) into the envelope shape.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalisePayload(array $payload): array
    {
        if (isset($payload['type'], $payload['data']) && is_array($payload['data'])) {
            return $payload;
        }

        $object = $payload;
        unset($object['event_id'], $object['event']);

        return [
            'id' => $payload['event_id'] ?? $payload['id'] ?? null,
            'type' => (string) ($payload['event'] ?? $payload['type'] ?? ''),
            'created' => Carbon::now()->getTimestamp(),
            'livemode' => (bool) ($payload['livemode'] ?? false),
            'api_version' => null,
            'data' => [
                'object' => $object,
                'previous_attributes' => null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function object(array $payload): array
    {
        $object = $payload['data']['object'] ?? [];

        return is_array($object) ? $object : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    protected function previous(array $payload): ?array
    {
        $previous = $payload['data']['previous_attributes'] ?? null;

        return is_array($previous) ? $previous : null;
    }

    protected function alreadyProcessed(string $eventId): bool
    {
        return $this->webhookEvents()->where('event_id', $eventId)->whereNotNull('processed_at')->exists();
    }

    protected function markProcessed(?string $eventId, string $type): void
    {
        if ($eventId === null) {
            return;
        }

        $now = Carbon::now();

        try {
            $this->webhookEvents()->updateOrInsert(
                ['event_id' => $eventId],
                ['type' => $type, 'processed_at' => $now, 'updated_at' => $now, 'created_at' => $now],
            );
        } catch (UniqueConstraintViolationException) {
            // Processed concurrently by another worker.
        }
    }

    protected function webhookEvents(): Builder
    {
        return DB::table((string) config('ridley.tables.webhook_events', 'ridley_webhook_events'));
    }

    protected function successMethod(): Response
    {
        return new Response('Webhook Handled', 200);
    }

    protected function missingMethod(): Response
    {
        return new Response('Webhook Skipped', 200);
    }
}
