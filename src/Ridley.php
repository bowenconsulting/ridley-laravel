<?php

declare(strict_types=1);

namespace Ridley\Laravel;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Ridley\Laravel\Catalogue\Catalogue;
use Ridley\Laravel\Catalogue\ItemDefinition;
use Ridley\Laravel\Catalogue\PriceDefinition;
use Ridley\Laravel\Client\RidleyClient;
use Ridley\Laravel\Exceptions\NotFoundException;
use Ridley\Laravel\Resources\Session;
use Ridley\Laravel\Testing\FakeRidley;

/**
 * Package entry point. Holds boot-time configuration only (no per-request state),
 * so it is safe under Laravel Octane.
 */
final class Ridley
{
    public const string VERSION = RidleyClient::VERSION;

    /**
     * @var class-string<Model>|null
     */
    public static ?string $customerModel = null;

    /**
     * @var class-string<Subscription>
     */
    public static string $subscriptionModel = Subscription::class;

    /**
     * @var class-string<SubscriptionItem>
     */
    public static string $subscriptionItemModel = SubscriptionItem::class;

    /**
     * @var class-string<Price>
     */
    public static string $priceModel = Price::class;

    /**
     * Query parameter appended to checkout success URLs to identify the session on return.
     */
    public const string CHECKOUT_QUERY = 'ridley_checkout';

    public static bool $runsMigrations = true;

    public static bool $registersRoutes = true;

    /**
     * Whether `past_due` subscriptions are still considered active / valid.
     */
    public static bool $keepPastDueActive = false;

    /**
     * @var (callable(): iterable<int, array<string, mixed>|PriceDefinition>)|null
     */
    protected static $catalogueResolver = null;

    /**
     * @var (callable(): iterable<int, array<string, mixed>|ItemDefinition>)|null
     */
    protected static $itemsResolver = null;

    /**
     * @var (Closure(Request): (Model|null))|null
     */
    protected static ?Closure $billableResolver = null;

    /**
     * @var (Closure(Request, Model, string): bool)|null
     */
    protected static ?Closure $authorizer = null;

    /**
     * @var (Closure(Request, string): string)|null
     */
    protected static ?Closure $redirector = null;

    /**
     * @var (Closure(Model, string, string): bool)|null
     */
    protected static ?Closure $sellable = null;

    /**
     * Resolve a fresh API client.
     */
    public static function client(): RidleyClient
    {
        return app(RidleyClient::class);
    }

    /**
     * The catalogue service (price definitions ↔ Ridley prices).
     */
    public static function catalogue(): Catalogue
    {
        return app(Catalogue::class);
    }

    /**
     * Register a resolver returning runtime price definitions (in addition to config).
     *
     * @param  callable(): iterable<int, array<string, mixed>|PriceDefinition>  $resolver
     */
    public static function defineCatalogueUsing(callable $resolver): void
    {
        self::$catalogueResolver = $resolver;
    }

    /**
     * @return (callable(): iterable<int, array<string, mixed>|PriceDefinition>)|null
     */
    public static function catalogueResolver(): ?callable
    {
        return self::$catalogueResolver;
    }

    /**
     * Register a resolver returning the items the app sells and what they grant
     * (in addition to config `ridley.catalogue`). Prices come from Ridley.
     *
     * @param  callable(): iterable<int, array<string, mixed>|ItemDefinition>  $resolver
     */
    public static function defineItemsUsing(callable $resolver): void
    {
        self::$itemsResolver = $resolver;
    }

    /**
     * @return (callable(): iterable<int, array<string, mixed>|ItemDefinition>)|null
     */
    public static function itemsResolver(): ?callable
    {
        return self::$itemsResolver;
    }

    /**
     * @param  class-string<Model>  $model
     */
    public static function useCustomerModel(string $model): void
    {
        self::$customerModel = $model;
    }

    /**
     * @return class-string<Model>
     */
    public static function customerModel(): string
    {
        /** @var class-string<Model> */
        return self::$customerModel ?? config('ridley.model', 'App\\Models\\User');
    }

    /**
     * @param  class-string<Subscription>  $model
     */
    public static function useSubscriptionModel(string $model): void
    {
        self::$subscriptionModel = $model;
    }

    /**
     * @return class-string<Subscription>
     */
    public static function subscriptionModel(): string
    {
        return self::$subscriptionModel;
    }

    /**
     * @param  class-string<SubscriptionItem>  $model
     */
    public static function useSubscriptionItemModel(string $model): void
    {
        self::$subscriptionItemModel = $model;
    }

    /**
     * @return class-string<SubscriptionItem>
     */
    public static function subscriptionItemModel(): string
    {
        return self::$subscriptionItemModel;
    }

    /**
     * @param  class-string<Price>  $model
     */
    public static function usePriceModel(string $model): void
    {
        self::$priceModel = $model;
    }

    /**
     * @return class-string<Price>
     */
    public static function priceModel(): string
    {
        return self::$priceModel;
    }

    /**
     * Do not load the package migrations (publish and customise them instead).
     */
    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    /**
     * Do not register the webhook route (register WebhookController yourself).
     */
    public static function ignoreRoutes(): void
    {
        self::$registersRoutes = false;
    }

    /**
     * Treat `past_due` subscriptions as active / valid.
     */
    public static function keepPastDueSubscriptionsActive(): void
    {
        self::$keepPastDueActive = true;
    }

    /**
     * Resolve the billable for the billing routes and middleware (default: `$request->user()`).
     *
     * @param  Closure(Request): (Model|null)  $resolver
     */
    public static function resolveBillableUsing(Closure $resolver): void
    {
        self::$billableResolver = $resolver;
    }

    public static function resolveBillable(Request $request): ?Model
    {
        $billable = self::$billableResolver !== null ? (self::$billableResolver)($request) : $request->user();

        return $billable instanceof Model ? $billable : null;
    }

    /**
     * Authorise billing route actions (default: allow). Abilities: subscribe, preview, change,
     * cancel, resume, payment-method, portal, invoices, details, checkout-success.
     *
     * @param  Closure(Request, Model, string): bool  $callback
     */
    public static function authorizeUsing(Closure $callback): void
    {
        self::$authorizer = $callback;
    }

    public static function authorize(Request $request, Model $billable, string $ability): bool
    {
        return self::$authorizer === null || (bool) (self::$authorizer)($request, $billable, $ability);
    }

    /**
     * Where billing routes redirect after an action (default: back).
     *
     * @param  Closure(Request, string): string  $callback
     */
    public static function afterActionRedirect(Closure $callback): void
    {
        self::$redirector = $callback;
    }

    public static function redirectUrlAfter(Request $request, string $action): ?string
    {
        return self::$redirector !== null ? (string) (self::$redirector)($request, $action) : null;
    }

    /**
     * Restrict which catalogue items may be bought self-serve (default: any current catalogue price).
     *
     * @param  Closure(Model, string, string): bool  $callback
     */
    public static function sellableUsing(Closure $callback): void
    {
        self::$sellable = $callback;
    }

    public static function isSellable(Model $billable, string $group, string $itemKey): bool
    {
        if (self::$sellable !== null) {
            return (bool) (self::$sellable)($billable, $group, $itemKey);
        }

        return self::catalogue()->prices($group)->contains(fn (Price $price) => $price->item_key === $itemKey);
    }

    /**
     * Find the billable model for a Ridley customer ID.
     */
    public static function findBillable(?string $customerId): ?Model
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        $class = self::customerModel();

        return (new $class)->newQuery()->where('ridley_customer_id', $customerId)->first();
    }

    /**
     * Find a billable model from the `billable_type`/`billable_id` the package writes into metadata.
     *
     * Only models using the {@see Billable} trait are resolved.
     */
    public static function findBillableByMorph(?string $type, string|int|null $id): ?Model
    {
        if ($type === null || $type === '' || $id === null || $id === '') {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! in_array(Billable::class, class_uses_recursive($class), true)) {
            return null;
        }

        return (new $class)->newQuery()->find($id);
    }

    /**
     * Create a hosted checkout session whose success URL carries a `ridley_checkout`
     * reference, remembered in the cache for a day, so {@see checkoutSession()} can
     * find the session again when the customer returns (Ridley does not substitute
     * a session ID into `success_url`).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function createCheckoutSession(Model $billable, array $payload): Session
    {
        $reference = (string) Str::ulid();

        if (is_string($payload['success_url'] ?? null) && $payload['success_url'] !== '') {
            $payload['success_url'] = self::appendQuery($payload['success_url'], self::CHECKOUT_QUERY, $reference);
        }

        $session = self::client()->sessions()->create($payload);

        Cache::put(self::checkoutCacheKey($reference), [
            'session' => $session->id(),
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ], now()->addDay());

        return $session;
    }

    /**
     * The checkout session the customer is returning from, identified by the
     * `ridley_checkout` query parameter. Null when the reference is missing,
     * expired, belongs to another billable or the session cannot be found.
     */
    public static function checkoutSession(Request $request, Model $billable): ?Session
    {
        $reference = $request->query(self::CHECKOUT_QUERY);

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $cached = Cache::get(self::checkoutCacheKey($reference));

        if (! is_array($cached)
            || ! is_string($cached['session'] ?? null)
            || ($cached['billable_type'] ?? null) !== $billable->getMorphClass()
            || ($cached['billable_id'] ?? null) !== (string) $billable->getKey()) {
            return null;
        }

        try {
            $session = self::client()->sessions()->retrieve($cached['session']);
        } catch (NotFoundException) {
            return null;
        }

        $customerId = method_exists($billable, 'ridleyId') ? $billable->ridleyId() : null;

        if ($session->customer !== null && $session->customer !== $customerId) {
            return null;
        }

        return $session;
    }

    protected static function checkoutCacheKey(string $reference): string
    {
        return 'ridley:checkout:'.$reference;
    }

    protected static function appendQuery(string $url, string $key, string $value): string
    {
        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);

        $base .= (str_contains($base, '?') ? (str_ends_with($base, '?') || str_ends_with($base, '&') ? '' : '&') : '?')
            .rawurlencode($key).'='.rawurlencode($value);

        return $fragment === null ? $base : $base.'#'.$fragment;
    }

    /**
     * Format a major-unit amount for display, e.g. "19.99" → "$19.99", JMD → "JMD 1,500.00".
     */
    public static function formatAmount(string|int|float $amount, ?string $currency = null, ?string $locale = null): string
    {
        $formatted = Number::currency(
            (float) $amount,
            in: strtoupper($currency ?? (string) config('ridley.currency', 'USD')),
            locale: $locale ?? (string) config('ridley.currency_locale', 'en'),
        );

        return str_replace(["\u{00A0}", "\u{202F}"], ' ', (string) $formatted);
    }

    /**
     * Install a stateful in-memory fake of the Ridley API (via Http::fake).
     *
     * @param  array<string, mixed>  $overrides  Responses keyed by "{METHOD} {path}" (wildcards allowed).
     */
    public static function fake(array $overrides = []): FakeRidley
    {
        return FakeRidley::install($overrides);
    }

    /**
     * Build a webhook envelope as Ridley would send it.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>|null  $previous
     * @return array{id: string, type: string, created: int, livemode: bool, api_version: string, data: array{object: array<string, mixed>, previous_attributes: array<string, mixed>|null}}
     */
    public static function webhookPayload(string $type, array $object, ?array $previous = null, ?string $eventId = null, bool $livemode = false): array
    {
        return [
            'id' => $eventId ?? 'evt_'.Str::lower(Str::random(24)),
            'type' => $type,
            'created' => now()->getTimestamp(),
            'livemode' => $livemode,
            'api_version' => '2026-01-01',
            'data' => [
                'object' => $object,
                'previous_attributes' => $previous,
            ],
        ];
    }

    /**
     * Reset all static configuration (used between tests).
     *
     * @internal
     */
    public static function flushState(): void
    {
        self::$customerModel = null;
        self::$subscriptionModel = Subscription::class;
        self::$subscriptionItemModel = SubscriptionItem::class;
        self::$priceModel = Price::class;
        self::$runsMigrations = true;
        self::$registersRoutes = true;
        self::$keepPastDueActive = false;
        self::$catalogueResolver = null;
        self::$itemsResolver = null;
        self::$billableResolver = null;
        self::$authorizer = null;
        self::$redirector = null;
        self::$sellable = null;
    }
}
