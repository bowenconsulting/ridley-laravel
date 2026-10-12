<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Ridley\Laravel\Events\InvoicePaymentFailed;
use Ridley\Laravel\Events\PaymentCompleted;
use Ridley\Laravel\Events\SubscriptionCreated;
use Ridley\Laravel\Events\SubscriptionDeleted;
use Ridley\Laravel\Events\SubscriptionSynced;
use Ridley\Laravel\Events\WebhookHandled;
use Ridley\Laravel\Events\WebhookReceived;
use Ridley\Laravel\Notifications\InvoicePaymentActionRequired as ActionRequiredNotification;
use Ridley\Laravel\Notifications\InvoicePaymentFailed as PaymentFailedNotification;
use Ridley\Laravel\Notifications\TrialWillEnd;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\Subscription;
use Ridley\Laravel\Tests\Fixtures\LegacyPlan;
use Ridley\Laravel\Tests\Fixtures\Organization;
use Ridley\Laravel\Tests\Fixtures\SluggedUser;
use Ridley\Laravel\WebhookSignature;

function subscriptionObject(string $customer, array $overrides = []): array
{
    return array_merge([
        'subscription_id' => 'sub_1',
        'customer' => $customer,
        'status' => 'active',
        'currency' => 'USD',
        'interval' => 'month',
        'interval_count' => 1,
        'current_period_start' => Carbon::now()->toIso8601String(),
        'current_period_end' => Carbon::now()->addMonth()->toIso8601String(),
        'trial_start' => null,
        'trial_end' => null,
        'cancel_at_period_end' => false,
        'cancelled_at' => null,
        'ended_at' => null,
        'items' => [['subscription_item_id' => 'si_1', 'price' => 'price_1', 'quantity' => 1, 'unit_amount' => '49.00']],
        'latest_invoice' => null,
        'metadata' => ['type' => 'default'],
    ], $overrides);
}

function invoiceObject(string $customer, array $overrides = []): array
{
    return array_merge([
        'invoice_id' => 'in_1',
        'status' => 'open',
        'customer_id' => $customer,
        'subscription_id' => 'sub_1',
        'currency' => 'USD',
        'total' => '49.00',
        'amount_due' => '49.00',
        'attempt_count' => 1,
        'next_payment_attempt' => Carbon::now()->addDays(3)->toIso8601String(),
        'payment_url' => 'https://pay.example.com/in_1',
        'metadata' => [],
    ], $overrides);
}

it('rejects invalid, missing and expired signatures', function () {
    $payload = json_encode(Ridley::webhookPayload('invoice.paid', invoiceObject('cus_1')));

    $this->call('POST', route('ridley.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertForbidden();
    $this->call('POST', route('ridley.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_SIGNATURE' => WebhookSignature::sign($payload, 'whsec_wrong'),
    ], $payload)->assertForbidden();
    $this->call('POST', route('ridley.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_SIGNATURE' => WebhookSignature::sign($payload, 'whsec_test', Carbon::now()->subHour()->getTimestamp()),
    ], $payload)->assertForbidden();
});

it('accepts any of several rotated secrets', function () {
    config(['ridley.webhook.secret' => 'whsec_new,whsec_old']);
    $payload = json_encode(Ridley::webhookPayload('ping', []));

    $this->call('POST', route('ridley.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_SIGNATURE' => WebhookSignature::sign($payload, 'whsec_old'),
    ], $payload)->assertOk();
});

it('creates, updates and deletes the local subscription mirror', function () {
    Event::fake([SubscriptionCreated::class, SubscriptionDeleted::class, SubscriptionSynced::class, WebhookReceived::class, WebhookHandled::class]);
    $user = $this->user(['ridley_customer_id' => 'cus_1']);

    $this->postRidleyWebhook('customer.subscription.created', subscriptionObject('cus_1'))->assertOk();

    $subscription = $user->ridleySubscription();
    expect($subscription->ridley_id)->toBe('sub_1')
        ->and($subscription->items)->toHaveCount(1)
        ->and($user->subscribed())->toBeTrue()
        ->and($user->fresh()->ridley_currency)->toBe('USD');

    Event::assertDispatched(SubscriptionCreated::class, fn ($event) => $event->billable->is($user) && $event->subscription->is($subscription));
    Event::assertDispatched(SubscriptionSynced::class, fn ($event) => $event->source === 'webhook');
    Event::assertDispatched(WebhookHandled::class);

    $this->postRidleyWebhook('customer.subscription.updated', subscriptionObject('cus_1', [
        'status' => 'past_due',
        'items' => [
            ['subscription_item_id' => 'si_1', 'price' => 'price_1', 'quantity' => 3, 'unit_amount' => '49.00'],
            ['subscription_item_id' => 'si_2', 'price' => 'price_2', 'quantity' => 1, 'unit_amount' => '5.00'],
        ],
    ]))->assertOk();

    $subscription->refresh();
    expect($subscription->pastDue())->toBeTrue()
        ->and($subscription->items)->toHaveCount(2)
        ->and($subscription->quantity)->toBeNull();

    $this->postRidleyWebhook('customer.subscription.deleted', subscriptionObject('cus_1'))->assertOk();

    expect($subscription->fresh()->ended())->toBeTrue()
        ->and($user->fresh()->subscribed())->toBeFalse();
    Event::assertDispatched(SubscriptionDeleted::class);
});

it('maps subscriptions via metadata when the customer is unknown', function () {
    $user = $this->user();

    $this->postRidleyWebhook('customer.subscription.created', subscriptionObject('cus_unknown', [
        'metadata' => ['type' => 'main', 'billable_type' => $user->getMorphClass(), 'billable_id' => (string) $user->getKey()],
    ]))->assertOk();

    expect($user->ridleySubscription('main'))->not->toBeNull();
});

it('ignores duplicate deliveries', function () {
    Event::fake([WebhookHandled::class]);
    $this->user(['ridley_customer_id' => 'cus_1']);

    $this->postRidleyWebhook('customer.subscription.created', subscriptionObject('cus_1'), eventId: 'evt_1')->assertOk();
    $this->postRidleyWebhook('customer.subscription.created', subscriptionObject('cus_1'), eventId: 'evt_1')
        ->assertOk()->assertSee('Already Processed');

    Event::assertDispatchedTimes(WebhookHandled::class, 1);
});

it('accepts the legacy flat payload shape', function () {
    $this->user(['ridley_customer_id' => 'cus_1']);
    $payload = json_encode(array_merge(['event_id' => 'evt_legacy', 'event' => 'customer.subscription.created'], subscriptionObject('cus_1')));

    $this->call('POST', route('ridley.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_SIGNATURE' => WebhookSignature::sign($payload, 'whsec_test'),
    ], $payload)->assertOk();

    expect(Subscription::query()->count())->toBe(1);
});

it('works for billables that define their own subscription() relation', function () {
    config(['ridley.model' => Organization::class]);
    $organization = Organization::query()->create(['name' => 'Acme', 'ridley_customer_id' => 'cus_org']);
    LegacyPlan::query()->create(['organization_id' => $organization->getKey(), 'name' => 'legacy']);

    expect($organization->subscription)->toBeInstanceOf(LegacyPlan::class)
        ->and($organization->subscribed())->toBeFalse();

    $this->postRidleyWebhook('customer.subscription.created', subscriptionObject('cus_org', [
        'trial_start' => Carbon::now()->toIso8601String(),
        'trial_end' => Carbon::now()->addWeek()->toIso8601String(),
        'status' => 'trialing',
    ]))->assertOk();

    $organization->refresh();

    expect($organization->subscribed())->toBeTrue()
        ->and($organization->onTrial())->toBeTrue()
        ->and($organization->ridleySubscription()->ridley_id)->toBe('sub_1')
        ->and($organization->subscription)->toBeInstanceOf(LegacyPlan::class);

    $this->postRidleyWebhook('customer.subscription.deleted', subscriptionObject('cus_org'))->assertOk();

    expect($organization->fresh()->subscribed())->toBeFalse();
});

it('dispatches typed invoice and payment events with purpose', function () {
    Event::fake([InvoicePaymentFailed::class, PaymentCompleted::class]);
    $user = $this->user(['ridley_customer_id' => 'cus_1']);

    $this->postRidleyWebhook('invoice.payment_failed', invoiceObject('cus_1'))->assertOk();
    Event::assertDispatched(InvoicePaymentFailed::class, fn ($event) => $event->billable->is($user)
        && $event->invoiceId() === 'in_1' && $event->paymentUrl() === 'https://pay.example.com/in_1');

    // Checkout payments carry no customer_id; the metadata morph resolves the billable.
    $this->postRidleyWebhook('payment.completed', [
        'payment_id' => 'pay_1', 'amount' => '25.50', 'currency' => 'USD', 'status' => 'PAID', 'customer_id' => null,
        'metadata' => ['purpose' => 'top_up', 'billable_type' => $user->getMorphClass(), 'billable_id' => (string) $user->getKey()],
    ])->assertOk();

    Event::assertDispatched(PaymentCompleted::class, fn (PaymentCompleted $event) => $event->billable?->is($user)
        && $event->purpose() === 'top_up'
        && $event->paymentId() === 'pay_1'
        && $event->amountInMinorUnits() === 2550
        && $event->currency() === 'USD'
        && $event->metadata()['purpose'] === 'top_up');
});

it('sends dunning notifications only when enabled', function () {
    Notification::fake();
    $user = $this->user(['ridley_customer_id' => 'cus_1']);

    $this->postRidleyWebhook('invoice.payment_failed', invoiceObject('cus_1'), eventId: 'evt_a')->assertOk();
    Notification::assertNothingSent();

    config(['ridley.notifications.enabled' => true]);

    $this->postRidleyWebhook('invoice.payment_failed', invoiceObject('cus_1'), eventId: 'evt_b')->assertOk();
    $this->postRidleyWebhook('invoice.payment_action_required', invoiceObject('cus_1'), eventId: 'evt_c')->assertOk();
    $this->postRidleyWebhook('customer.subscription.trial_will_end', subscriptionObject('cus_1', [
        'status' => 'trialing', 'trial_end' => Carbon::now()->addDays(3)->toIso8601String(),
    ]), eventId: 'evt_d')->assertOk();

    Notification::assertSentTo($user, PaymentFailedNotification::class, function (PaymentFailedNotification $notification) use ($user) {
        $html = (string) $notification->toMail($user)->render();

        return $notification->actionUrl() === 'https://pay.example.com/in_1'
            && str_contains($html, 'https://pay.example.com/in_1')
            && str_contains($html, '$49.00');
    });
    Notification::assertSentTo($user, ActionRequiredNotification::class);
    Notification::assertSentTo($user, TrialWillEnd::class);
});

it('renders the notification mail with the fallback billing url', function () {
    config(['ridley.billing_url' => 'https://app.example.com/billing']);
    $user = $this->user();

    $mail = (new TrialWillEnd(subscriptionObject('cus_1', ['trial_end' => Carbon::now()->addDays(3)->toIso8601String()])))->toMail($user);

    expect((string) $mail->render())->toContain('https://app.example.com/billing');
});

it('links notifications to the billable\'s own billing url', function () {
    config(['ridley.billing_url' => 'https://app.example.com/billing']);
    $user = SluggedUser::query()->findOrFail($this->user()->getKey());

    $mail = (new TrialWillEnd(subscriptionObject('cus_1', ['trial_end' => Carbon::now()->addDays(3)->toIso8601String()])))
        ->forBillable($user)
        ->toMail($user);

    expect((string) $mail->render())->toContain("https://app.example.com/accounts/{$user->getKey()}/billing");
});
