<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Ridley\Laravel\Customer;
use Ridley\Laravel\Exceptions\CardException;
use Ridley\Laravel\Exceptions\CurrencyLocked;
use Ridley\Laravel\Exceptions\InvalidCustomer;
use Ridley\Laravel\Ridley;

it('creates, updates and syncs the Ridley customer', function () {
    $fake = Ridley::fake();
    $user = $this->user();

    $customer = $user->createAsRidleyCustomer(['metadata' => ['source' => 'tests']]);

    expect($customer->id())->toBe($user->ridleyId())
        ->and($customer->email)->toBe('ada@example.com')
        ->and($customer->metadata['billable_type'])->toBe($user->getMorphClass())
        ->and((string) $customer->metadata['billable_id'])->toBe((string) $user->getKey());

    expect(fn () => $user->createAsRidleyCustomer())->toThrow(InvalidCustomer::class, 'already');

    $user->update(['name' => 'Ada King']);
    $user->syncRidleyCustomerDetails();

    expect($fake->find($user->ridleyId())['name'])->toBe('Ada King')
        ->and($user->createOrGetRidleyCustomer()->id())->toBe($user->ridleyId());
});

it('requires a customer before customer-scoped calls', function () {
    Ridley::fake();

    expect(fn () => $this->user()->asRidleyCustomer())->toThrow(InvalidCustomer::class);
});

it('manages payment methods and mirrors the default card', function () {
    $fake = Ridley::fake();
    $user = $this->user();
    $user->createAsRidleyCustomer();
    $first = $fake->addPaymentMethod($user->ridleyId(), ['last4' => '1111']);
    $second = $fake->addPaymentMethod($user->ridleyId(), ['last4' => '2222', 'brand' => 'mastercard']);

    expect($user->paymentMethods())->toHaveCount(2)
        ->and($user->hasDefaultPaymentMethod())->toBeFalse();

    $user->updateDefaultPaymentMethod($first['id']);

    expect($user->fresh()->ridleyCustomer->pm_last_four)->toBe('1111')
        ->and($user->defaultPaymentMethod()->id())->toBe($first['id']);

    $user->updateDefaultPaymentMethodFromRidley();
    expect($user->ridleyCustomer->pm_last_four)->toBe('1111');

    $user->deletePaymentMethod($first['id']);
    expect($user->fresh()->ridleyCustomer->pm_last_four)->toBeNull()
        ->and($user->findPaymentMethod($second['id'])?->id())->toBe($second['id']);
});

it('charges in major units with purpose metadata', function () {
    $fake = Ridley::fake();
    $user = $this->user();
    $user->createAsRidleyCustomer();
    $fake->addPaymentMethod($user->ridleyId());

    $payment = $user->charge('25.50', ['purpose' => 'top_up', 'metadata' => ['ref' => 'abc']]);

    expect($payment->isSucceeded())->toBeTrue()
        ->and($payment->amount())->toBe('$25.50');

    $fake->assertCharged(fn (array $params) => $params['amount'] === '25.50'
        && $params['currency'] === 'USD'
        && $params['metadata']['purpose'] === 'top_up'
        && $params['metadata']['ref'] === 'abc'
        && $params['metadata']['billable_type'] === $user->getMorphClass());
});

it('locks the billing currency on the first invoiced charge', function () {
    $fake = Ridley::fake();
    $user = $this->user();
    $user->createAsRidleyCustomer();
    $fake->addPaymentMethod($user->ridleyId());

    expect($user->hasLockedCurrency())->toBeFalse()
        ->and($user->billingCurrency())->toBe('USD');

    $user->chargeWithInvoice('10.00', ['currency' => 'EUR']);

    expect($user->fresh()->ridleyCustomer->currency)->toBe('EUR')
        ->and($user->fresh()->billingCurrency())->toBe('EUR')
        ->and(fn () => $user->fresh()->assertCurrencyAllowed('USD'))->toThrow(CurrencyLocked::class);
});

it('raises card exceptions for declined charges', function () {
    $fake = Ridley::fake();
    $user = $this->user();
    $user->createAsRidleyCustomer();
    $fake->addPaymentMethod($user->ridleyId());
    $fake->failNextCharge();

    expect(fn () => $user->charge('5'))->toThrow(CardException::class);
});

it('pays with the default card or falls back to checkout', function () {
    $fake = Ridley::fake();
    $user = $this->user();

    $result = $user->payOrCheckout('30.00', ['purpose' => 'top_up', 'currency' => 'EUR', 'invoice' => true], 'https://app.example.com/ok', 'https://app.example.com/cancel');

    expect($result->requiresCheckout())->toBeTrue()
        ->and($result->succeeded())->toBeFalse()
        ->and($result->redirectUrl())->toStartWith('https://')
        ->and($user->fresh()->hasRidleyId())->toBeTrue();

    $session = $fake->all('sessions')[$result->checkout()->id()];
    expect($session['mode'])->toBe('payment')->and($session['metadata']['purpose'])->toBe('top_up');

    $fake->addPaymentMethod($user->ridleyId());
    $user->updateDefaultPaymentMethodFromRidley();

    $result = $user->payOrCheckout('30.00', ['purpose' => 'top_up', 'currency' => 'EUR', 'invoice' => true], 'https://app.example.com/ok', 'https://app.example.com/cancel');

    expect($result->succeeded())->toBeTrue()->and($result->payment())->not->toBeNull();
    // EUR differs from the USD billing currency, so no invoice is requested.
    $fake->assertCharged(fn (array $params) => ! isset($params['invoice']) && $params['metadata']['purpose'] === 'top_up');

    $user->payOrCheckout('12.00', ['invoice' => true], 'https://app.example.com/ok', 'https://app.example.com/cancel');
    $fake->assertCharged(fn (array $params) => ($params['invoice'] ?? false) === true && $params['amount'] === '12.00');
});

it('lists invoices and downloads PDFs', function () {
    $fake = Ridley::fake();
    $user = $this->user();
    $user->createAsRidleyCustomer();
    $invoice = $fake->addInvoice(['customer' => $user->ridleyId(), 'total' => '49.00']);
    $fake->addInvoice(['customer' => 'cus_other']);

    expect($user->invoices())->toHaveCount(1)
        ->and($user->findInvoice($invoice['id'])->total())->toBe('$49.00')
        ->and($user->findInvoice('in_other'))->toBeNull();

    $response = $user->downloadInvoice($invoice['id']);

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('.pdf');
});

it('manages the customer balance', function () {
    Ridley::fake();
    $user = $this->user();
    $user->createAsRidleyCustomer();

    $user->creditBalance('15.00', 'Goodwill');
    $user->debitBalance('5.00');

    expect($user->rawBalance())->toBe(-1000)
        ->and($user->balanceTransactions())->toHaveCount(2);
});

it('creates a billing portal session', function () {
    $fake = Ridley::fake();
    $user = $this->user();
    $user->createAsRidleyCustomer();

    expect($user->billingPortalUrl('https://app.example.com/billing'))->toStartWith('https://');
    $fake->assertCreated('portal-sessions', fn (array $params) => $params['return_url'] === 'https://app.example.com/billing');
});

it('carries a checkout reference on the success URL and finds the session again', function () {
    $fake = Ridley::fake();
    $this->syncCatalogue();
    $user = $this->user();
    $other = $this->user(['email' => 'other@example.com']);

    $sessions = [
        $user->checkoutCharge('10.00', 'Top-up', 'https://app.example.com/done?tab=billing#paid', 'https://app.example.com/cancel'),
        $user->paymentMethodSetupSession('https://app.example.com/done', 'https://app.example.com/cancel'),
        $user->newSubscription('default')->catalogue('plan', 'starter', 'USD', 'month')->checkout('https://app.example.com/done', 'https://app.example.com/cancel'),
    ];

    $successUrl = $fake->find($sessions[0]->id())['success_url'];

    expect($successUrl)->toMatch('#^https://app\.example\.com/done\?tab=billing&ridley_checkout=[0-9A-Z]{26}\#paid$#');

    foreach ($sessions as $session) {
        $request = Request::create($fake->find($session->id())['success_url']);

        expect(Ridley::checkoutSession($request, $user)?->id())->toBe($session->id())
            ->and(Ridley::checkoutSession($request, $other))->toBeNull();
    }

    expect(Ridley::checkoutSession(Request::create('https://app.example.com/done?ridley_checkout=unknown'), $user))->toBeNull()
        ->and(Ridley::checkoutSession(Request::create('https://app.example.com/done'), $user))->toBeNull();
});

it('keeps customer state on one polymorphic record per billable', function () {
    $fake = Ridley::fake();
    $user = $this->user();

    expect($user->ridleyCustomer)->toBeNull()
        ->and($user->hasDefaultPaymentMethod())->toBeFalse();

    $user->createAsRidleyCustomer();
    $fake->addPaymentMethod($user->ridleyId(), ['last4' => '1111', 'exp_month' => 1, 'exp_year' => 2020]);
    $user->updateDefaultPaymentMethodFromRidley();
    $user->lockBillingCurrency('usd');

    $record = $user->fresh()->ridleyCustomer;
    expect(Customer::query()->count())->toBe(1)
        ->and($record->billable->is($user))->toBeTrue()
        ->and($record->currency)->toBe('USD')
        ->and($record->hasExpiredCard())->toBeTrue()
        ->and(Ridley::findBillable($user->ridleyId())?->is($user))->toBeTrue();
});
