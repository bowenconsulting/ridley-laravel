<?php

declare(strict_types=1);

namespace Ridley\Laravel\Testing;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;
use Ridley\Laravel\Support\Money;

/**
 * A stateful, in-memory emulation of the Ridley API installed with `Ridley::fake()`.
 *
 * Requests go through the real client (via `Http::fake`), so tests exercise the
 * same code path as production. Override any endpoint with `"{METHOD} {path}"` keys.
 */
class FakeRidley
{
    /**
     * Resource attributes keyed by type, then ID.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    protected array $store = [];

    /**
     * @var array<string, int>
     */
    protected array $counters = [];

    /**
     * @var list<array{0: Request, 1: string, 2: string, 3: array<string, mixed>}>
     */
    protected array $recorded = [];

    protected ?string $failNextChargeCode = null;

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function __construct(protected array $overrides = [], protected string $baseUrl = 'https://api.ridleypay.com/v1') {}

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function install(array $overrides = []): static
    {
        if (! config('ridley.key')) {
            config(['ridley.key' => 'sk_test_fake']);
        }

        $baseUrl = rtrim((string) config('ridley.base_url', 'https://api.ridleypay.com/v1'), '/');

        $fake = new static($overrides, $baseUrl);

        app()->instance(self::class, $fake);
        app()->instance(static::class, $fake);

        Http::fake([
            $baseUrl.'/*' => fn (Request $request) => app(self::class)->handle($request),
        ]);

        return $fake;
    }

    /**
     * Add or replace overrides after installation.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function override(array $overrides): static
    {
        $this->overrides = array_merge($this->overrides, $overrides);

        return $this;
    }

    public function handle(Request $request): PromiseInterface
    {
        $method = strtoupper($request->method());
        $path = $this->pathOf($request);
        $params = $this->paramsOf($request);

        $this->recorded[] = [$request, $method, $path, $params];

        foreach ($this->overrides as $pattern => $response) {
            [$overrideMethod, $overridePath] = array_pad(explode(' ', (string) $pattern, 2), 2, '*');

            if (Str::is(strtoupper($overrideMethod), $method) && Str::is(trim($overridePath, '/'), $path)) {
                return $this->overrideResponse($response, $request, $path);
            }
        }

        return $this->emulate($method, $path, $params);
    }

    // ---------------------------------------------------------------------
    // Seeding helpers
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function addCustomer(array $attributes = []): array
    {
        return $this->createResource('customers', 'cus', array_merge([
            'name' => null,
            'email' => null,
            'phone' => null,
            'address' => null,
            'balance' => '0.00',
            'currency' => null,
            'default_payment_method' => null,
            'metadata' => [],
        ], $attributes));
    }

    /**
     * Attach a card (brand visa, last4 4242) and make it the customer's default.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function addPaymentMethod(string $customerId, array $attributes = []): array
    {
        $paymentMethod = $this->createResource('payment-methods', 'pm', array_merge([
            'customer' => $customerId,
            'type' => 'card',
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => (int) date('Y') + 3,
        ], $attributes));

        if (isset($this->store['customers'][$customerId])) {
            $this->store['customers'][$customerId]['default_payment_method'] = $paymentMethod['id'];
        }

        return $paymentMethod;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function addProduct(array $attributes = []): array
    {
        return $this->createResource('products', 'prod', array_merge([
            'name' => 'Product',
            'active' => true,
            'metadata' => [],
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes  `unit_amount` is a decimal string, e.g. "19.00".
     * @return array<string, mixed>
     */
    public function addPrice(array $attributes = []): array
    {
        $attributes = array_merge([
            'product' => null,
            'type' => 'recurring',
            'currency' => 'USD',
            'unit_amount' => '10.00',
            'interval' => 'month',
            'interval_count' => 1,
            'lookup_key' => null,
            'nickname' => null,
            'active' => true,
            'metadata' => [],
        ], $attributes);

        $attributes['currency'] = strtoupper((string) $attributes['currency']);
        $attributes['unit_amount'] = Money::decimal($attributes['unit_amount']);

        if ($attributes['product'] === null) {
            $attributes['product'] = $this->addProduct()['id'];
        }

        return $this->createResource('prices', 'price', $attributes);
    }

    /**
     * Seed an invoice. Amounts are decimal strings.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function addInvoice(array $attributes = []): array
    {
        $total = Money::decimal($attributes['total'] ?? '10.00');

        return $this->createResource('invoices', 'in', array_merge([
            'invoice_number' => 'INV-'.str_pad((string) (($this->counters['in'] ?? 0) + 1), 4, '0', STR_PAD_LEFT),
            'status' => 'paid',
            'customer' => null,
            'subscription' => null,
            'billing_reason' => 'subscription_cycle',
            'currency' => 'USD',
            'subtotal' => $total,
            'tax_amount' => '0.00',
            'total' => $total,
            'amount_paid' => ($attributes['status'] ?? 'paid') === 'paid' ? $total : '0.00',
            'amount_due' => ($attributes['status'] ?? 'paid') === 'paid' ? '0.00' : $total,
            'payment_url' => null,
            'next_payment_attempt' => null,
            'period_start' => Carbon::now()->toIso8601String(),
            'period_end' => Carbon::now()->addMonth()->toIso8601String(),
            'created_at' => Carbon::now()->toIso8601String(),
            'items' => [],
            'metadata' => [],
        ], $attributes));
    }

    /**
     * Make the next charge fail with the given error code (HTTP 402).
     */
    public function failNextCharge(string $code = 'card_declined'): static
    {
        $this->failNextChargeCode = $code;

        return $this;
    }

    /**
     * Update stored attributes of any resource.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $attributes): ?array
    {
        foreach ($this->store as $type => $resources) {
            if (isset($resources[$id])) {
                $this->store[$type][$id] = array_merge($resources[$id], $attributes);

                return $this->store[$type][$id];
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Accessors
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    public function customers(): array
    {
        return $this->store['customers'] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function subscriptions(): array
    {
        return $this->store['subscriptions'] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(string $type): array
    {
        return $this->store[$type] ?? [];
    }

    /**
     * Find any stored resource by ID.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->store as $resources) {
            if (isset($resources[$id])) {
                return $resources[$id];
            }
        }

        return null;
    }

    /**
     * @return list<Request>
     */
    public function requests(): array
    {
        return array_column($this->recorded, 0);
    }

    // ---------------------------------------------------------------------
    // Assertions
    // ---------------------------------------------------------------------

    /**
     * @param  callable(Request): bool  $callback
     */
    public function assertSent(callable $callback): void
    {
        PHPUnit::assertTrue(
            collect($this->requests())->contains(fn (Request $request) => (bool) $callback($request)),
            'An expected Ridley request was not sent.',
        );
    }

    /**
     * @param  callable(Request): bool  $callback
     */
    public function assertNotSent(callable $callback): void
    {
        PHPUnit::assertFalse(
            collect($this->requests())->contains(fn (Request $request) => (bool) $callback($request)),
            'An unexpected Ridley request was sent.',
        );
    }

    public function assertNothingSent(): void
    {
        PHPUnit::assertEmpty($this->recorded, 'Ridley requests were sent unexpectedly.');
    }

    public function assertSentCount(int $count): void
    {
        PHPUnit::assertCount($count, $this->recorded);
    }

    /**
     * Assert a POST to a collection (e.g. `subscriptions`), optionally checking its params.
     *
     * @param  (callable(array<string, mixed>, Request): bool)|null  $callback
     */
    public function assertCreated(string $resource, ?callable $callback = null): void
    {
        $resource = trim($resource, '/');

        PHPUnit::assertTrue(
            collect($this->recorded)->contains(fn (array $entry) => $entry[1] === 'POST' && $entry[2] === $resource
                && ($callback === null || $callback($entry[3], $entry[0]))),
            "No matching [{$resource}] was created on Ridley.",
        );
    }

    /**
     * Assert a PATCH to a resource, optionally checking its params.
     *
     * @param  (callable(array<string, mixed>, Request): bool)|null  $callback
     */
    public function assertUpdated(string $resource, string $id, ?callable $callback = null): void
    {
        $path = trim($resource, '/').'/'.$id;

        PHPUnit::assertTrue(
            collect($this->recorded)->contains(fn (array $entry) => $entry[1] === 'PATCH' && $entry[2] === $path
                && ($callback === null || $callback($entry[3], $entry[0]))),
            "Ridley [{$path}] was not updated as expected.",
        );
    }

    /**
     * @param  (callable(array<string, mixed>, Request): bool)|null  $callback
     */
    public function assertCharged(?callable $callback = null): void
    {
        $this->assertCreated('charges', $callback);
    }

    // ---------------------------------------------------------------------
    // Emulation
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    protected function emulate(string $method, string $path, array $params): PromiseInterface
    {
        $segments = explode('/', $path);
        $collection = $segments[0];
        $id = $segments[1] ?? null;
        $action = $segments[2] ?? null;

        return match (true) {
            $path === 'me' => $this->resource('accounts', 'acct_fake', [
                'name' => 'Fake account',
                'mode' => str_starts_with((string) config('ridley.key'), 'sk_live') ? 'live' : 'test',
                'default_currency' => strtoupper((string) config('ridley.currency', 'USD')),
            ]),
            $collection === 'customers' => $this->customersEndpoint($method, $id, $action, $params),
            $collection === 'payment-methods' => $this->paymentMethodsEndpoint($method, $id, $action),
            $collection === 'products' => $this->crud('products', 'prod', $method, $id, $params, fn (array $product) => $this->matchesMetadata($product, $params)),
            $collection === 'prices' => $this->pricesEndpoint($method, $id, $params),
            $collection === 'subscriptions' => $this->subscriptionsEndpoint($method, $id, $action, $params),
            $collection === 'invoices' => $this->invoicesEndpoint($method, $id, $action, $params),
            $collection === 'sessions' => $this->sessionsEndpoint($method, $id, $action, $params),
            $collection === 'charges' && $method === 'POST' => $this->charge($params),
            $collection === 'payments' => $this->crud('payments', 'pay', $method, $id, $params),
            $collection === 'portal-sessions' && $method === 'POST' => $this->resource('portal-sessions', $id = $this->nextId('bps'), [
                'customer' => $params['customer'] ?? null,
                'url' => "https://billing.example.com/p/{$id}",
                'return_url' => $params['return_url'] ?? null,
                'expires_at' => Carbon::now()->addHour()->toIso8601String(),
            ]),
            $collection === 'webhook-endpoints' => $this->webhookEndpointsEndpoint($method, $id, $action, $params),
            default => $this->error(404, 'resource_missing', "No fake for [{$method} {$path}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function customersEndpoint(string $method, ?string $id, ?string $action, array $params): PromiseInterface
    {
        if ($id !== null && $action !== null) {
            if (! isset($this->store['customers'][$id])) {
                return $this->notFound('customer', $id);
            }

            return match (true) {
                $action === 'payment-methods' => $this->list('payment-methods', array_values(array_filter(
                    $this->store['payment-methods'] ?? [],
                    fn (array $pm) => ($pm['customer'] ?? null) === $id,
                )), $params),
                $action === 'default-payment-method' => $this->setDefaultPaymentMethod($id, (string) ($params['payment_method'] ?? '')),
                $action === 'balance-transactions' && $method === 'POST' => $this->balanceTransaction($id, $params),
                $action === 'balance-transactions' => $this->list('balance-transactions', array_values(array_filter(
                    $this->store['balance-transactions'] ?? [],
                    fn (array $transaction) => $transaction['customer'] === $id,
                )), $params),
                default => $this->error(404, 'resource_missing', "No fake for customers/{$id}/{$action}."),
            };
        }

        if ($method === 'POST' && $id === null) {
            $customer = $this->addCustomer(array_merge($params, [
                'balance' => '0.00',
                'currency' => isset($params['currency']) ? strtoupper((string) $params['currency']) : null,
            ]));

            return $this->resource('customers', $customer['id'], $customer);
        }

        return $this->crud('customers', 'cus', $method, $id, $params, function (array $customer) use ($params) {
            $email = Arr::get($params, 'filter.email');

            return $email === null || $customer['email'] === $email;
        });
    }

    protected function setDefaultPaymentMethod(string $customerId, string $paymentMethod): PromiseInterface
    {
        if (! isset($this->store['payment-methods'][$paymentMethod])) {
            return $this->notFound('payment method', $paymentMethod);
        }

        $this->store['customers'][$customerId]['default_payment_method'] = $paymentMethod;

        return $this->resource('customers', $customerId, $this->store['customers'][$customerId]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function balanceTransaction(string $customerId, array $params): PromiseInterface
    {
        $amount = Money::toMinor($params['amount'] ?? 0);
        $balance = Money::toMinor($this->store['customers'][$customerId]['balance'] ?? '0') + $amount;

        $this->store['customers'][$customerId]['balance'] = Money::fromMinor($balance);

        $transaction = $this->createResource('balance-transactions', 'cbtxn', [
            'customer' => $customerId,
            'amount' => Money::fromMinor($amount),
            'currency' => strtoupper((string) ($params['currency'] ?? 'USD')),
            'description' => $params['description'] ?? null,
            'ending_balance' => Money::fromMinor($balance),
            'created_at' => Carbon::now()->toIso8601String(),
        ]);

        return $this->resource('balance-transactions', $transaction['id'], $transaction);
    }

    protected function paymentMethodsEndpoint(string $method, ?string $id, ?string $action): PromiseInterface
    {
        if ($id === null || ! isset($this->store['payment-methods'][$id])) {
            return $this->notFound('payment method', (string) $id);
        }

        $paymentMethod = $this->store['payment-methods'][$id];

        if ($method === 'DELETE' || $action === 'detach') {
            unset($this->store['payment-methods'][$id]);

            $customerId = $paymentMethod['customer'] ?? null;

            if (is_string($customerId) && ($this->store['customers'][$customerId]['default_payment_method'] ?? null) === $id) {
                $this->store['customers'][$customerId]['default_payment_method'] = null;
            }
        }

        return $this->resource('payment-methods', $id, $paymentMethod);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function pricesEndpoint(string $method, ?string $id, array $params): PromiseInterface
    {
        if ($method === 'POST' && $id === null) {
            $lookupKey = $params['lookup_key'] ?? null;

            if (is_string($lookupKey)) {
                foreach ($this->store['prices'] ?? [] as $priceId => $price) {
                    if (($price['lookup_key'] ?? null) === $lookupKey) {
                        if (empty($params['transfer_lookup_key'])) {
                            return $this->error(422, 'lookup_key_taken', "The lookup key [{$lookupKey}] is already in use.", 'lookup_key');
                        }

                        $this->store['prices'][$priceId]['lookup_key'] = null;
                    }
                }
            }

            unset($params['transfer_lookup_key']);
            $price = $this->addPrice($params);

            return $this->resource('prices', $price['id'], $price);
        }

        return $this->crud('prices', 'price', $method, $id, $params, function (array $price) use ($params) {
            foreach (['lookup_key', 'product', 'currency', 'type'] as $filter) {
                $value = Arr::get($params, "filter.{$filter}");

                if ($value !== null && (string) ($price[$filter] ?? '') !== (string) $value) {
                    return false;
                }
            }

            $active = Arr::get($params, 'filter.active');

            return $active === null || (bool) $price['active'] === filter_var($active, FILTER_VALIDATE_BOOLEAN);
        });
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function subscriptionsEndpoint(string $method, ?string $id, ?string $action, array $params): PromiseInterface
    {
        if ($method === 'POST' && $id === null) {
            return $this->createSubscription($params);
        }

        if ($id !== null && ! isset($this->store['subscriptions'][$id])) {
            return $this->notFound('subscription', $id);
        }

        if ($id !== null && $method === 'PATCH') {
            return $this->updateSubscription($id, $params);
        }

        if ($id !== null && $method === 'DELETE') {
            $this->store['subscriptions'][$id] = array_merge($this->store['subscriptions'][$id], [
                'status' => 'cancelled',
                'cancelled_at' => Carbon::now()->toIso8601String(),
                'ended_at' => Carbon::now()->toIso8601String(),
                'cancel_at_period_end' => false,
            ]);

            return $this->resource('subscriptions', $id, $this->store['subscriptions'][$id]);
        }

        if ($id !== null && $action === 'resume') {
            $subscription = $this->store['subscriptions'][$id];
            $subscription['cancel_at_period_end'] = false;
            $subscription['cancelled_at'] = null;
            $subscription['pause_collection'] = null;

            if ($subscription['status'] === 'paused') {
                $subscription['status'] = 'active';
            }

            $this->store['subscriptions'][$id] = $subscription;

            return $this->resource('subscriptions', $id, $subscription);
        }

        return $this->crud('subscriptions', 'sub', $method, $id, $params, function (array $subscription) use ($params) {
            foreach (['customer', 'status'] as $filter) {
                $value = Arr::get($params, "filter.{$filter}");

                if ($value !== null && ($subscription[$filter] ?? null) !== $value) {
                    return false;
                }
            }

            $price = Arr::get($params, 'filter.price');

            return ($price === null || in_array($price, array_column($subscription['items'], 'price'), true))
                && $this->matchesMetadata($subscription, $params);
        });
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function createSubscription(array $params): PromiseInterface
    {
        $customerId = (string) ($params['customer'] ?? '');

        if (! isset($this->store['customers'][$customerId])) {
            return $this->notFound('customer', $customerId, 'customer');
        }

        $items = [];

        foreach ((array) ($params['items'] ?? []) as $item) {
            $built = $this->buildItem((array) $item);

            if ($built === null) {
                return $this->notFound('price', (string) ($item['price'] ?? ''), 'items');
            }

            $items[] = $built;
        }

        if ($items === []) {
            return $this->error(422, 'parameter_missing', 'At least one item is required.', 'items');
        }

        $first = $this->store['prices'][$items[0]['price']];
        $now = Carbon::now();

        $trialEnd = match (true) {
            isset($params['trial_end']) && $params['trial_end'] !== 'now' => Carbon::parse(is_numeric($params['trial_end']) ? '@'.$params['trial_end'] : (string) $params['trial_end']),
            ! empty($params['trial_period_days']) => $now->copy()->addDays((int) $params['trial_period_days']),
            default => null,
        };

        $status = match (true) {
            $trialEnd !== null && $trialEnd->isFuture() => 'trialing',
            ($params['payment_behavior'] ?? null) === 'default_incomplete' => 'incomplete',
            default => 'active',
        };

        $periodStart = $now->copy();
        $periodEnd = $trialEnd !== null && $trialEnd->isFuture()
            ? $trialEnd->copy()
            : $this->addInterval($now->copy(), (string) $first['interval'], (int) $first['interval_count']);

        $subscription = $this->createResource('subscriptions', 'sub', [
            'customer' => $customerId,
            'status' => $status,
            'currency' => $first['currency'],
            'interval' => $first['interval'],
            'interval_count' => $first['interval_count'],
            'current_period_start' => $periodStart->toIso8601String(),
            'current_period_end' => $periodEnd->toIso8601String(),
            'trial_start' => $trialEnd !== null ? $now->toIso8601String() : null,
            'trial_end' => $trialEnd?->toIso8601String(),
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
            'ended_at' => null,
            'pause_collection' => null,
            'default_payment_method' => $params['default_payment_method'] ?? $this->store['customers'][$customerId]['default_payment_method'] ?? null,
            'latest_invoice' => null,
            'items' => $items,
            'metadata' => (array) ($params['metadata'] ?? []),
        ]);

        return $this->resource('subscriptions', $subscription['id'], $subscription);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function updateSubscription(string $id, array $params): PromiseInterface
    {
        $subscription = $this->store['subscriptions'][$id];

        if (isset($params['items'])) {
            $items = $this->applyItemChanges($subscription['items'], (array) $params['items']);

            if ($items === null) {
                return $this->error(404, 'resource_missing', 'A price or subscription item was not found.', 'items');
            }

            $subscription['items'] = $items;

            if ($items !== []) {
                $first = $this->store['prices'][$items[0]['price']];
                $subscription['currency'] = $first['currency'];
                $subscription['interval'] = $first['interval'];
                $subscription['interval_count'] = $first['interval_count'];
            }
        }

        if (array_key_exists('cancel_at_period_end', $params)) {
            $cancel = filter_var($params['cancel_at_period_end'], FILTER_VALIDATE_BOOLEAN);
            $subscription['cancel_at_period_end'] = $cancel;
            $subscription['cancelled_at'] = $cancel ? Carbon::now()->toIso8601String() : null;
        }

        if (array_key_exists('trial_end', $params)) {
            if ($params['trial_end'] === 'now') {
                $subscription['trial_end'] = Carbon::now()->toIso8601String();
                $subscription['status'] = $subscription['status'] === 'trialing' ? 'active' : $subscription['status'];
            } else {
                $trialEnd = Carbon::parse(is_numeric($params['trial_end']) ? '@'.$params['trial_end'] : (string) $params['trial_end']);
                $subscription['trial_end'] = $trialEnd->toIso8601String();
                $subscription['current_period_end'] = $trialEnd->toIso8601String();
                $subscription['status'] = $trialEnd->isFuture() ? 'trialing' : $subscription['status'];
            }
        }

        if (array_key_exists('pause_collection', $params)) {
            $subscription['pause_collection'] = $params['pause_collection'];
            $subscription['status'] = $params['pause_collection'] ? 'paused' : ($subscription['status'] === 'paused' ? 'active' : $subscription['status']);
        }

        if (isset($params['metadata']) && is_array($params['metadata'])) {
            $subscription['metadata'] = array_filter(array_merge((array) $subscription['metadata'], $params['metadata']), fn ($value) => $value !== null && $value !== '');
        }

        if (isset($params['default_payment_method'])) {
            $subscription['default_payment_method'] = $params['default_payment_method'];
        }

        $this->store['subscriptions'][$id] = $subscription;

        return $this->resource('subscriptions', $id, $subscription);
    }

    /**
     * @param  list<array<string, mixed>>  $current
     * @param  array<int, mixed>  $changes
     * @return list<array<string, mixed>>|null
     */
    protected function applyItemChanges(array $current, array $changes, bool $persist = true): ?array
    {
        $items = collect($current)->keyBy('id')->all();

        foreach ($changes as $change) {
            $change = (array) $change;
            $itemId = $change['id'] ?? null;

            if ($itemId !== null) {
                if (! isset($items[$itemId])) {
                    return null;
                }

                if (filter_var($change['deleted'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    unset($items[$itemId]);

                    continue;
                }

                if (isset($change['price'])) {
                    $price = $this->store['prices'][$this->priceId((string) $change['price'])] ?? null;

                    if ($price === null) {
                        return null;
                    }

                    $items[$itemId]['price'] = $price['id'];
                    $items[$itemId]['unit_amount'] = $price['unit_amount'];
                    $items[$itemId]['product'] = $price['product'];
                }

                if (array_key_exists('quantity', $change)) {
                    $items[$itemId]['quantity'] = (int) $change['quantity'];
                }

                if (isset($change['metadata'])) {
                    $items[$itemId]['metadata'] = (array) $change['metadata'];
                }

                continue;
            }

            $built = $this->buildItem($change, $persist);

            if ($built === null) {
                return null;
            }

            $items[$persist ? $built['id'] : 'si_preview_'.count($items)] = $built;
        }

        return array_values($items);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    protected function buildItem(array $item, bool $persist = true): ?array
    {
        $price = $this->store['prices'][$this->priceId((string) ($item['price'] ?? ''))] ?? null;

        if ($price === null) {
            return null;
        }

        return [
            'id' => $persist ? $this->nextId('si') : 'si_preview',
            'price' => $price['id'],
            'product' => $price['product'],
            'quantity' => (int) ($item['quantity'] ?? 1),
            'unit_amount' => $price['unit_amount'],
            'metadata' => (array) ($item['metadata'] ?? []),
        ];
    }

    /**
     * Resolve a price ID or lookup key to a stored price ID.
     */
    protected function priceId(string $price): string
    {
        if (isset($this->store['prices'][$price])) {
            return $price;
        }

        foreach ($this->store['prices'] ?? [] as $id => $attributes) {
            if (($attributes['lookup_key'] ?? null) === $price) {
                return $id;
            }
        }

        return $price;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function invoicesEndpoint(string $method, ?string $id, ?string $action, array $params): PromiseInterface
    {
        if ($id === 'upcoming') {
            return $this->upcomingInvoice($params);
        }

        if ($method === 'POST' && $id === null) {
            $invoice = $this->addInvoice(array_merge(['status' => 'draft', 'total' => '0.00'], $params));

            return $this->resource('invoices', $invoice['id'], $invoice);
        }

        if ($id !== null && ! isset($this->store['invoices'][$id])) {
            return $this->notFound('invoice', $id);
        }

        if ($id !== null && $action === 'pdf') {
            return Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']);
        }

        if ($id !== null && $action === 'lines') {
            return $this->list('invoice-items', (array) ($this->store['invoices'][$id]['items'] ?? []), $params);
        }

        if ($id !== null && $action === 'pay') {
            $this->store['invoices'][$id] = array_merge($this->store['invoices'][$id], [
                'status' => 'paid',
                'amount_paid' => $this->store['invoices'][$id]['total'],
                'amount_due' => '0.00',
                'paid_at' => Carbon::now()->toIso8601String(),
            ]);

            return $this->resource('invoices', $id, $this->store['invoices'][$id]);
        }

        if ($id !== null && in_array($action, ['finalize', 'send', 'void', 'mark-paid', 'mark-uncollectible'], true)) {
            $status = match ($action) {
                'finalize', 'send' => 'open',
                'void' => 'void',
                'mark-paid' => 'paid',
                default => 'uncollectible',
            };

            $this->store['invoices'][$id]['status'] = $status;

            return $this->resource('invoices', $id, $this->store['invoices'][$id]);
        }

        return $this->crud('invoices', 'in', $method, $id, $params, function (array $invoice) use ($params) {
            foreach (['customer', 'subscription', 'status'] as $filter) {
                $value = Arr::get($params, "filter.{$filter}");

                if ($value !== null && ($invoice[$filter] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * The next invoice for a subscription: unit_amount × quantity per item, with
     * `subscription_items` applied as a proposed change.
     *
     * @param  array<string, mixed>  $params
     */
    protected function upcomingInvoice(array $params): PromiseInterface
    {
        $subscriptionId = $params['subscription'] ?? null;
        $subscription = is_string($subscriptionId) ? ($this->store['subscriptions'][$subscriptionId] ?? null) : null;

        if ($subscription === null || $subscription['status'] === 'cancelled') {
            return $this->error(404, 'resource_missing', 'No upcoming invoice.');
        }

        $items = $subscription['items'];
        $billingReason = 'subscription_cycle';

        if (isset($params['subscription_items'])) {
            $proposed = [];

            foreach ($this->applyItemChanges($items, array_values((array) $params['subscription_items']), false) ?? [] as $item) {
                $proposed[] = $item;
            }

            $currentInterval = [$subscription['interval'], (int) $subscription['interval_count']];
            $first = $proposed !== [] ? ($this->store['prices'][$proposed[0]['price']] ?? null) : null;

            if ($first !== null && [$first['interval'], (int) $first['interval_count']] !== $currentInterval) {
                $billingReason = 'subscription_update';
            }

            $items = $proposed;
        }

        $lines = [];
        $total = 0;

        foreach ($items as $item) {
            $amount = Money::toMinor($item['unit_amount']) * (int) $item['quantity'];
            $total += $amount;

            $lines[] = [
                'id' => 'ii_preview_'.count($lines),
                'description' => ($item['quantity'] > 1 ? $item['quantity'].' × ' : '').($this->store['prices'][$item['price']]['nickname'] ?? $item['price']),
                'quantity' => (int) $item['quantity'],
                'unit_price' => $item['unit_amount'],
                'amount' => Money::fromMinor($amount),
                'price' => $item['price'],
                'proration' => false,
            ];
        }

        return $this->json(['data' => [
            'id' => null,
            'type' => 'invoices',
            'attributes' => [
                'status' => 'draft',
                'customer' => $subscription['customer'],
                'subscription' => $subscription['id'],
                'billing_reason' => $billingReason,
                'currency' => $subscription['currency'],
                'subtotal' => Money::fromMinor($total),
                'tax_amount' => '0.00',
                'total' => Money::fromMinor($total),
                'amount_paid' => '0.00',
                'amount_due' => Money::fromMinor($total),
                'period_start' => $subscription['current_period_end'],
                'period_end' => $this->addInterval(Carbon::parse($subscription['current_period_end']), (string) $subscription['interval'], (int) $subscription['interval_count'])->toIso8601String(),
                'subscription_proration_behavior' => $params['subscription_proration_behavior'] ?? null,
                'items' => $lines,
            ],
        ]]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function sessionsEndpoint(string $method, ?string $id, ?string $action, array $params): PromiseInterface
    {
        if ($method === 'POST' && $id === null) {
            $id = $this->nextId('cs');

            $session = [
                'id' => $id,
                'mode' => $params['mode'] ?? 'payment',
                'amount' => isset($params['amount']) ? Money::decimal($params['amount']) : null,
                'currency' => isset($params['currency']) ? strtoupper((string) $params['currency']) : null,
                'reference' => $params['reference'] ?? null,
                'status' => 'open',
                'checkout_url' => "https://checkout.example.com/c/{$id}",
                'success_url' => $params['success_url'] ?? null,
                'cancel_url' => $params['cancel_url'] ?? null,
                'customer' => $params['customer'] ?? null,
                'items' => $params['items'] ?? null,
                'payment_id' => null,
                'payment_method' => null,
                'subscription' => null,
                'metadata' => (array) ($params['metadata'] ?? []),
                'expires_at' => Carbon::now()->addDay()->toIso8601String(),
            ];

            $this->store['sessions'][$id] = $session;

            return $this->resource('sessions', $id, $session);
        }

        if ($id !== null && $action === 'expire' && isset($this->store['sessions'][$id])) {
            $this->store['sessions'][$id]['status'] = 'expired';

            return $this->resource('sessions', $id, $this->store['sessions'][$id]);
        }

        return $this->crud('sessions', 'cs', $method, $id, $params, function (array $session) use ($params) {
            foreach (['status', 'reference', 'customer'] as $filter) {
                $value = Arr::get($params, "filter.{$filter}");

                if ($value !== null && ($session[$filter] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Complete a checkout session as if the customer paid (creates the subscription or payment).
     *
     * @return array<string, mixed>
     */
    public function completeCheckout(string $sessionId): array
    {
        $session = $this->store['sessions'][$sessionId] ?? null;

        PHPUnit::assertNotNull($session, "Unknown checkout session [{$sessionId}].");

        $customerId = $session['customer'];

        if ($session['mode'] === 'subscription' && is_string($customerId)) {
            $paymentMethod = $this->addPaymentMethod($customerId);
            $response = $this->createSubscription([
                'customer' => $customerId,
                'items' => $session['items'] ?? [],
                'metadata' => $session['metadata'] ?? [],
                'trial_period_days' => $session['trial_period_days'] ?? null,
            ])->wait();
            $session['subscription'] = json_decode((string) $response->getBody(), true)['data']['id'] ?? null;
            $session['payment_method'] = $paymentMethod['id'];
        }

        if ($session['mode'] === 'setup' && is_string($customerId)) {
            $session['payment_method'] = $this->addPaymentMethod($customerId)['id'];
        }

        if ($session['mode'] === 'payment') {
            $payment = $this->createResource('payments', 'pay', [
                'reference' => $session['reference'],
                'amount' => $session['amount'],
                'currency' => $session['currency'],
                'status' => 'PAID',
                'paid' => true,
                'customer_id' => null,
                'metadata' => $session['metadata'],
            ]);
            $session['payment_id'] = $payment['id'];
        }

        $session['status'] = 'complete';

        return $this->store['sessions'][$sessionId] = $session;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function charge(array $params): PromiseInterface
    {
        if ($this->failNextChargeCode !== null) {
            $code = $this->failNextChargeCode;
            $this->failNextChargeCode = null;

            return $this->error(402, $code, 'Your card was declined.');
        }

        $customerId = (string) ($params['customer'] ?? '');

        if (! isset($this->store['customers'][$customerId])) {
            return $this->notFound('customer', $customerId, 'customer');
        }

        $paymentMethod = $params['payment_method'] ?? $this->store['customers'][$customerId]['default_payment_method'] ?? null;

        if ($paymentMethod === null) {
            return $this->error(402, 'no_payment_method', 'The customer has no default payment method.');
        }

        $amount = Money::decimal($params['amount'] ?? '0');
        $currency = strtoupper((string) ($params['currency'] ?? 'USD'));
        $invoiceId = null;

        if (filter_var($params['invoice'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $invoiceId = $this->addInvoice([
                'customer' => $customerId,
                'currency' => $currency,
                'total' => $amount,
                'billing_reason' => 'manual',
                'items' => [['description' => $params['description'] ?? 'Charge', 'quantity' => 1, 'unit_price' => $amount, 'amount' => $amount]],
            ])['id'];
        }

        $payment = $this->createResource('payments', 'pay', [
            'reference' => $params['reference'] ?? (string) Str::ulid(),
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'completed',
            'paid' => true,
            'description' => $params['description'] ?? null,
            'customer_id' => $customerId,
            'payment_method_id' => $paymentMethod,
            'card_brand' => $this->store['payment-methods'][$paymentMethod]['brand'] ?? null,
            'card_last' => $this->store['payment-methods'][$paymentMethod]['last4'] ?? null,
            'invoice' => $invoiceId,
            'metadata' => (array) ($params['metadata'] ?? []),
        ]);

        return $this->resource('payments', $payment['id'], $payment);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function webhookEndpointsEndpoint(string $method, ?string $id, ?string $action, array $params): PromiseInterface
    {
        if ($method === 'POST' && $id === null) {
            $endpoint = $this->createResource('webhook-endpoints', 'we', [
                'url' => $params['url'] ?? null,
                'enabled_events' => $params['enabled_events'] ?? ['*'],
                'active' => $params['active'] ?? true,
                'description' => $params['description'] ?? null,
                'secret' => 'whsec_'.Str::random(32),
            ]);

            return $this->resource('webhook-endpoints', $endpoint['id'], $endpoint);
        }

        if ($id !== null && $action === 'roll-secret' && isset($this->store['webhook-endpoints'][$id])) {
            $this->store['webhook-endpoints'][$id]['secret'] = 'whsec_'.Str::random(32);

            return $this->resource('webhook-endpoints', $id, $this->store['webhook-endpoints'][$id]);
        }

        return $this->crud('webhook-endpoints', 'we', $method, $id, $params);
    }

    // ---------------------------------------------------------------------
    // Generic helpers
    // ---------------------------------------------------------------------

    /**
     * Generic list / retrieve / update / delete for a stored collection.
     *
     * @param  array<string, mixed>  $params
     * @param  (Closure(array<string, mixed>): bool)|null  $filter
     */
    protected function crud(string $type, string $prefix, string $method, ?string $id, array $params, ?Closure $filter = null): PromiseInterface
    {
        if ($id === null) {
            if ($method === 'POST') {
                $resource = $this->createResource($type, $prefix, $params);

                return $this->resource($type, $resource['id'], $resource);
            }

            $items = array_values(array_filter($this->store[$type] ?? [], fn (array $resource) => $filter === null || $filter($resource)));

            return $this->list($type, $items, $params);
        }

        if (! isset($this->store[$type][$id])) {
            return $this->notFound(Str::singular(str_replace('-', ' ', $type)), $id);
        }

        if ($method === 'PATCH') {
            $attributes = $this->store[$type][$id];

            foreach ($params as $key => $value) {
                $attributes[$key] = $key === 'metadata' && is_array($value)
                    ? array_filter(array_merge((array) ($attributes['metadata'] ?? []), $value), fn ($item) => $item !== null && $item !== '')
                    : $value;
            }

            $this->store[$type][$id] = $attributes;
        }

        $resource = $this->store[$type][$id];

        if ($method === 'DELETE') {
            unset($this->store[$type][$id]);
            $resource['deleted'] = true;
        }

        if ($type === 'webhook-endpoints' && $method !== 'POST') {
            $resource['secret'] = null;
        }

        return $this->resource($type, $id, $resource);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function createResource(string $type, string $prefix, array $attributes): array
    {
        $id = isset($attributes['id']) && is_string($attributes['id']) ? $attributes['id'] : $this->nextId($prefix);

        $attributes = array_merge(['created_at' => Carbon::now()->toIso8601String()], $attributes, ['id' => $id]);

        $this->store[$type][$id] = $attributes;

        return $attributes;
    }

    protected function nextId(string $prefix): string
    {
        $this->counters[$prefix] = ($this->counters[$prefix] ?? 0) + 1;

        return "{$prefix}_fake_{$this->counters[$prefix]}";
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function resource(string $type, ?string $id, array $attributes): PromiseInterface
    {
        unset($attributes['id']);

        return $this->json(['data' => ['id' => $id, 'type' => $type, 'attributes' => $attributes]]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $params
     */
    protected function list(string $type, array $items, array $params): PromiseInterface
    {
        $after = Arr::get($params, 'page.after');
        $size = (int) (Arr::get($params, 'page.size') ?? 100);

        if (is_string($after)) {
            $position = array_search($after, array_column($items, 'id'), true);
            $items = $position === false ? [] : array_slice($items, $position + 1);
        }

        $page = array_slice($items, 0, max(1, $size));

        return $this->json([
            'data' => array_map(function (array $item) use ($type) {
                $id = $item['id'] ?? null;
                unset($item['id']);

                return ['id' => $id, 'type' => $type, 'attributes' => $item];
            }, $page),
            'meta' => ['has_more' => count($items) > count($page)],
            'links' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, mixed>  $params
     */
    protected function matchesMetadata(array $resource, array $params): bool
    {
        $filters = Arr::get($params, 'filter.metadata');

        if (! is_array($filters)) {
            return true;
        }

        foreach ($filters as $key => $value) {
            if ((string) ($resource['metadata'][$key] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    protected function addInterval(Carbon $date, string $interval, int $count): Carbon
    {
        return match ($interval) {
            'day' => $date->addDays($count),
            'week' => $date->addWeeks($count),
            'year' => $date->addYears($count),
            default => $date->addMonths($count),
        };
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function json(array $body, int $status = 200): PromiseInterface
    {
        return Http::response($body, $status);
    }

    protected function error(int $status, string $code, string $detail, ?string $parameter = null): PromiseInterface
    {
        return $this->json(['errors' => [array_filter([
            'status' => (string) $status,
            'code' => $code,
            'title' => Str::headline($code),
            'detail' => $detail,
            'source' => $parameter !== null ? ['parameter' => $parameter] : null,
        ])]], $status);
    }

    protected function notFound(string $resource, string $id, ?string $parameter = null): PromiseInterface
    {
        return $this->error(404, 'resource_missing', "No such {$resource}: '{$id}'.", $parameter);
    }

    protected function overrideResponse(mixed $response, Request $request, string $path): PromiseInterface
    {
        if ($response instanceof Closure) {
            $response = $response($request, $this);
        }

        if ($response instanceof PromiseInterface) {
            return $response;
        }

        if (! is_array($response)) {
            return $this->json([]);
        }

        if (isset($response['data']) || isset($response['errors'])) {
            return $this->json($response, isset($response['errors']) ? (int) ($response['errors'][0]['status'] ?? 422) : 200);
        }

        $type = explode('/', $path)[0];

        return $this->resource($type, isset($response['id']) ? (string) $response['id'] : null, $response);
    }

    protected function pathOf(Request $request): string
    {
        $url = strtok($request->url(), '?');
        $path = str_starts_with((string) $url, $this->baseUrl) ? substr((string) $url, strlen($this->baseUrl)) : (string) parse_url((string) $url, PHP_URL_PATH);

        return rawurldecode(trim($path, '/'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function paramsOf(Request $request): array
    {
        $query = [];
        $queryString = parse_url($request->url(), PHP_URL_QUERY);

        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $query);
        }

        $body = in_array(strtoupper($request->method()), ['GET', 'HEAD', 'DELETE'], true) ? [] : json_decode($request->body(), true);

        /** @var array<string, mixed> */
        return array_merge($query, is_array($body) ? $body : []);
    }
}
