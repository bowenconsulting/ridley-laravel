<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Ridley\Laravel\Events\SubscriptionSynced;
use Ridley\Laravel\Exceptions\CurrencyLocked;
use Ridley\Laravel\Exceptions\IncompletePayment;
use Ridley\Laravel\Exceptions\PriceNotFound;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\Subscription;
use Ridley\Laravel\Tests\Fixtures\ExternalTrialUser;

it('creates a subscription from catalogue keys and mirrors it locally', function () {
    $fake = Ridley::fake();
    $this->syncCatalogue();
    Event::fake([SubscriptionSynced::class]);
    $user = $this->customerWithCard();

    $subscription = $user->newSubscription('default')
        ->catalogue('plan', 'professional', 'USD', 'month')
        ->catalogue('addon', 'extra_seat', 'USD', 'month', quantity: 2)
        ->withMetadata(['ref' => 'x'])
        ->create();

    expect($subscription)->toBeInstanceOf(Subscription::class)
        ->and($subscription->active())->toBeTrue()
        ->and($subscription->items)->toHaveCount(2)
        ->and($subscription->catalogueKey('plan'))->toBe('professional')
        ->and($subscription->quantityOf('addon', 'extra_seat'))->toBe(2)
        ->and($subscription->selection())->toBe(['plan' => ['professional' => 1], 'addon' => ['extra_seat' => 2]])
        ->and($subscription->itemInGroup('plan')->price->item_key)->toBe('professional')
        ->and($subscription->itemInGroup('plan')->group())->toBe('plan')
        ->and($user->fresh()->ridley_currency)->toBe('USD');

    $remote = $fake->find($subscription->ridley_id);
    expect($remote['metadata'])->toMatchArray(['type' => 'default', 'billable_type' => $user->getMorphClass(), 'ref' => 'x']);

    Event::assertDispatched(SubscriptionSynced::class, fn (SubscriptionSynced $event) => $event->source === 'api' && $event->billable->is($user));

    expect($user->subscribed())->toBeTrue()
        ->and($user->subscribedToCatalogue('plan', 'professional'))->toBeTrue()
        ->and($user->subscribedToCatalogue('plan', 'starter'))->toBeFalse()
        ->and($user->subscribedToPrice($subscription->itemInGroup('plan')->ridley_price))->toBeTrue();
});

it('accepts Price models, ids and lookup keys', function () {
    $fake = Ridley::fake();
    $this->syncCatalogue();
    $user = $this->customerWithCard();
    $starter = Ridley::catalogue()->price('plan', 'starter', 'USD', 'month');

    $user->newSubscription('default', $starter)->price('addon.extra_seat.usd.month', 3)->create();

    $fake->assertCreated('subscriptions', fn (array $params) => $params['items'] === [
        ['price' => $starter->ridley_price_id, 'quantity' => 1],
        ['price' => Ridley::catalogue()->price('addon', 'extra_seat', 'USD', 'month')->ridley_price_id, 'quantity' => 3],
    ]);
});

it('defers catalogue currency to the billing currency', function () {
    $fake = Ridley::fake();
    $this->syncCatalogue();
    $user = $this->customerWithCard(['ridley_currency' => 'EUR']);

    $user->newSubscription('default')->catalogue('plan', 'professional', null, 'month')->create();

    expect($user->ridleySubscription()->ridley_price)->toBe(Ridley::catalogue()->price('plan', 'professional', 'EUR', 'month')->ridley_price_id);

    expect(fn () => $user->newSubscription('other')->catalogue('plan', 'starter', null, 'month')->create())
        ->toThrow(PriceNotFound::class);
});

it('rejects a different currency once locked', function () {
    Ridley::fake();
    $this->syncCatalogue();
    $user = $this->customerWithCard(['ridley_currency' => 'EUR']);

    expect(fn () => $user->newSubscription('default')->catalogue('plan', 'starter', 'USD', 'month')->create())
        ->toThrow(CurrencyLocked::class);
});

it('handles trials', function () {
    $fake = Ridley::fake();
    $this->syncCatalogue();
    $user = $this->customerWithCard();

    $subscription = $user->newSubscription('default')->catalogue('plan', 'starter', 'USD', 'month')->trialDays(14)->create();

    expect($subscription->onTrial())->toBeTrue()
        ->and($subscription->ridley_status)->toBe('trialing')
        ->and($user->onTrial())->toBeTrue()
        ->and($user->subscribed())->toBeTrue();

    $user->newSubscription('past')->catalogue('plan', 'starter', 'USD', 'month')->trialUntilIfFuture(Carbon::now()->subDay())->create();
    $fake->assertCreated('subscriptions', fn (array $params) => ($params['metadata']['type'] ?? null) === 'past' && ! isset($params['trial_end']));

    $user->update(['trial_ends_at' => Carbon::now()->addDays(5)]);
    $user->newSubscription('generic')->catalogue('plan', 'starter', 'USD', 'month')->trialUntilIfFuture()->create();
    expect($user->ridleySubscription('generic')->trial_ends_at->isSameDay(Carbon::now()->addDays(5)))->toBeTrue();
});

it('takes the trial end from an overridden genericTrialEndsAt', function () {
    Ridley::fake();
    $this->syncCatalogue();
    $user = ExternalTrialUser::query()->findOrFail($this->customerWithCard()->getKey());
    ExternalTrialUser::$trialEndsAt = CarbonImmutable::now()->addDays(9);

    try {
        $subscription = $user->newSubscription('default')->catalogue('plan', 'starter', 'USD', 'month')->trialUntilIfFuture()->create();

        expect($user->getAttribute('trial_ends_at'))->toBeNull()
            ->and($user->onGenericTrial())->toBeTrue()
            ->and($subscription->trial_ends_at->isSameDay(CarbonImmutable::now()->addDays(9)))->toBeTrue();
    } finally {
        ExternalTrialUser::$trialEndsAt = null;
    }
});

it('subscribes or checks out in one call', function () {
    $fake = Ridley::fake();
    $this->syncCatalogue();
    $user = $this->user();

    $result = $user->newSubscription('default')->catalogue('plan', 'starter', 'USD', 'month')
        ->createOrCheckout('https://app.example.com/ok', 'https://app.example.com/cancel');

    expect($result->requiresCheckout())->toBeTrue()
        ->and($result->subscription())->toBeNull()
        ->and($result->redirectUrl())->toBe($result->checkout()->url())
        ->and($user->fresh()->hasRidleyId())->toBeTrue();

    $fake->assertCreated('sessions', fn (array $params) => $params['mode'] === 'subscription' && $params['customer'] === $user->fresh()->ridleyId());

    $card = $this->customerWithCard(['email' => 'card@example.com']);
    $result = $card->newSubscription('default')->catalogue('plan', 'starter', 'USD', 'month')
        ->createOrCheckout('https://app.example.com/ok', 'https://app.example.com/cancel');

    expect($result->requiresCheckout())->toBeFalse()
        ->and($result->subscription()->active())->toBeTrue()
        ->and($result->redirectUrl())->toBeNull();
});

it('throws IncompletePayment when the first payment needs action', function () {
    $fake = Ridley::fake();
    $invoice = $fake->addInvoice(['status' => 'open', 'payment_url' => 'https://pay.example.com/in_1']);
    $fake->override(['POST subscriptions' => fn () => [
        'data' => ['id' => 'sub_x', 'type' => 'subscriptions', 'attributes' => [
            'customer' => 'cus_fake_1', 'status' => 'incomplete', 'currency' => 'USD', 'interval' => 'month', 'interval_count' => 1,
            'items' => [], 'metadata' => ['type' => 'default'], 'latest_invoice' => $invoice['id'],
        ]],
    ]]);
    $this->syncCatalogue();
    $user = $this->customerWithCard();

    try {
        $user->newSubscription('default')->catalogue('plan', 'starter', 'USD', 'month')->create();
        $this->fail('Expected IncompletePayment');
    } catch (IncompletePayment $exception) {
        expect($exception->paymentUrl())->toBe('https://pay.example.com/in_1');
    }

    expect($user->ridleySubscription()->hasIncompletePayment())->toBeTrue()
        ->and($user->hasIncompletePayment())->toBeTrue()
        ->and($user->subscribed())->toBeFalse();
});
