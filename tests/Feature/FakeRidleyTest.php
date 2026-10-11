<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Ridley\Laravel\Exceptions\InvalidRequestException;
use Ridley\Laravel\Ridley;

it('records requests and supports assertions', function () {
    $fake = Ridley::fake();

    $fake->assertNothingSent();

    $customer = Ridley::client()->customers()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

    $fake->assertSentCount(1);
    $fake->assertCreated('customers', fn (array $params) => $params['email'] === 'ada@example.com');
    $fake->assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key'));
    $fake->assertNotSent(fn (Request $request) => $request->method() === 'DELETE');

    expect($fake->customers())->toHaveKey($customer->id());
});

it('paginates lists with a cursor', function () {
    $fake = Ridley::fake();

    foreach (range(1, 5) as $i) {
        $fake->addCustomer(['email' => "c{$i}@example.com"]);
    }

    $page = Ridley::client()->customers()->list(['page' => ['size' => 2]]);

    expect($page->data())->toHaveCount(2)
        ->and($page->hasMore())->toBeTrue()
        ->and(Ridley::client()->customers()->all(['page' => ['size' => 2]]))->toHaveCount(5);
});

it('allows overriding endpoints with arrays or closures', function () {
    $fake = Ridley::fake([
        'GET customers/cus_x' => ['data' => ['id' => 'cus_x', 'type' => 'customers', 'attributes' => ['name' => 'Overridden']]],
    ]);
    $fake->override(['POST prices' => fn () => ['errors' => [['status' => '422', 'code' => 'lookup_key_taken', 'detail' => 'Taken.']]]]);

    expect(Ridley::client()->customers()->retrieve('cus_x')->name)->toBe('Overridden')
        ->and(fn () => Ridley::client()->prices()->create(['unit_amount' => '1.00']))->toThrow(InvalidRequestException::class, 'Taken.');
});

it('rejects a duplicate lookup key unless it is transferred', function () {
    $fake = Ridley::fake();
    $fake->addPrice(['lookup_key' => 'plan.basic.usd.month']);

    expect(fn () => Ridley::client()->prices()->create(['currency' => 'USD', 'unit_amount' => '2.00', 'lookup_key' => 'plan.basic.usd.month']))
        ->toThrow(InvalidRequestException::class);

    $price = Ridley::client()->prices()->create(['currency' => 'USD', 'unit_amount' => '2.00', 'lookup_key' => 'plan.basic.usd.month', 'transfer_lookup_key' => true]);

    expect(Ridley::client()->prices()->findByLookupKey('plan.basic.usd.month')?->id())->toBe($price->id());
});
