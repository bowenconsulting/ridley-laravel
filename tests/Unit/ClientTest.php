<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Ridley\Laravel\Exceptions\ApiException;
use Ridley\Laravel\Exceptions\AuthenticationException;
use Ridley\Laravel\Exceptions\CardException;
use Ridley\Laravel\Exceptions\IdempotencyException;
use Ridley\Laravel\Exceptions\IncompletePayment;
use Ridley\Laravel\Exceptions\InvalidRequestException;
use Ridley\Laravel\Exceptions\NotFoundException;
use Ridley\Laravel\Exceptions\PermissionException;
use Ridley\Laravel\Exceptions\RateLimitException;
use Ridley\Laravel\Resources\Customer;
use Ridley\Laravel\Ridley;

function errorBody(string $code, int $status = 422): array
{
    return ['errors' => [['status' => (string) $status, 'code' => $code, 'title' => 'Error', 'detail' => "Failed: {$code}"]]];
}

it('unwraps the JSON:API envelope into typed resources', function () {
    Http::fake(['api.ridley.test/v1/customers/cus_1' => Http::response([
        'data' => ['id' => 'cus_1', 'type' => 'customers', 'attributes' => ['name' => 'Ada', 'balance' => '-5.00']],
    ])]);

    $customer = Ridley::client()->customers()->retrieve('cus_1');

    expect($customer)->toBeInstanceOf(Customer::class)
        ->and($customer->id())->toBe('cus_1')
        ->and($customer->name)->toBe('Ada')
        ->and($customer->rawBalance())->toBe(-500);
});

it('iterates every page with the after cursor', function () {
    Http::fake(function (Request $request) {
        $after = parse_url($request->url(), PHP_URL_QUERY);

        return str_contains((string) $after, 'page%5Bafter%5D=cus_2')
            ? Http::response(['data' => [['id' => 'cus_3', 'type' => 'customers', 'attributes' => []]], 'meta' => ['has_more' => false]])
            : Http::response(['data' => [
                ['id' => 'cus_1', 'type' => 'customers', 'attributes' => []],
                ['id' => 'cus_2', 'type' => 'customers', 'attributes' => []],
            ], 'meta' => ['has_more' => true]]);
    });

    $ids = Ridley::client()->customers()->all()->map(fn ($customer) => $customer->id())->all();

    expect($ids)->toBe(['cus_1', 'cus_2', 'cus_3']);
});

it('encodes nested query parameters with brackets and booleans as strings', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    Ridley::client()->subscriptions()->list(['filter' => ['customer' => 'cus_1', 'metadata' => ['plan' => 'pro']], 'expand' => true]);

    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'filter[customer]=cus_1')
        && str_contains(urldecode($request->url()), 'filter[metadata][plan]=pro')
        && str_contains($request->url(), 'expand=true'));
});

it('retries 429 and 5xx responses reusing the idempotency key', function () {
    Http::fakeSequence()
        ->push(errorBody('rate_limited', 429), 429, ['Retry-After' => '1'])
        ->push(errorBody('server_error', 503), 503)
        ->push(['data' => ['id' => 'cus_1', 'type' => 'customers', 'attributes' => []]]);

    Ridley::client()->customers()->create(['name' => 'Ada'], ['idempotency_key' => 'key-1']);

    $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0] ?? null)->unique()->values()->all();

    expect(Http::recorded())->toHaveCount(3)->and($keys)->toBe(['key-1']);
});

it('generates one idempotency key per request and reuses it across retries', function () {
    Http::fakeSequence()
        ->push(errorBody('server_error', 500), 500)
        ->push(['data' => ['id' => 'cus_1', 'type' => 'customers', 'attributes' => []]]);

    Ridley::client()->customers()->create(['name' => 'Ada']);

    $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->unique();

    expect($keys)->toHaveCount(1)->and($keys->first())->not->toBeEmpty();
});

it('maps errors to exceptions', function (int $status, string $code, string $class) {
    config(['ridley.http.retries' => 0]);
    Http::fake(['*' => Http::response(errorBody($code, $status), $status)]);

    expect(fn () => Ridley::client()->customers()->retrieve('cus_1'))->toThrow($class, "Failed: {$code}");
})->with([
    [402, 'card_declined', CardException::class],
    [402, 'authentication_required', IncompletePayment::class],
    [401, 'unauthenticated', AuthenticationException::class],
    [403, 'forbidden', PermissionException::class],
    [404, 'resource_missing', NotFoundException::class],
    [409, 'idempotency_key_in_use', IdempotencyException::class],
    [422, 'parameter_invalid', InvalidRequestException::class],
    [429, 'rate_limited', RateLimitException::class],
    [500, 'server_error', ApiException::class],
]);

it('advances test clocks with ISO 8601 times', function (int|string|DateTimeInterface $time) {
    Http::fake(['*' => Http::response(['data' => ['id' => 'clock_1', 'type' => 'test-clocks', 'attributes' => []]])]);

    Ridley::client()->testClocks()->advance('clock_1', $time);

    Http::assertSent(fn (Request $request) => $request['frozen_time'] === '2026-11-11T20:06:11+00:00');
})->with([
    'timestamp' => [1794427571],
    'DateTime' => [new DateTimeImmutable('2026-11-11T20:06:11+00:00')],
    'string' => ['2026-11-11T20:06:11+00:00'],
]);

it('returns raw PDF bytes', function () {
    Http::fake(['*/invoices/in_1/pdf' => Http::response('%PDF-1.4 bytes', 200, ['Content-Type' => 'application/pdf'])]);

    expect(Ridley::client()->invoices()->pdf('in_1'))->toBe('%PDF-1.4 bytes');
});

it('fails fast without an API key', function () {
    config(['ridley.key' => null]);

    expect(fn () => Ridley::client()->customers()->retrieve('cus_1'))->toThrow(AuthenticationException::class);
});

it('formats amounts', function () {
    expect(Ridley::formatAmount('19.99', 'USD'))->toBe('$19.99')
        ->and(Ridley::formatAmount('1500', 'JMD'))->toContain('1,500.00');
});
