<?php

declare(strict_types=1);

namespace Ridley\Laravel;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Ridley\Laravel\Exceptions\CurrencyLocked;
use Ridley\Laravel\Exceptions\IncompletePayment;
use Ridley\Laravel\Exceptions\InvalidRequestException;
use Ridley\Laravel\Exceptions\PriceNotFound;
use Ridley\Laravel\Resources\Customer;
use Ridley\Laravel\Resources\Session;
use Ridley\Laravel\Resources\Subscription as RidleySubscription;
use Ridley\Laravel\Support\PriceResolver;
use Throwable;

/**
 * Fluent builder for new subscriptions (`$billable->newSubscription('default', 'price_…')`).
 */
class SubscriptionBuilder
{
    /**
     * Items keyed by the price argument as given (ID, lookup key or catalogue key).
     *
     * @var array<string, array{price: string|Price|null, quantity: int|null, catalogue?: array{group: string, key: string, currency: string|null, interval: string, interval_count: int}}>
     */
    protected array $items = [];

    protected ?string $currency = null;

    protected ?int $trialDays = null;

    protected ?CarbonInterface $trialExpires = null;

    protected bool $skipTrial = false;

    protected string|int|null $billingCycleAnchor = null;

    /**
     * @var array<string, mixed>
     */
    protected array $metadata = [];

    protected string $paymentBehavior = 'allow_incomplete';

    /**
     * @param  Model  $owner  A model using the {@see Billable} trait.
     * @param  string|Price|array<int, string|Price>  $prices
     */
    public function __construct(
        protected Model $owner,
        protected string $type,
        string|Price|array $prices = [],
    ) {
        foreach ((array) (is_array($prices) ? $prices : [$prices]) as $price) {
            $this->price($price);
        }
    }

    /**
     * Add a price (ID, lookup key or local catalogue Price).
     */
    public function price(string|Price $price, ?int $quantity = 1): static
    {
        $key = $price instanceof Price ? $price->ridley_price_id : $price;

        $this->items[$key] = ['price' => $price, 'quantity' => $quantity];

        return $this;
    }

    /**
     * Add the current catalogue price for a group/item, e.g. `catalogue('plan', 'professional', 'USD', 'month')`.
     */
    /**
     * A null currency means the builder's {@see currency()} or the billable's billing currency;
     * it is resolved when the subscription is created.
     *
     * @throws PriceNotFound
     */
    public function catalogue(string $group, string $itemKey, ?string $currency, string $interval, int $intervalCount = 1, ?int $quantity = 1): static
    {
        if ($currency !== null) {
            return $this->price(Ridley::catalogue()->priceOrFail($group, $itemKey, $currency, $interval, $intervalCount), $quantity);
        }

        $this->items["catalogue:{$group}.{$itemKey}.{$intervalCount}{$interval}"] = [
            'price' => null,
            'quantity' => $quantity,
            'catalogue' => ['group' => $group, 'key' => $itemKey, 'currency' => null, 'interval' => $interval, 'interval_count' => $intervalCount],
        ];

        return $this;
    }

    /**
     * Add a whole selection, e.g. `[['group' => 'plan', 'key' => 'pro'], ['group' => 'addon', 'key' => 'seat', 'quantity' => 3]]`.
     *
     * @param  list<array{group: string, key: string, quantity?: int|null}>  $items
     */
    public function catalogueItems(array $items, string $interval, ?string $currency = null, int $intervalCount = 1): static
    {
        foreach ($items as $item) {
            $quantity = (int) ($item['quantity'] ?? 1);

            if ($quantity > 0) {
                $this->catalogue($item['group'], $item['key'], $currency, $interval, $intervalCount, $quantity);
            }
        }

        return $this;
    }

    /**
     * The currency to subscribe in (defaults to the billable's billing currency).
     */
    public function currency(string $currency): static
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    /**
     * Set the quantity of a price (or the only price).
     */
    public function quantity(?int $quantity, string|Price|null $price = null): static
    {
        if ($price === null) {
            if (count($this->items) !== 1) {
                throw new InvalidArgumentException('Price is required when creating subscriptions with multiple prices.');
            }

            $price = (string) array_key_first($this->items);
        }

        $key = $price instanceof Price ? $price->ridley_price_id : $price;

        if (! isset($this->items[$key])) {
            return $this->price($price, $quantity);
        }

        $this->items[$key]['quantity'] = $quantity;

        return $this;
    }

    public function trialDays(int $trialDays): static
    {
        $this->trialDays = $trialDays;
        $this->trialExpires = null;

        return $this;
    }

    public function trialUntil(DateTimeInterface $trialUntil): static
    {
        $this->trialExpires = Carbon::instance($trialUntil);
        $this->trialDays = null;

        return $this;
    }

    /**
     * Trial until the date (default: the billable's generic `trial_ends_at`) when it is in the future; otherwise no trial.
     */
    public function trialUntilIfFuture(?DateTimeInterface $date = null): static
    {
        $date ??= $this->owner->genericTrialEndsAt();

        if ($date !== null && Carbon::instance($date)->isFuture()) {
            $this->skipTrial = false;

            return $this->trialUntil($date);
        }

        return $this->skipTrial();
    }

    public function skipTrial(): static
    {
        $this->skipTrial = true;

        return $this;
    }

    /**
     * Anchor the billing cycle to a moment (timestamp) instead of the start date.
     */
    public function anchorBillingCycleOn(DateTimeInterface|int|string $date): static
    {
        $this->billingCycleAnchor = match (true) {
            $date instanceof DateTimeInterface => Carbon::instance($date)->utc()->toIso8601String(),
            is_int($date) => Carbon::createFromTimestampUTC($date)->toIso8601String(),
            default => $date,
        };

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Set Ridley's `payment_behavior` (allow_incomplete | default_incomplete | error_if_incomplete).
     */
    public function paymentBehavior(string $behavior): static
    {
        $this->paymentBehavior = $behavior;

        return $this;
    }

    public function errorIfPaymentFails(): static
    {
        return $this->paymentBehavior('error_if_incomplete');
    }

    public function allowPaymentFailures(): static
    {
        return $this->paymentBehavior('allow_incomplete');
    }

    public function defaultIncomplete(): static
    {
        return $this->paymentBehavior('default_incomplete');
    }

    /**
     * Create the subscription without attempting payment (`default_incomplete`).
     *
     * @param  array<string, mixed>  $customerOptions
     * @param  array<string, mixed>  $subscriptionOptions
     */
    public function add(array $customerOptions = [], array $subscriptionOptions = []): Subscription
    {
        return $this->defaultIncomplete()->create(null, $customerOptions, $subscriptionOptions);
    }

    /**
     * Create the subscription on Ridley and store it locally.
     *
     * @param  string|null  $paymentMethod  A saved payment method to bill (becomes the subscription default).
     * @param  array<string, mixed>  $customerOptions
     * @param  array<string, mixed>  $subscriptionOptions  Extra POST /subscriptions params.
     *
     * @throws IncompletePayment
     */
    public function create(?string $paymentMethod = null, array $customerOptions = [], array $subscriptionOptions = []): Subscription
    {
        if ($this->items === []) {
            throw new InvalidArgumentException('At least one price is required when starting subscriptions.');
        }

        $items = $this->buildItems();

        /** @var Customer $customer */
        $customer = $this->owner->createOrGetRidleyCustomer($customerOptions);

        $payload = array_filter(array_merge([
            'customer' => $customer->id(),
            'items' => $items,
            'payment_behavior' => $this->paymentBehavior,
            'default_payment_method' => $paymentMethod,
            'billing_cycle_anchor' => $this->billingCycleAnchor,
            'metadata' => $this->buildMetadata(),
        ], $this->trialPayload(), $subscriptionOptions), fn ($value) => $value !== null);

        $opts = isset($subscriptionOptions['idempotency_key']) ? ['idempotency_key' => (string) $subscriptionOptions['idempotency_key']] : [];
        unset($payload['idempotency_key']);

        $resource = Ridley::client()->subscriptions()->create($payload, $opts);

        $subscription = $this->persist($resource);

        if ($subscription->incomplete() && $this->paymentBehavior !== 'default_incomplete') {
            throw IncompletePayment::forSubscription($subscription, $this->latestInvoice($resource));
        }

        return $subscription;
    }

    /**
     * Start a hosted checkout that saves a card and starts the subscription.
     *
     * The local subscription is created when the `customer.subscription.created` webhook arrives.
     *
     * @param  array<string, mixed>  $sessionOptions
     */
    public function checkout(string $successUrl, string $cancelUrl, array $sessionOptions = []): Session
    {
        if ($this->items === []) {
            throw new InvalidArgumentException('At least one price is required when starting subscriptions.');
        }

        $items = $this->buildItems();

        /** @var Customer $customer */
        $customer = $this->owner->createOrGetRidleyCustomer();

        $payload = array_filter(array_merge([
            'mode' => 'subscription',
            'customer' => $customer->id(),
            'items' => $items,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $this->buildMetadata(),
        ], $this->trialPayload(), $sessionOptions), fn ($value) => $value !== null);

        return Ridley::createCheckoutSession($this->owner, $payload);
    }

    /**
     * Subscribe with the saved default card when there is one, otherwise return a
     * subscription-mode checkout. The Ridley customer is created either way.
     *
     * @param  array<string, mixed>  $customerOptions
     * @param  array<string, mixed>  $subscriptionOptions  POST /subscriptions params (or session params when checking out).
     *
     * @throws IncompletePayment
     * @throws CurrencyLocked
     */
    public function createOrCheckout(string $successUrl, string $cancelUrl, array $customerOptions = [], array $subscriptionOptions = []): SubscriptionResult
    {
        $this->buildItems();

        $this->owner->createOrGetRidleyCustomer($customerOptions);

        if ($this->owner->hasDefaultPaymentMethod()) {
            return SubscriptionResult::created($this->create(null, $customerOptions, $subscriptionOptions));
        }

        unset($subscriptionOptions['idempotency_key'], $subscriptionOptions['payment_behavior'], $subscriptionOptions['default_payment_method']);

        return SubscriptionResult::checkoutRequired(new Checkout($this->checkout($successUrl, $cancelUrl, $subscriptionOptions)));
    }

    /**
     * The currency the subscription will be billed in.
     */
    public function resolveCurrency(): string
    {
        if ($this->currency !== null) {
            return $this->currency;
        }

        foreach ($this->items as $item) {
            $currency = $this->priceCurrency($item['price']);

            if ($currency !== null) {
                return $currency;
            }
        }

        return $this->owner->billingCurrency();
    }

    /**
     * The POST /subscriptions items. Resolves catalogue items and enforces the currency lock.
     *
     * @return list<array{price: string, quantity?: int}>
     *
     * @throws CurrencyLocked
     * @throws PriceNotFound
     */
    public function buildItems(): array
    {
        $currency = $this->resolveCurrency();

        $this->owner->assertCurrencyAllowed($currency);

        return array_values(array_map(function (array $item) use ($currency) {
            $price = $item['price'];

            if ($price === null && isset($item['catalogue'])) {
                $spec = $item['catalogue'];
                $price = Ridley::catalogue()->priceOrFail($spec['group'], $spec['key'], $spec['currency'] ?? $currency, $spec['interval'], $spec['interval_count']);
            }

            if ($price instanceof Price && $price->currency !== $currency) {
                throw new InvalidRequestException("All prices must be in {$currency}; [{$price->lookup_key}] is in {$price->currency}.", 422, [
                    'code' => 'currency_mismatch',
                    'param' => 'items',
                ]);
            }

            return array_filter([
                'price' => PriceResolver::resolve($price ?? ''),
                'quantity' => $item['quantity'],
            ], fn ($value) => $value !== null);
        }, $this->items));
    }

    /**
     * The currency of a price argument when it can be determined (local catalogue first, then Ridley).
     */
    protected function priceCurrency(string|Price|null $price): ?string
    {
        if ($price === null) {
            return null;
        }

        if ($price instanceof Price) {
            return $price->currency;
        }

        $local = str_starts_with($price, 'price_')
            ? Ridley::catalogue()->findByRidleyId($price)
            : Ridley::priceModel()::query()->where('lookup_key', $price)->where('is_current', true)->latest('id')->first();

        if ($local !== null) {
            return $local->currency;
        }

        try {
            $remote = str_starts_with($price, 'price_')
                ? Ridley::client()->prices()->retrieve($price)
                : Ridley::client()->prices()->findByLookupKey($price);
        } catch (Throwable) {
            return null;
        }

        return is_string($remote?->currency) ? strtoupper($remote->currency) : null;
    }

    /**
     * Metadata sent to Ridley; `type`, `billable_type` and `billable_id` let webhooks map the subscription back.
     *
     * @return array<string, mixed>
     */
    public function buildMetadata(): array
    {
        return array_merge($this->metadata, [
            'type' => $this->type,
            'billable_type' => $this->owner->getMorphClass(),
            'billable_id' => (string) $this->owner->getKey(),
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    protected function trialPayload(): array
    {
        if ($this->skipTrial) {
            return [];
        }

        if ($this->trialExpires !== null) {
            return ['trial_end' => Carbon::instance($this->trialExpires)->utc()->toIso8601String()];
        }

        if ($this->trialDays !== null && $this->trialDays > 0) {
            return ['trial_period_days' => $this->trialDays];
        }

        return [];
    }

    protected function persist(RidleySubscription $resource): Subscription
    {
        $model = Ridley::subscriptionModel();

        /** @var Subscription $subscription */
        $subscription = $model::query()->firstOrNew(['ridley_id' => $resource->id()]);

        $subscription->forceFill([
            'billable_type' => $this->owner->getMorphClass(),
            'billable_id' => $this->owner->getKey(),
            'type' => $this->type,
            'ridley_status' => $resource->status ?? Subscription::STATUS_ACTIVE,
        ]);

        return $subscription->syncFromRidley($resource);
    }

    protected function latestInvoice(RidleySubscription $resource): ?Resources\Invoice
    {
        if (! $resource->latest_invoice) {
            return null;
        }

        try {
            return Ridley::client()->invoices()->retrieve($resource->latest_invoice);
        } catch (Throwable) {
            return null;
        }
    }
}
