<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Ridley\Laravel\Events\SubscriptionSynced;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\Subscription;
use Ridley\Laravel\Testing\FakeRidley;

beforeEach(function () {
    $this->fake = Ridley::fake();
    $this->syncCatalogue();
    $this->owner = $this->customerWithCard();
    $this->subscription = $this->owner->newSubscription('default')
        ->catalogue('plan', 'professional', 'USD', 'month')
        ->create();
});

function price(string $group, string $key, string $interval = 'month'): string
{
    return Ridley::catalogue()->price($group, $key, 'USD', $interval)->ridley_price_id;
}

it('reports status helpers', function () {
    $subscription = $this->subscription;

    expect($subscription->active())->toBeTrue()
        ->and($subscription->valid())->toBeTrue()
        ->and($subscription->recurring())->toBeTrue()
        ->and($subscription->onTrial())->toBeFalse()
        ->and($subscription->pastDue())->toBeFalse()
        ->and($subscription->incomplete())->toBeFalse()
        ->and($subscription->canceled())->toBeFalse()
        ->and($subscription->onGracePeriod())->toBeFalse()
        ->and($subscription->ended())->toBeFalse()
        ->and($subscription->hasPrice(price('plan', 'professional')))->toBeTrue()
        ->and($subscription->hasCatalogueItem('plan', 'professional'))->toBeTrue()
        ->and($subscription->hasCatalogueItem('plan', 'starter'))->toBeFalse();

    $subscription->forceFill(['ridley_status' => 'past_due'])->save();

    expect($subscription->pastDue())->toBeTrue()
        ->and($subscription->active())->toBeFalse()
        ->and($subscription->live())->toBeTrue()
        ->and($subscription->manageable())->toBeTrue()
        ->and(Subscription::query()->pastDue()->count())->toBe(1);

    $subscription->forceFill(['ridley_status' => 'incomplete'])->save();

    expect($subscription->live())->toBeTrue()->and($subscription->manageable())->toBeFalse();

    $subscription->forceFill(['ridley_status' => 'incomplete_expired'])->save();

    expect($subscription->live())->toBeFalse();
});

it('cancels at period end, resumes and cancels now', function () {
    Event::fake([SubscriptionSynced::class]);
    $subscription = $this->subscription;

    $subscription->cancel();

    expect($subscription->canceled())->toBeTrue()
        ->and($subscription->onGracePeriod())->toBeTrue()
        ->and($subscription->valid())->toBeTrue()
        ->and($this->owner->subscribed())->toBeTrue();

    $subscription->resume();

    expect($subscription->canceled())->toBeFalse()->and($subscription->ends_at)->toBeNull();

    $subscription->cancelNow();

    expect($subscription->ended())->toBeTrue()
        ->and($this->owner->fresh()->subscribed())->toBeFalse();

    Event::assertDispatchedTimes(SubscriptionSynced::class, 3);
});

it('swaps and updates quantities', function () {
    $subscription = $this->subscription;

    $subscription->swap([price('plan', 'starter'), price('addon', 'extra_seat') => ['quantity' => 2]]);

    expect($subscription->selection())->toBe(['plan' => ['starter' => 1], 'addon' => ['extra_seat' => 2]]);

    $subscription->incrementQuantity(1, price('addon', 'extra_seat'));
    expect($subscription->quantityOf('addon', 'extra_seat'))->toBe(3);

    $subscription->removePrice(price('addon', 'extra_seat'));
    expect($subscription->fresh()->items)->toHaveCount(1);
});

it('applies the proration policy to increases and decreases', function () {
    $subscription = $this->subscription;
    $fake = app(FakeRidley::class);

    $upgrade = Ridley::catalogue()->resolve('USD', 'month', [
        ['group' => 'plan', 'key' => 'professional'],
        ['group' => 'addon', 'key' => 'extra_seat', 'quantity' => 2],
    ]);

    expect($subscription->isIncrease($upgrade))->toBeTrue()
        ->and($subscription->changeProrationBehaviorFor($upgrade))->toBe('always_invoice');

    $preview = $subscription->previewChange($upgrade);
    $fake->assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains(urldecode($request->url()), 'subscription_proration_behavior=always_invoice')
        && str_contains(urldecode($request->url()), 'subscription_items[0]'));
    expect($preview->rawTotal())->toBeGreaterThan(0);

    $subscription->change($upgrade);
    $fake->assertUpdated('subscriptions', $subscription->ridley_id, fn (array $params) => $params['proration_behavior'] === 'always_invoice');

    $downgrade = Ridley::catalogue()->resolve('USD', 'month', [['group' => 'plan', 'key' => 'starter']]);
    expect($subscription->changeProrationBehaviorFor($downgrade))->toBe('create_prorations');

    $subscription->change($downgrade);
    $fake->assertUpdated('subscriptions', $subscription->ridley_id, fn (array $params) => $params['proration_behavior'] === 'create_prorations');
    expect($subscription->selection())->toBe(['plan' => ['starter' => 1]]);

    $yearly = Ridley::catalogue()->resolve('USD', 'year', [['group' => 'plan', 'key' => 'starter']]);
    expect($subscription->isIncrease($yearly))->toBeTrue()
        ->and($subscription->isIncrease(['price_unknown' => ['quantity' => 1]]))->toBeTrue();

    $subscription->noProrate();
    expect($subscription->changeProrationBehaviorFor($upgrade))->toBe('none');

    config(['ridley.proration.decrease' => 'none']);
    expect($subscription->fresh()->changeProrationBehaviorFor($downgrade))->toBe('none');
});

it('dispatches SubscriptionSynced for every write', function () {
    Event::fake([SubscriptionSynced::class]);
    $subscription = $this->subscription;

    $subscription->updateQuantity(2);
    $subscription->change(Ridley::catalogue()->resolve('USD', 'month', [['group' => 'plan', 'key' => 'starter']]));

    Event::assertDispatchedTimes(SubscriptionSynced::class, 2);
    Event::assertDispatched(SubscriptionSynced::class, fn (SubscriptionSynced $event) => $event->subscription->is($subscription) && $event->source === 'api');
});

it('extends and ends trials', function () {
    $subscription = $this->subscription;

    $subscription->extendTrial(Carbon::now()->addDays(10));

    expect($subscription->onTrial())->toBeTrue();

    $subscription->endTrial();

    expect($subscription->onTrial())->toBeFalse();
});

it('reads invoices for the subscription', function () {
    $fake = app(FakeRidley::class);
    $fake->addInvoice(['customer' => $this->owner->ridleyId(), 'subscription' => $this->subscription->ridley_id]);
    $fake->addInvoice(['customer' => $this->owner->ridleyId()]);

    expect($this->subscription->invoices())->toHaveCount(1)
        ->and($this->subscription->upcomingInvoice()?->total())->toBe('$49.00');
});
