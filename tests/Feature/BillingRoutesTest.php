<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\RidleyServiceProvider;
use Ridley\Laravel\Testing\FakeRidley;

beforeEach(function () {
    $this->fake = Ridley::fake();
    $this->syncCatalogue();
});

function selection(string $plan = 'starter', int $seats = 0): array
{
    return [
        'interval' => 'month',
        'items' => array_values(array_filter([
            ['group' => 'plan', 'key' => $plan, 'quantity' => 1],
            $seats > 0 ? ['group' => 'addon', 'key' => 'extra_seat', 'quantity' => $seats] : null,
        ])),
    ];
}

it('requires authentication', function () {
    $this->postJson(route('ridley.subscribe'), selection())->assertUnauthorized();
});

it('honours the authorization hook', function () {
    Ridley::authorizeUsing(fn (Request $request, $billable, string $ability) => $ability !== 'subscribe');

    $this->actingAs($this->user())->postJson(route('ridley.subscribe'), selection())->assertForbidden();
});

it('returns 409 with a Location for checkout when there is no card', function () {
    $user = $this->user();

    $response = $this->actingAs($user)->postJson(route('ridley.subscribe'), selection());

    $response->assertStatus(409)->assertJsonPath('requires_redirect', true);
    expect($response->headers->get('Location'))->toBe($response->json('redirect_url'));

    expect($this->actingAs($user)->post(route('ridley.subscribe'), selection())->assertRedirect()->headers->get('Location'))
        ->toStartWith('https://checkout.example.com/');
    $this->actingAs($user)->post(route('ridley.subscribe'), selection(), ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location');
});

it('subscribes immediately with a saved card', function () {
    $user = $this->customerWithCard();

    $this->actingAs($user)->postJson(route('ridley.subscribe'), selection('professional', 2))
        ->assertOk()
        ->assertJsonPath('subscription.selection', ['plan' => ['professional' => 1], 'addon' => ['extra_seat' => 2]]);

    $this->actingAs($user)->postJson(route('ridley.subscribe'), selection())->assertUnprocessable()->assertJsonValidationErrors('items');
});

it('redirects with a flashed status for browser requests', function () {
    Ridley::afterActionRedirect(fn (Request $request, string $action) => "https://app.example.com/after/{$action}");
    $user = $this->customerWithCard();

    $this->actingAs($user)->post(route('ridley.subscribe'), selection())
        ->assertRedirect('https://app.example.com/after/subscribe')
        ->assertSessionHas('status', 'Subscribed.');
});

it('validates selections against the catalogue, the sellable hook and max_quantity', function () {
    $user = $this->customerWithCard();

    $this->actingAs($user)->postJson(route('ridley.subscribe'), ['interval' => 'month', 'items' => []])->assertJsonValidationErrors('items');
    $this->actingAs($user)->postJson(route('ridley.subscribe'), selection('enterprise'))->assertJsonValidationErrors('items.0.key');
    $this->actingAs($user)->postJson(route('ridley.subscribe'), selection('starter', 6))->assertJsonValidationErrors('items.1.quantity');
    $this->actingAs($user)->postJson(route('ridley.subscribe'), selection() + ['currency' => 'EUR'])->assertJsonValidationErrors('items.0.key');

    Ridley::sellableUsing(fn ($billable, string $group, string $key) => $key !== 'professional');
    $this->actingAs($user)->postJson(route('ridley.subscribe'), selection('professional'))->assertJsonValidationErrors('items.0.key');

    $this->actingAs($user)->post(route('ridley.subscribe'), ['interval' => 'month'])->assertSessionHasErrors('items');
});

it('previews, changes, cancels and resumes', function () {
    $user = $this->customerWithCard();
    $this->actingAs($user);

    $this->postJson(route('ridley.preview'), selection('professional'))
        ->assertOk()
        ->assertJsonPath('next_invoice.total', '49.00')
        ->assertJsonPath('proration_behavior', null);

    $this->postJson(route('ridley.subscribe'), selection())->assertOk();

    $this->postJson(route('ridley.preview'), selection('professional', 1))
        ->assertOk()
        ->assertJsonPath('proration_behavior', 'always_invoice')
        ->assertJsonStructure(['amount_due_now', 'amount_due_now_formatted', 'next_invoice' => ['total'], 'lines']);

    $this->putJson(route('ridley.subscription.update'), selection('professional', 1))
        ->assertOk()
        ->assertJsonPath('subscription.selection.plan', ['professional' => 1]);

    app(FakeRidley::class)->assertUpdated('subscriptions', $user->ridleySubscription()->ridley_id, fn (array $params) => $params['proration_behavior'] === 'always_invoice');

    $this->deleteJson(route('ridley.subscription.cancel'))->assertOk()->assertJsonPath('subscription.on_grace_period', true);
    $this->postJson(route('ridley.subscription.resume'))->assertOk()->assertJsonPath('subscription.on_grace_period', false);
});

it('manages a past-due subscription instead of starting another', function () {
    $user = $this->customerWithCard();
    $this->actingAs($user)->postJson(route('ridley.subscribe'), selection())->assertOk();
    $user->ridleySubscription()->forceFill(['ridley_status' => 'past_due'])->save();

    $this->postJson(route('ridley.subscribe'), selection('professional'))->assertJsonValidationErrors('items');
    $this->postJson(route('ridley.preview'), selection('professional'))->assertOk()->assertJsonPath('proration_behavior', 'always_invoice');
    $this->putJson(route('ridley.subscription.update'), selection('professional'))->assertOk();
    $this->deleteJson(route('ridley.subscription.cancel'))->assertOk();
});

it('fails to change without a subscription', function () {
    $this->actingAs($this->customerWithCard())->putJson(route('ridley.subscription.update'), selection())->assertUnprocessable();
});

it('redirects to hosted payment-method and portal pages', function () {
    $user = $this->user();
    $this->actingAs($user);

    $this->postJson(route('ridley.payment-method'))->assertStatus(409);
    $this->get(route('ridley.portal'))->assertRedirect();

    $this->fake->assertCreated('sessions', fn (array $params) => $params['mode'] === 'setup');
    $this->fake->assertCreated('portal-sessions');
});

it('lists and downloads only the billable\'s invoices', function () {
    $user = $this->customerWithCard();
    $mine = $this->fake->addInvoice(['customer' => $user->ridleyId()]);
    $theirs = $this->fake->addInvoice(['customer' => 'cus_someone_else']);
    $this->actingAs($user);

    $this->getJson(route('ridley.invoices.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine['id']);

    $this->get(route('ridley.invoices.show', $mine['id']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('ridley.invoices.show', $theirs['id']))->assertNotFound();
});

it('updates billing details', function () {
    $user = $this->customerWithCard();

    $this->actingAs($user)->putJson(route('ridley.details'), ['name' => 'Ada Ltd', 'tax_id' => 'GB123', 'address' => ['country' => 'GB']])->assertOk();

    expect($this->fake->find($user->ridleyId()))
        ->name->toBe('Ada Ltd')
        ->metadata->toMatchArray(['tax_id' => 'GB123']);

    $this->actingAs($user)->putJson(route('ridley.details'), ['email' => 'nope'])->assertJsonValidationErrors('email');
});

it('syncs the subscription after checkout success', function () {
    $user = $this->user();
    $this->actingAs($user);

    $this->postJson(route('ridley.subscribe'), selection())->assertStatus(409);
    $session = collect($this->fake->all('sessions'))->last();
    $this->fake->completeCheckout($session['id']);

    $this->getJson(route('ridley.checkout.success', ['session_id' => $session['id']]))
        ->assertOk()
        ->assertJsonPath('subscription.status', 'active');

    expect($user->fresh()->subscribed())->toBeTrue()
        ->and($user->fresh()->hasDefaultPaymentMethod())->toBeTrue();
});

it('supports a prefix with route parameters', function () {
    config(['ridley.routes.prefix' => 'accounts/{account}/billing']);
    app('router')->setRoutes(new RouteCollection);
    (fn () => $this->registerRoutes())->call(app()->getProvider(RidleyServiceProvider::class));
    app('router')->getRoutes()->refreshNameLookups();

    $user = $this->user();

    $this->actingAs($user)->postJson('/accounts/acme/billing/subscribe', selection())->assertStatus(409);

    expect($this->fake->all('sessions')[collect($this->fake->all('sessions'))->keys()->last()]['success_url'])
        ->toContain('/accounts/acme/billing/checkout/success?ridley_checkout=');
});

function successPath(array $session): string
{
    $parts = parse_url((string) $session['success_url']);

    return $parts['path'].'?'.$parts['query'];
}

it('finds the session through the ridley_checkout reference on return', function () {
    $user = $this->user();
    $this->actingAs($user);

    $this->postJson(route('ridley.subscribe'), selection())->assertStatus(409);
    $session = collect($this->fake->all('sessions'))->last();
    $this->fake->completeCheckout($session['id']);

    $this->getJson(successPath($session))
        ->assertOk()
        ->assertJsonPath('subscription.status', 'active');

    expect($user->fresh()->subscribed())->toBeTrue();
    $this->fake->assertNotSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), 'subscriptions?'));
});

it('reports the payment of a payment-mode checkout', function () {
    $user = $this->user();
    $this->actingAs($user);

    $result = $user->payOrCheckout('30.00', ['purpose' => 'top_up'], 'https://app.example.com/billing/checkout/success', 'https://app.example.com/cancel');
    $this->fake->completeCheckout($result->checkout()->session->id());
    $session = $this->fake->find($result->checkout()->session->id());

    $this->getJson(successPath($session))
        ->assertOk()
        ->assertJsonPath('payment.succeeded', true)
        ->assertJsonPath('payment.purpose', 'top_up');
});

it('rejects a session_id belonging to another customer', function () {
    $other = $this->user(['email' => 'other@example.com']);
    $this->actingAs($other)->postJson(route('ridley.subscribe'), selection())->assertStatus(409);
    $session = collect($this->fake->all('sessions'))->last();
    $this->fake->completeCheckout($session['id']);

    $user = $this->customerWithCard();

    $this->actingAs($user)->getJson(route('ridley.checkout.success', ['session_id' => $session['id']]))->assertForbidden();
    expect($user->fresh()->ridleySubscriptions()->count())->toBe(0);
});

it('rejects a customer-less session pointing at a foreign subscription', function () {
    $other = $this->customerWithCard(['email' => 'other@example.com']);
    $foreign = $other->newSubscription('default')->catalogue('plan', 'starter', 'USD', 'month')->create();

    $this->fake->override(['GET sessions/cs_evil' => ['data' => ['id' => 'cs_evil', 'type' => 'sessions', 'attributes' => [
        'mode' => 'subscription', 'status' => 'complete', 'customer' => null, 'subscription' => $foreign->ridley_id,
    ]]]]);

    $user = $this->customerWithCard();

    $this->actingAs($user)->getJson(route('ridley.checkout.success', ['session_id' => 'cs_evil']))->assertForbidden();
    expect($user->fresh()->ridleySubscriptions()->count())->toBe(0)
        ->and($foreign->fresh()->billable_id)->toBe($other->getKey());
});

it('rejects a customer-less session pointing at a foreign payment', function () {
    $other = $this->user(['email' => 'other@example.com']);
    $result = $other->payOrCheckout('30.00', ['purpose' => 'top_up'], 'https://app.example.com/ok', 'https://app.example.com/cancel');
    $completed = $this->fake->completeCheckout($result->checkout()->session->id());

    $this->fake->override(['GET sessions/cs_evil' => ['data' => ['id' => 'cs_evil', 'type' => 'sessions', 'attributes' => [
        'mode' => 'payment', 'status' => 'complete', 'customer' => null, 'payment_id' => $completed['payment_id'],
    ]]]]);

    $user = $this->customerWithCard();

    $this->actingAs($user)->getJson(route('ridley.checkout.success', ['session_id' => 'cs_evil']))->assertForbidden();
});
