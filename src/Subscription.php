<?php

declare(strict_types=1);

namespace Ridley\Laravel;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Ridley\Laravel\Events\SubscriptionSynced;
use Ridley\Laravel\Exceptions\NotFoundException;
use Ridley\Laravel\Exceptions\SubscriptionUpdateFailure;
use Ridley\Laravel\Resources\Invoice;
use Ridley\Laravel\Resources\RidleyObject;
use Ridley\Laravel\Resources\Subscription as RidleySubscription;
use Ridley\Laravel\Support\PriceResolver;
use Ridley\Laravel\Support\Timestamp;

/**
 * A local mirror of a Ridley subscription.
 *
 * @property int $id
 * @property string $billable_type
 * @property int|string $billable_id
 * @property string $type
 * @property string $ridley_id
 * @property string $ridley_status
 * @property string|null $ridley_price
 * @property int|null $quantity
 * @property string|null $currency
 * @property string|null $interval
 * @property int|null $interval_count
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property bool $cancel_at_period_end
 * @property Carbon|null $paused_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $owner
 * @property-read Collection<int, SubscriptionItem> $items
 */
class Subscription extends Model
{
    public const string STATUS_INCOMPLETE = 'incomplete';

    public const string STATUS_INCOMPLETE_EXPIRED = 'incomplete_expired';

    public const string STATUS_TRIALING = 'trialing';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_PAST_DUE = 'past_due';

    public const string STATUS_UNPAID = 'unpaid';

    public const string STATUS_PAUSED = 'paused';

    public const string STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];

    /**
     * The `proration_behavior` explicitly chosen for the next item change (null = default/policy).
     */
    protected ?string $prorationBehavior = null;

    protected ?int $prorationDate = null;

    protected string|int|null $billingCycleAnchor = null;

    protected ?string $idempotencyKey = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'interval_count' => 'integer',
            'trial_ends_at' => 'datetime',
            'ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'paused_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return (string) config('ridley.tables.subscriptions', 'ridley_subscriptions');
    }

    /**
     * The billable model that owns the subscription.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner', 'billable_type', 'billable_id');
    }

    /**
     * Alias of {@see owner()}.
     *
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo('billable', 'billable_type', 'billable_id');
    }

    /**
     * @return HasMany<SubscriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Ridley::subscriptionItemModel(), 'subscription_id');
    }

    // ---------------------------------------------------------------------
    // State
    // ---------------------------------------------------------------------

    /**
     * Whether the subscription grants access: active, on trial or on its grace period.
     */
    public function valid(): bool
    {
        return $this->active() || $this->onTrial() || $this->onGracePeriod();
    }

    /**
     * Whether the subscription is in good standing (trialing counts as active).
     */
    public function active(): bool
    {
        if ($this->ended()) {
            return false;
        }

        return match ($this->ridley_status) {
            self::STATUS_ACTIVE, self::STATUS_TRIALING => true,
            self::STATUS_PAST_DUE => Ridley::$keepPastDueActive,
            default => false,
        };
    }

    /**
     * Whether the subscription is active and not on trial.
     */
    public function recurring(): bool
    {
        return ! $this->onTrial() && ! $this->canceled() && $this->active();
    }

    /**
     * Whether the subscription was cancelled (it may still be on its grace period).
     */
    public function canceled(): bool
    {
        return $this->ends_at !== null;
    }

    public function cancelled(): bool
    {
        return $this->canceled();
    }

    /**
     * Whether the subscription has ended (cancelled and past its grace period).
     */
    public function ended(): bool
    {
        return $this->canceled() && ! $this->onGracePeriod();
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function hasExpiredTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
    }

    /**
     * Whether the subscription is cancelled but still running until `ends_at`.
     */
    public function onGracePeriod(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isFuture();
    }

    public function pastDue(): bool
    {
        return $this->ridley_status === self::STATUS_PAST_DUE;
    }

    public function unpaid(): bool
    {
        return $this->ridley_status === self::STATUS_UNPAID;
    }

    public function incomplete(): bool
    {
        return $this->ridley_status === self::STATUS_INCOMPLETE;
    }

    public function hasIncompletePayment(): bool
    {
        return $this->pastDue() || $this->incomplete();
    }

    public function paused(): bool
    {
        return $this->ridley_status === self::STATUS_PAUSED;
    }

    /**
     * Whether the subscription includes the price (ID, lookup key or local Price).
     */
    public function hasPrice(string|Price $price): bool
    {
        $price = $this->priceId($price);

        if ($this->hasMultiplePrices()) {
            return $this->items->contains(fn (SubscriptionItem $item) => $item->ridley_price === $price);
        }

        return $this->ridley_price === $price || $this->items->contains(fn (SubscriptionItem $item) => $item->ridley_price === $price);
    }

    public function hasMultiplePrices(): bool
    {
        return $this->items->count() > 1;
    }

    public function hasSinglePrice(): bool
    {
        return ! $this->hasMultiplePrices();
    }

    /**
     * @throws SubscriptionUpdateFailure
     */
    public function findItemOrFail(string|Price $price): SubscriptionItem
    {
        $priceId = $this->priceId($price);

        $item = $this->items->first(fn (SubscriptionItem $item) => $item->ridley_price === $priceId);

        if ($item === null) {
            throw SubscriptionUpdateFailure::priceNotFound($this, $priceId);
        }

        return $item;
    }

    // ---------------------------------------------------------------------
    // Catalogue helpers
    // ---------------------------------------------------------------------

    /**
     * The items whose catalogue price belongs to a group (e.g. `addon`).
     *
     * @return Collection<int, SubscriptionItem>
     */
    public function itemsInGroup(string $group): Collection
    {
        $this->loadMissing('items.price');

        return $this->items->filter(fn (SubscriptionItem $item) => $item->group() === $group)->values();
    }

    public function itemInGroup(string $group): ?SubscriptionItem
    {
        return $this->itemsInGroup($group)->first();
    }

    /**
     * The catalogue item key subscribed to in a group, e.g. `catalogueKey('plan')` → `professional`.
     */
    public function catalogueKey(string $group): ?string
    {
        return $this->itemInGroup($group)?->itemKey();
    }

    /**
     * The total quantity subscribed for a catalogue item (0 when absent).
     */
    public function quantityOf(string $group, string $itemKey): int
    {
        return (int) $this->itemsInGroup($group)
            ->filter(fn (SubscriptionItem $item) => $item->itemKey() === $itemKey)
            ->sum(fn (SubscriptionItem $item) => $item->quantity ?? 1);
    }

    /**
     * Whether the subscription includes the catalogue item (e.g. `hasCatalogueItem('addon', 'extra_seats')`).
     */
    public function hasCatalogueItem(string $group, string $itemKey): bool
    {
        return $this->itemsInGroup($group)->contains(fn (SubscriptionItem $item) => $item->itemKey() === $itemKey);
    }

    /**
     * The catalogue selection: `['plan' => ['professional' => 1], 'addon' => ['extra_seats' => 2]]`.
     *
     * Items whose price is not in the local catalogue are left out.
     *
     * @return array<string, array<string, int>>
     */
    public function selection(): array
    {
        $this->loadMissing('items.price');

        $selection = [];

        foreach ($this->items as $item) {
            $group = $item->group();
            $itemKey = $item->itemKey();

            if ($group === null || $itemKey === null) {
                continue;
            }

            $selection[$group][$itemKey] = ($selection[$group][$itemKey] ?? 0) + ($item->quantity ?? 1);
        }

        return $selection;
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $statuses = [self::STATUS_ACTIVE, self::STATUS_TRIALING];

        if (Ridley::$keepPastDueActive) {
            $statuses[] = self::STATUS_PAST_DUE;
        }

        $query->whereIn('ridley_status', $statuses)
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', Carbon::now()));
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->where(function (Builder $query) {
            /** @var Builder<static> $query */
            $query->active()
                ->orWhere('trial_ends_at', '>', Carbon::now())
                ->orWhere('ends_at', '>', Carbon::now());
        });
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeRecurring(Builder $query): void
    {
        $query->active()->notOnTrial()->notCanceled();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeCanceled(Builder $query): void
    {
        $query->whereNotNull('ends_at');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeCancelled(Builder $query): void
    {
        $query->canceled();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeNotCanceled(Builder $query): void
    {
        $query->whereNull('ends_at');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeEnded(Builder $query): void
    {
        $query->canceled()->notOnGracePeriod();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOnTrial(Builder $query): void
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', Carbon::now());
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeExpiredTrial(Builder $query): void
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', Carbon::now());
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeNotOnTrial(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', Carbon::now()));
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOnGracePeriod(Builder $query): void
    {
        $query->whereNotNull('ends_at')->where('ends_at', '>', Carbon::now());
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeNotOnGracePeriod(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '<=', Carbon::now()));
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePastDue(Builder $query): void
    {
        $query->where('ridley_status', self::STATUS_PAST_DUE);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeUnpaid(Builder $query): void
    {
        $query->where('ridley_status', self::STATUS_UNPAID);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeIncomplete(Builder $query): void
    {
        $query->where('ridley_status', self::STATUS_INCOMPLETE);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePaused(Builder $query): void
    {
        $query->where('ridley_status', self::STATUS_PAUSED);
    }

    // ---------------------------------------------------------------------
    // Fluent options for the next change
    // ---------------------------------------------------------------------

    /**
     * Do not prorate the next change.
     */
    public function noProrate(): static
    {
        $this->prorationBehavior = 'none';

        return $this;
    }

    /**
     * Prorate the next change onto the next invoice (default).
     */
    public function prorate(): static
    {
        $this->prorationBehavior = 'create_prorations';

        return $this;
    }

    /**
     * Prorate and invoice the next change immediately.
     */
    public function alwaysInvoice(): static
    {
        $this->prorationBehavior = 'always_invoice';

        return $this;
    }

    public function setProrationBehavior(string $behavior): static
    {
        $this->prorationBehavior = $behavior;

        return $this;
    }

    /**
     * The behaviour used by swap()/addPrice()/… (`create_prorations` unless set fluently).
     */
    public function prorationBehavior(): string
    {
        return $this->prorationBehavior ?? 'create_prorations';
    }

    /**
     * Calculate prorations as of the given moment.
     */
    public function prorationDate(DateTimeInterface $date): static
    {
        $this->prorationDate = $date->getTimestamp();

        return $this;
    }

    /**
     * Reset the billing cycle anchor with the next change (Ridley supports `now`).
     */
    public function anchorBillingCycleOn(DateTimeInterface|string|int $date = 'now'): static
    {
        $this->billingCycleAnchor = $date instanceof DateTimeInterface ? $date->getTimestamp() : $date;

        return $this;
    }

    /**
     * Use this Idempotency-Key for the next API call.
     */
    public function withIdempotencyKey(string $key): static
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    // ---------------------------------------------------------------------
    // Changes
    // ---------------------------------------------------------------------

    /**
     * Swap the subscription to new prices.
     *
     * A single item swapped to a single price keeps its item; otherwise items whose price
     * remains are kept, new prices are added and the rest are removed.
     *
     * @param  string|Price|array<int|string, string|Price|array<string, mixed>>  $prices
     * @param  array<string, mixed>  $options  Extra PATCH params (e.g. `metadata`, `proration_behavior`).
     */
    public function swap(string|Price|array $prices, array $options = []): static
    {
        $this->guardAgainstIncomplete();

        return $this->updateItems($this->swapItems($prices), $options);
    }

    /**
     * Change the subscription's prices using the proration policy: increases use
     * `ridley.proration.increase` (default `always_invoice`), decreases use
     * `ridley.proration.decrease` (default `create_prorations`). A preceding
     * prorate()/noProrate()/alwaysInvoice() call, or `proration_behavior` in
     * `$options`, overrides the policy.
     *
     * @param  array<int|string, string|Price|array<string, mixed>>  $prices  Swap shape (e.g. {@see Catalogue\Catalogue::resolve()}) or PATCH item list.
     * @param  array<string, mixed>  $options  Extra PATCH params.
     */
    public function change(array $prices, array $options = []): static
    {
        $this->guardAgainstIncomplete();

        $items = $this->changeItems($prices);
        $behavior = $this->changeProrationBehavior($items, $options);
        unset($options['proration_behavior']);

        return $this->updateRidley(array_merge($this->prorationParams($behavior), ['items' => $items], $options));
    }

    /**
     * Whether the change would raise the bill: a higher per-period total, an interval
     * different from the subscription's, or a price missing from the local catalogue.
     *
     * @param  array<int|string, string|Price|array<string, mixed>>  $prices
     */
    public function isIncrease(array $prices): bool
    {
        return $this->itemsIncrease($this->changeItems($prices));
    }

    /**
     * The proration behaviour {@see change()} and {@see previewChange()} would use.
     *
     * @param  array<int|string, string|Price|array<string, mixed>>  $prices
     * @param  array<string, mixed>  $options
     */
    public function changeProrationBehaviorFor(array $prices, array $options = []): string
    {
        return $this->changeProrationBehavior($this->changeItems($prices), $options);
    }

    /**
     * Swap and invoice the prorations immediately.
     *
     * @param  string|Price|array<int|string, string|Price|array<string, mixed>>  $prices
     * @param  array<string, mixed>  $options
     */
    public function swapAndInvoice(string|Price|array $prices, array $options = []): static
    {
        return $this->alwaysInvoice()->swap($prices, $options);
    }

    public function incrementQuantity(int $count = 1, string|Price|null $price = null): static
    {
        $item = $this->itemFor($price);

        return $this->updateQuantity(($item->quantity ?? 1) + $count, $item->ridley_price);
    }

    public function incrementAndInvoice(int $count = 1, string|Price|null $price = null): static
    {
        return $this->alwaysInvoice()->incrementQuantity($count, $price);
    }

    public function decrementQuantity(int $count = 1, string|Price|null $price = null): static
    {
        $item = $this->itemFor($price);

        return $this->updateQuantity(max(1, ($item->quantity ?? 1) - $count), $item->ridley_price);
    }

    public function updateQuantity(int $quantity, string|Price|null $price = null): static
    {
        $this->guardAgainstIncomplete();

        $item = $this->itemFor($price);

        return $this->updateItems([['id' => $item->ridley_id, 'quantity' => $quantity]]);
    }

    /**
     * Add a price (e.g. an add-on) to the subscription.
     *
     * @param  array<string, mixed>  $options  Item options such as `metadata`.
     */
    public function addPrice(string|Price $price, ?int $quantity = 1, array $options = []): static
    {
        $this->guardAgainstIncomplete();

        $priceId = $this->priceId($price);

        if ($this->items->contains(fn (SubscriptionItem $item) => $item->ridley_price === $priceId)) {
            throw SubscriptionUpdateFailure::duplicatePrice($this, $priceId);
        }

        return $this->updateItems([$this->newItem($priceId, $quantity, $options)]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function addPriceAndInvoice(string|Price $price, ?int $quantity = 1, array $options = []): static
    {
        return $this->alwaysInvoice()->addPrice($price, $quantity, $options);
    }

    public function removePrice(string|Price $price): static
    {
        if ($this->hasSinglePrice()) {
            throw SubscriptionUpdateFailure::cannotDeleteLastPrice($this);
        }

        $item = $this->findItemOrFail($price);

        return $this->updateItems([['id' => $item->ridley_id, 'deleted' => true]]);
    }

    /**
     * PATCH a list of item changes (Ridley PATCH shape) with the fluent proration options.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $params
     */
    public function updateItems(array $items, array $params = []): static
    {
        return $this->updateRidley(array_merge($this->prorationParams($this->prorationBehavior()), ['items' => $items], $params));
    }

    /**
     * Cancel at the end of the current period (or trial).
     */
    public function cancel(): static
    {
        return $this->updateRidley(['cancel_at_period_end' => true]);
    }

    /**
     * Cancel immediately.
     */
    public function cancelNow(bool $prorate = false): static
    {
        return $this->cancelRidley(['prorate' => $prorate]);
    }

    /**
     * Cancel immediately, crediting unused time and invoicing anything pending now.
     */
    public function cancelNowAndInvoice(): static
    {
        return $this->cancelRidley(['prorate' => true, 'invoice_now' => true]);
    }

    /**
     * Undo a pending cancellation, or lift a pause.
     */
    public function resume(): static
    {
        if ($this->paused()) {
            return $this->unpause();
        }

        if (! $this->onGracePeriod()) {
            throw new LogicException('Unable to resume a subscription that is not within its grace period.');
        }

        return $this->updateRidley(['cancel_at_period_end' => false]);
    }

    /**
     * Pause collection, e.g. `['behavior' => 'void', 'resumes_at' => $timestamp]`.
     *
     * @param  array<string, mixed>  $pauseCollection
     */
    public function pause(array $pauseCollection = ['behavior' => 'void']): static
    {
        if (isset($pauseCollection['resumes_at']) && $pauseCollection['resumes_at'] instanceof DateTimeInterface) {
            $pauseCollection['resumes_at'] = $pauseCollection['resumes_at']->getTimestamp();
        }

        return $this->updateRidley(['pause_collection' => $pauseCollection]);
    }

    /**
     * Lift a pause (POST /subscriptions/{id}/resume).
     */
    public function unpause(): static
    {
        $resource = Ridley::client()->subscriptions()->resume($this->ridley_id, $this->pullOptions());

        return $this->syncFromRidley($resource);
    }

    /**
     * End the trial now and start billing.
     */
    public function endTrial(): static
    {
        return $this->updateRidley(['trial_end' => 'now']);
    }

    /**
     * Move the trial end to a later moment.
     */
    public function extendTrial(DateTimeInterface $date): static
    {
        if (Carbon::instance($date)->isPast()) {
            throw new InvalidArgumentException("Extending a subscription's trial requires a date in the future.");
        }

        return $this->updateRidley(['trial_end' => Carbon::instance($date)->utc()->toIso8601String()]);
    }

    public function updateDefaultPaymentMethod(string $paymentMethod): static
    {
        return $this->updateRidley(['default_payment_method' => $paymentMethod]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function updateMetadata(array $metadata): static
    {
        return $this->updateRidley(['metadata' => $metadata]);
    }

    // ---------------------------------------------------------------------
    // Previews (no change is made)
    // ---------------------------------------------------------------------

    /**
     * Quote a swap. `chargesNow()` on the result tells you whether it would be charged immediately.
     *
     * @param  string|Price|array<int|string, string|Price|array<string, mixed>>  $prices
     * @param  array<string, mixed>  $options  `proration_behavior`, `proration_date`.
     */
    public function previewSwap(string|Price|array $prices, array $options = []): Invoice
    {
        return $this->previewItems($this->swapItems($prices), $options['proration_behavior'] ?? $this->prorationBehavior(), $options);
    }

    public function previewAddPrice(string|Price $price, ?int $quantity = 1): Invoice
    {
        return $this->previewItems([$this->newItem($this->priceId($price), $quantity)], $this->prorationBehavior());
    }

    public function previewRemovePrice(string|Price $price): Invoice
    {
        return $this->previewItems([['id' => $this->findItemOrFail($price)->ridley_id, 'deleted' => true]], $this->prorationBehavior());
    }

    public function previewQuantity(int $quantity, string|Price|null $price = null): Invoice
    {
        return $this->previewItems([['id' => $this->itemFor($price)->ridley_id, 'quantity' => $quantity]], $this->prorationBehavior());
    }

    /**
     * Quote {@see change()} via the upcoming invoice, using exactly the same items and
     * proration decision. Accepts the swap shape or a PATCH item list.
     *
     * @param  array<int|string, string|Price|array<string, mixed>>  $prices
     * @param  array<string, mixed>  $options  `proration_behavior`, `proration_date`.
     */
    public function previewChange(array $prices, array $options = []): Invoice
    {
        $items = $this->changeItems($prices);

        return $this->previewItems($items, $this->changeProrationBehavior($items, $options), $options);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $options
     */
    protected function previewItems(array $items, string $behavior, array $options = []): Invoice
    {
        $prorationDate = $options['proration_date'] ?? $this->prorationDate;

        if ($prorationDate instanceof DateTimeInterface) {
            $prorationDate = $prorationDate->getTimestamp();
        }

        $query = array_filter([
            'customer' => $this->ownerCustomerId(),
            'subscription' => $this->ridley_id,
            'subscription_items' => $items,
            'subscription_proration_behavior' => $behavior,
            'subscription_proration_date' => $prorationDate,
        ], fn ($value) => $value !== null);

        return Ridley::client()->invoices()->upcoming($query);
    }

    // ---------------------------------------------------------------------
    // Invoices
    // ---------------------------------------------------------------------

    /**
     * The next renewal invoice, or null when there is nothing to invoice.
     */
    public function upcomingInvoice(): ?Invoice
    {
        try {
            return Ridley::client()->invoices()->upcoming(array_filter([
                'customer' => $this->ownerCustomerId(),
                'subscription' => $this->ridley_id,
            ]));
        } catch (NotFoundException) {
            return null;
        }
    }

    public function latestInvoice(): ?Invoice
    {
        $invoiceId = $this->asRidleySubscription()->latest_invoice;

        return $invoiceId ? Ridley::client()->invoices()->retrieve($invoiceId) : null;
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function invoices(bool $includePending = false): Collection
    {
        return Ridley::client()->invoices()
            ->all(['filter' => ['subscription' => $this->ridley_id], 'page' => ['size' => 100]])
            ->filter(fn (Invoice $invoice) => $includePending || ! $invoice->isDraft())
            ->values()
            ->collect();
    }

    public function asRidleySubscription(): RidleySubscription
    {
        return Ridley::client()->subscriptions()->retrieve($this->ridley_id);
    }

    // ---------------------------------------------------------------------
    // Sync
    // ---------------------------------------------------------------------

    /**
     * Update the local row and its items from an API resource or a webhook `data.object`.
     *
     * Dispatches {@see SubscriptionSynced} and locks the owner's billing currency.
     *
     * @param  RidleySubscription|array<string, mixed>  $subscription
     * @param  string  $source  `api` or `webhook`.
     */
    public function syncFromRidley(RidleySubscription|array $subscription, string $source = SubscriptionSynced::SOURCE_API): static
    {
        $data = $subscription instanceof RidleyObject ? $subscription->toArray() : $subscription;

        if (isset($data['attributes']) && is_array($data['attributes'])) {
            $data = array_merge(['id' => $data['id'] ?? null], $data['attributes']);
        }

        $ridleyId = $data['subscription_id'] ?? $data['id'] ?? $this->ridley_id;

        /** @var list<array{id: string, price: string, quantity: int|null, metadata: array<string, mixed>|null, product: string|null}> $items */
        $items = collect(is_array($data['items'] ?? null) ? $data['items'] : [])
            ->filter(fn ($item) => is_array($item) && ($item['id'] ?? $item['subscription_item_id'] ?? null))
            ->map(fn (array $item) => [
                'id' => (string) ($item['id'] ?? $item['subscription_item_id']),
                'price' => (string) (is_array($item['price'] ?? null) ? ($item['price']['id'] ?? '') : ($item['price'] ?? '')),
                'quantity' => isset($item['quantity']) ? (int) $item['quantity'] : null,
                'metadata' => is_array($item['metadata'] ?? null) && $item['metadata'] !== [] ? $item['metadata'] : null,
                'product' => is_string($item['product'] ?? null) ? $item['product'] : null,
            ])
            ->values()
            ->all();

        $status = (string) ($data['status'] ?? $this->ridley_status ?? self::STATUS_ACTIVE);
        $trialEnd = Timestamp::parse($data['trial_end'] ?? null);
        $periodEnd = Timestamp::parse($data['current_period_end'] ?? null);
        $endedAt = Timestamp::parse($data['ended_at'] ?? null);
        $cancelAtPeriodEnd = (bool) ($data['cancel_at_period_end'] ?? false);

        $endsAt = match (true) {
            $endedAt !== null => $endedAt,
            in_array($status, [self::STATUS_CANCELLED, self::STATUS_INCOMPLETE_EXPIRED], true) => $this->ends_at ?? Carbon::now(),
            $cancelAtPeriodEnd => $status === self::STATUS_TRIALING && $trialEnd !== null ? $trialEnd : $periodEnd,
            default => null,
        };

        DB::connection($this->getConnectionName())->transaction(function () use ($ridleyId, $items, $status, $trialEnd, $periodEnd, $endsAt, $cancelAtPeriodEnd, $data) {
            $single = count($items) === 1 ? $items[0] : null;

            $this->forceFill([
                'ridley_id' => $ridleyId,
                'ridley_status' => $status,
                'ridley_price' => $single['price'] ?? null,
                'quantity' => $single['quantity'] ?? null,
                'currency' => isset($data['currency']) ? strtoupper((string) $data['currency']) : $this->currency,
                'interval' => $data['interval'] ?? $this->interval,
                'interval_count' => isset($data['interval_count']) ? (int) $data['interval_count'] : $this->interval_count,
                'trial_ends_at' => $trialEnd,
                'ends_at' => $endsAt,
                'current_period_start' => Timestamp::parse($data['current_period_start'] ?? null),
                'current_period_end' => $periodEnd,
                'cancel_at_period_end' => $cancelAtPeriodEnd,
                'paused_at' => $status === self::STATUS_PAUSED ? ($this->paused_at ?? Carbon::now()) : null,
            ])->save();

            $model = Ridley::subscriptionItemModel();

            foreach ($items as $item) {
                $model::query()->updateOrCreate(
                    ['ridley_id' => $item['id']],
                    [
                        'subscription_id' => $this->getKey(),
                        'ridley_price' => $item['price'],
                        'ridley_product' => $item['product'],
                        'quantity' => $item['quantity'],
                        'metadata' => $item['metadata'],
                    ],
                );
            }

            $this->items()->whereNotIn('ridley_id', array_column($items, 'id'))->delete();
        });

        $this->unsetRelation('items');

        $this->afterSync($source);

        return $this;
    }

    /**
     * Lock the owner's currency and announce the mirror changed.
     */
    public function afterSync(string $source = SubscriptionSynced::SOURCE_API): void
    {
        $owner = $this->owner;

        if ($owner === null) {
            return;
        }

        if ($this->currency !== null && method_exists($owner, 'lockBillingCurrency')) {
            $owner->lockBillingCurrency($this->currency);
        }

        event(new SubscriptionSynced($this, $owner, $source));
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    protected function updateRidley(array $params): static
    {
        $resource = Ridley::client()->subscriptions()->update($this->ridley_id, $params, $this->pullOptions());

        return $this->syncFromRidley($resource);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function cancelRidley(array $params): static
    {
        $data = Ridley::client()->subscriptions()->cancel($this->ridley_id, $params)->toArray();

        $endedAt = Timestamp::parse($data['ended_at'] ?? null);

        if ($endedAt === null || $endedAt->isFuture()) {
            $data['ended_at'] = Carbon::now()->toIso8601String();
        }

        return $this->syncFromRidley($data);
    }

    /**
     * @return array{idempotency_key?: string}
     */
    protected function pullOptions(): array
    {
        $key = $this->idempotencyKey;
        $this->idempotencyKey = null;

        return $key !== null ? ['idempotency_key' => $key] : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function prorationParams(string $behavior): array
    {
        return array_filter([
            'proration_behavior' => $behavior,
            'proration_date' => $this->prorationDate,
            'billing_cycle_anchor' => $this->billingCycleAnchor,
        ], fn ($value) => $value !== null);
    }

    /**
     * PATCH items for change()/previewChange(): a PATCH item list is used as-is, anything else is a swap.
     *
     * @param  array<int|string, string|Price|array<string, mixed>>  $prices
     * @return list<array<string, mixed>>
     */
    protected function changeItems(array $prices): array
    {
        $isPatchList = $prices !== [] && array_is_list($prices) && collect($prices)->every(
            fn ($item) => is_array($item) && (isset($item['id']) || isset($item['price'])),
        );

        if ($isPatchList) {
            /** @var list<array<string, mixed>> $prices */
            return array_map(function (array $item) {
                if (isset($item['price']) && ($item['price'] instanceof Price || is_string($item['price']))) {
                    $item['price'] = PriceResolver::resolve($item['price']);
                }

                return $item;
            }, $prices);
        }

        return $this->swapItems($prices);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $options
     */
    protected function changeProrationBehavior(array $items, array $options = []): string
    {
        if (isset($options['proration_behavior']) && is_string($options['proration_behavior'])) {
            return $options['proration_behavior'];
        }

        if ($this->prorationBehavior !== null) {
            return $this->prorationBehavior;
        }

        return (string) ($this->itemsIncrease($items)
            ? config('ridley.proration.increase', 'always_invoice')
            : config('ridley.proration.decrease', 'create_prorations'));
    }

    /**
     * @param  list<array<string, mixed>>  $items  PATCH item changes.
     */
    protected function itemsIncrease(array $items): bool
    {
        $current = [];

        foreach ($this->items as $item) {
            $current[$item->ridley_id] = ['price' => $item->ridley_price, 'quantity' => $item->quantity ?? 1];
        }

        $proposed = $current;

        foreach ($items as $change) {
            $id = isset($change['id']) ? (string) $change['id'] : null;

            if ($id !== null && isset($proposed[$id])) {
                if (! empty($change['deleted'])) {
                    unset($proposed[$id]);

                    continue;
                }

                $proposed[$id] = [
                    'price' => isset($change['price']) ? (string) $change['price'] : $proposed[$id]['price'],
                    'quantity' => isset($change['quantity']) ? (int) $change['quantity'] : $proposed[$id]['quantity'],
                ];
            } elseif ($id === null && isset($change['price'])) {
                $proposed[] = ['price' => (string) $change['price'], 'quantity' => isset($change['quantity']) ? (int) $change['quantity'] : 1];
            }
        }

        $catalogue = Ridley::catalogue();
        $proposedTotal = 0;

        foreach ($proposed as $item) {
            $price = $catalogue->findByRidleyId($item['price']);

            if ($price === null) {
                return true;
            }

            if ($this->interval !== null && ($price->interval !== $this->interval || $price->interval_count !== ($this->interval_count ?? 1))) {
                return true;
            }

            $proposedTotal += $price->unit_amount * $item['quantity'];
        }

        $currentTotal = 0;

        foreach ($current as $item) {
            $currentTotal += ($catalogue->findByRidleyId($item['price'])->unit_amount ?? 0) * $item['quantity'];
        }

        return $proposedTotal > $currentTotal;
    }

    /**
     * Build the PATCH `items` diff for a swap.
     *
     * @param  string|Price|array<int|string, string|Price|array<string, mixed>>  $prices
     * @return list<array<string, mixed>>
     */
    protected function swapItems(string|Price|array $prices): array
    {
        $prices = PriceResolver::normalise($prices);

        if ($prices === []) {
            throw new InvalidArgumentException('Please provide at least one price when swapping.');
        }

        $existing = $this->items;

        if ($existing->count() === 1 && count($prices) === 1) {
            $item = $existing->first();
            $price = (string) array_key_first($prices);
            $options = $prices[$price];

            return [array_filter([
                'id' => $item->ridley_id,
                'price' => $price,
                'quantity' => array_key_exists('quantity', $options) ? $options['quantity'] : $item->quantity,
                'metadata' => $options['metadata'] ?? null,
            ], fn ($value) => $value !== null)];
        }

        $changes = [];

        foreach ($existing as $item) {
            if (array_key_exists($item->ridley_price, $prices)) {
                $options = $prices[$item->ridley_price];

                $changes[] = array_filter([
                    'id' => $item->ridley_id,
                    'quantity' => array_key_exists('quantity', $options) ? $options['quantity'] : $item->quantity,
                    'metadata' => $options['metadata'] ?? null,
                ], fn ($value) => $value !== null);

                unset($prices[$item->ridley_price]);
            } else {
                $changes[] = ['id' => $item->ridley_id, 'deleted' => true];
            }
        }

        foreach ($prices as $price => $options) {
            $changes[] = $this->newItem((string) $price, array_key_exists('quantity', $options) ? $options['quantity'] : 1, $options);
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function newItem(string $price, ?int $quantity = 1, array $options = []): array
    {
        return array_filter(array_merge(
            ['price' => $price, 'quantity' => $quantity],
            array_diff_key($options, array_flip(['quantity', 'price', 'id'])),
        ), fn ($value) => $value !== null);
    }

    protected function itemFor(string|Price|null $price): SubscriptionItem
    {
        if ($price !== null) {
            return $this->findItemOrFail($price);
        }

        if ($this->hasMultiplePrices()) {
            throw SubscriptionUpdateFailure::multiplePrices($this);
        }

        $item = $this->items->first();

        if ($item === null) {
            throw SubscriptionUpdateFailure::priceNotFound($this, (string) $this->ridley_price);
        }

        return $item;
    }

    protected function priceId(string|Price $price): string
    {
        if ($price instanceof Price) {
            return $price->ridley_price_id;
        }

        $local = $this->items->first(fn (SubscriptionItem $item) => $item->ridley_price === $price);

        return $local !== null ? $price : PriceResolver::resolve($price);
    }

    protected function guardAgainstIncomplete(): void
    {
        if ($this->incomplete()) {
            throw SubscriptionUpdateFailure::incompleteSubscription($this);
        }
    }

    protected function ownerCustomerId(): ?string
    {
        $customerId = $this->owner?->getAttribute('ridley_customer_id');

        return is_string($customerId) ? $customerId : null;
    }
}
