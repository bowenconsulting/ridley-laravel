# Ridley for Laravel

> **Status: early development (0.x).** The public API may change between minor versions until 1.0; check CHANGELOG.md before upgrading.

A Laravel Cashier-style billing client for the [Ridley](https://api.ridleypay.com) payments and billing API. It handles customers, payment methods, subscriptions, a synced product catalogue, plan changes with a proration policy, one-off payments, invoices, webhooks, dunning emails, optional entitlements and optional headless billing routes. Your app keeps its catalogue definitions, its UI and a listener or two.

- [Minimal product in 10 minutes](#minimal-product-in-10-minutes)
- [Installation](#installation)
- [Configuration](#configuration)
- [Catalogue](#catalogue)
- [Billable](#billable)
- [Subscribing](#subscribing)
- [Changing plans](#changing-plans)
- [Currency](#currency)
- [Trials](#trials)
- [Entitlements (optional)](#entitlements-optional)
- [One-off payments](#one-off-payments)
- [Invoices](#invoices)
- [Billing routes (optional)](#billing-routes-optional)
- [Webhooks & events](#webhooks--events)
- [Notifications](#notifications)
- [Ridley API gaps / notes](#ridley-api-gaps--notes)
- [Errors](#errors)
- [Testing](#testing)
- [Upgrading](#upgrading)

Requirements: PHP 8.3+, Laravel 12 or 13.

## Minimal product in 10 minutes

This walkthrough uses the config catalogue, the headless routes and the Ridley customer portal. You write no billing code.

```bash
composer require ridley/ridley-laravel
php artisan ridley:install --model="App\Models\User"
php artisan migrate
```

Add the `Billable` trait to your user model:

```php
use Ridley\Laravel\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

Fill in `.env`:

```dotenv
RIDLEY_SECRET=sk_test_...
RIDLEY_ROUTES=true
RIDLEY_BILLING_URL=/billing
```

Describe what you sell, and what each item grants, in `config/ridley.php`. The `prices` here are only a starting point for a fresh Ridley account; after the first push, prices are managed on Ridley:

```php
'catalogue' => [
    'plan' => [
        'starter' => [
            'name' => 'Starter',
            'prices' => [
                ['currency' => 'USD', 'interval' => 'month', 'amount' => '19.00'],
                ['currency' => 'USD', 'interval' => 'year', 'amount' => '190.00'],
            ],
            'features' => ['reports'],
            'limits' => ['projects' => 5],
        ],
        'pro' => [
            'name' => 'Pro',
            'prices' => [['currency' => 'USD', 'interval' => 'month', 'amount' => '49.00']],
            'features' => ['reports', 'api'],
            'limits' => ['projects' => null], // unlimited
        ],
    ],
],
```

Create the prices on Ridley, register the webhook endpoint (this prints the signing secret for `RIDLEY_WEBHOOK_SECRET`) and check the setup:

```bash
php artisan ridley:push-catalogue
php artisan ridley:webhook --url=https://your-app.example.com/ridley/webhook
php artisan ridley:doctor
```

Your billing page now only needs forms or `fetch` calls:

```html
<form method="POST" action="/billing/subscribe">
    @csrf
    <input type="hidden" name="items[0][group]" value="plan">
    <input type="hidden" name="items[0][key]" value="pro">
    <input type="hidden" name="interval" value="month">
    <button>Subscribe to Pro</button>
</form>

<a href="/billing/portal">Manage billing</a>
```

Protect paid areas with `->middleware('ridley.subscribed')` or `->middleware('ridley.feature:api')`, and check limits with `$user->ridleyLimit('projects')`.

## Installation

```bash
composer require ridley/ridley-laravel
php artisan ridley:install
php artisan migrate
```

`ridley:install` does the following:

- publishes `config/ridley.php` and the migrations;
- asks for the billable model (or takes `--model=`) and writes `ridley.model` and `ridley.tables.billable`;
- adds any missing `RIDLEY_*` keys to `.env` and `.env.example`;
- prints the next steps.

It runs non-interactively with flags:

```bash
php artisan ridley:install --model="App\Models\Account" --table=accounts --force --no-interaction
```

| Option | Meaning |
| --- | --- |
| `--model=` | Billable model class (default `App\Models\User`) |
| `--table=` | Billable table (default: the model's table) |
| `--force` | Overwrite an existing `config/ridley.php` |
| `--no-env` | Leave `.env` and `.env.example` untouched |

The migrations do the following:

- add `ridley_customer_id`, `ridley_currency`, `pm_brand`, `pm_last_four`, `pm_exp_month` and `pm_exp_year` to the billable table, plus `trial_ends_at` only when that column doesn't already exist;
- create `ridley_subscriptions`, `ridley_subscription_items`, `ridley_webhook_events` and `ridley_prices`.

Subscriptions use a polymorphic `billable` relation, so any model, or several models, can be billable.

To ship your own migrations instead, call `Ridley::ignoreMigrations()` in a service provider's `register()` method.

### Health check

`php artisan ridley:doctor` checks the following and exits non-zero on any failure:

- the API key reaches Ridley;
- the key mode matches `app.env` (a live key outside production, or a test key in production, is flagged);
- the webhook secret is set;
- the webhook route is registered;
- the billable model uses the trait and its table has the columns;
- every defined catalogue item has a current price (items without one are listed).

Run it in CI or after deploys.

## Configuration

| Key | Env | Default | Purpose |
| --- | --- | --- | --- |
| `ridley.key` | `RIDLEY_SECRET` | – | Secret API key (`sk_live_…` / `sk_test_…`) |
| `ridley.base_url` | `RIDLEY_BASE_URL` | `https://api.ridleypay.com/v1` | API base URL |
| `ridley.path` | `RIDLEY_PATH` | `ridley` | Webhook route prefix (`POST /ridley/webhook`, name `ridley.webhook`) |
| `ridley.webhook.secret` | `RIDLEY_WEBHOOK_SECRET` | – | Signing secret; comma-separate several while rotating |
| `ridley.webhook.tolerance` | `RIDLEY_WEBHOOK_TOLERANCE` | `300` | Max signature age in seconds (0 disables) |
| `ridley.webhook.events` | – | all handled events | Events `ridley:webhook` subscribes to |
| `ridley.model` | `RIDLEY_MODEL` | `App\Models\User` | Billable model used to map webhooks to customers |
| `ridley.currency` | `RIDLEY_CURRENCY` | `USD` | Default currency |
| `ridley.currency_locale` | `RIDLEY_CURRENCY_LOCALE` | `en` | Locale for `Ridley::formatAmount()` |
| `ridley.http.timeout` / `connect_timeout` / `retries` / `retry_delay_ms` | – | `30` / `10` / `2` / `500` | HTTP client settings (retries reuse the same idempotency key) |
| `ridley.tables.billable` | `RIDLEY_BILLABLE_TABLE` | `users` | Billable table for the column migration |
| `ridley.tables.subscriptions` / `subscription_items` / `webhook_events` / `prices` | – | `ridley_*` | Package table names |
| `ridley.proration.increase` | `RIDLEY_PRORATION_INCREASE` | `always_invoice` | Proration for upgrades in `change()` |
| `ridley.proration.decrease` | `RIDLEY_PRORATION_DECREASE` | `create_prorations` | Proration for downgrades in `change()` |
| `ridley.catalogue` | – | `[]` | Items and what they grant ([Catalogue](#catalogue)) |
| `ridley.catalogue_prefix` | `RIDLEY_CATALOGUE_PREFIX` | – | Lookup key prefix when several apps share a Ridley account |
| `ridley.notifications.enabled` | `RIDLEY_NOTIFICATIONS` | `false` | Send dunning emails |
| `ridley.notifications.billing_url` | `RIDLEY_BILLING_URL` | – | Fallback action URL in emails |
| `ridley.billing_url` | `RIDLEY_BILLING_URL` | – | Where the middleware redirects customers without access |
| `ridley.trial.catalogue_item` | `RIDLEY_TRIAL_ITEM` | – | Catalogue item (`group.key`) granted during a generic trial |
| `ridley.trial.features` / `ridley.trial.limits` | – | `[]` | Extra trial features and limits |
| `ridley.routes.enabled` | `RIDLEY_ROUTES` | `false` | Register the headless billing routes |
| `ridley.routes.prefix` | `RIDLEY_ROUTES_PREFIX` | `billing` | Route prefix; may contain parameters (`{account}/billing`) |
| `ridley.routes.middleware` | – | `['web', 'auth']` | Middleware for the billing routes |

You can swap the package models for your own subclasses:

```php
Ridley::useCustomerModel(Account::class);
Ridley::useSubscriptionModel(MySubscription::class);
Ridley::useSubscriptionItemModel(MySubscriptionItem::class);
Ridley::usePriceModel(MyPrice::class);
```

`Ridley::keepPastDueSubscriptionsActive()` makes `past_due` subscriptions count as active. `Ridley::ignoreRoutes()` disables every package route, including the webhook route.

**Octane:** hooks such as `defineItemsUsing`, `defineCatalogueUsing`, `resolveBillableUsing`, `authorizeUsing`, `afterActionRedirect` and `sellableUsing` are boot-time registrations. Register them in a service provider, never per request. The package keeps no request state in statics or singletons.

## Catalogue

Like Stripe, **Ridley owns prices**. The app defines its **items** (what it sells and what each one grants), and the package mirrors Ridley's prices into the local `ridley_prices` table, mapping each price ID to a `group` and `item_key` (for example `plan` / `pro`).

A Ridley price belongs to an item through its **lookup key**:

```
[{prefix}.]{group}.{item_key}.{currency}.{every}
```

For example `plan.pro.usd.month`, `addon.extra_seat.jmd.year` or `plan.pro.usd.3month` (every 3 months). Set `RIDLEY_CATALOGUE_PREFIX` (e.g. `acme`, giving `acme.plan.pro.usd.month`) when several apps share one Ridley account, so each imports only its own prices.

### Items

Define items in config:

```php
'catalogue' => [
    'plan' => [
        'pro' => [
            'name' => 'Pro',
            'features' => ['reports', 'api'],
            'limits' => ['seats' => 10, 'projects' => null], // null = unlimited
        ],
    ],
    'addon' => [
        'extra_seat' => [
            'name' => 'Extra seat',
            'limits' => ['seats' => 1], // multiplied by quantity
            'max_quantity' => 50,
        ],
    ],
],
```

Or at runtime, in a service provider (the config may be cached, so closures don't belong there):

```php
use Ridley\Laravel\Catalogue\ItemDefinition;

Ridley::defineItemsUsing(fn () => Plan::all()->map(fn (Plan $plan) => new ItemDefinition(
    group: 'plan',
    key: $plan->slug,
    name: $plan->name,
    features: $plan->features,
    limits: ['projects' => $plan->max_projects],
)));
```

The resolver may also return arrays accepted by `ItemDefinition::fromArray()`, and wins over config. When at least one item is defined, Ridley prices for undefined items are skipped (and reported). With no items, every price with a conventional lookup key is imported, without entitlements.

### Syncing from Ridley

```bash
php artisan ridley:sync-catalogue
```

The sync lists the active recurring prices on Ridley and, for each one carrying this app's lookup key:

- **Imported / updated / unchanged:** the price becomes the current price for its item, currency and interval. Features, limits and `max_quantity` come from the item.
- **Skipped:** the lookup key is malformed, doesn't match the price's currency or interval, or names an undefined item. The note says which.

Local prices Ridley no longer sells (archived, or the lookup key moved to another price) are **retired**: kept with `is_current = false`, so existing subscribers still map to their item and keep their entitlements. Item changes reach retired prices too.

Register the `price.created` and `price.updated` webhooks (in the default `ridley.webhook.events`), and the catalogue stays in step without running the command. Each sync fires `Ridley\Laravel\Events\CatalogueSynced` with the `SyncResult`s, so you can refresh anything derived from prices, such as a public pricing page.

### Changing a price

A Ridley price's amount and interval never change. To change what new customers pay, create a new price on Ridley with the same lookup key and choose to **take the lookup key over** (dashboard) or pass `transfer_lookup_key` (API). The webhook retires the old price locally. Existing subscribers stay on their old price, like Stripe, until you move them (for example with `swap()`).

### Bootstrapping a fresh account

`ridley:push-catalogue` creates prices on Ridley from price definitions: `prices` on config items, a flat list under `ridley.catalogue.prices`, or `Ridley::defineCatalogueUsing()` returning `PriceDefinition`s. It is a one-off convenience, and Ridley stays the source of truth:

```bash
php artisan ridley:push-catalogue            # creates missing prices, then syncs
php artisan ridley:push-catalogue --dry-run  # shows would_create only
php artisan ridley:push-catalogue --replace  # also replaces Ridley prices that differ
```

For each definition the push finds the Ridley price by lookup key:

- **Unchanged:** it matches, so it is only recorded locally.
- **Differs:** Ridley has a different amount or interval and is left alone, unless `--replace` creates a new price and transfers the lookup key to it.
- **Created:** there is no price yet, so the price is created on an existing product (matched via the `ridley_laravel_group` / `ridley_laravel_item` metadata) or a new product.

Old Ridley prices are never deactivated.

### Looking prices up

```php
$catalogue = Ridley::catalogue();

$catalogue->items();                                         // Collection<ItemDefinition>, keyed group.key
$catalogue->item('plan', 'pro');                             // ?ItemDefinition
$catalogue->price('plan', 'pro', 'USD', 'month');            // ?Price (current)
$catalogue->priceOrFail('plan', 'pro', 'USD', 'month');      // Price or PriceNotFound
$catalogue->findByRidleyId('price_...');                     // ?Price (current or retired)
$catalogue->prices('plan', 'USD', 'month');                  // Collection<Price>
$catalogue->drift();                                         // items without a current price

$catalogue->resolve('USD', 'month', [
    ['group' => 'plan', 'key' => 'pro'],
    ['group' => 'addon', 'key' => 'extra_seat', 'quantity' => 3],
]); // ['price_a' => ['quantity' => 1], 'price_b' => ['quantity' => 3]]
```

`resolve()` skips items with a quantity of 0 or less and merges duplicates. It throws `Ridley\Laravel\Exceptions\PriceNotFound` (with public `$group`, `$key`, `$currency`, `$interval` and `$intervalCount`) when no current price exists.

Every method that takes a price (builder, `swap`, `addPrice`, previews, `hasPrice`…) accepts a Ridley price ID or a local `Ridley\Laravel\Price` model.

## Billable

```php
use Ridley\Laravel\Billable;

class Account extends Model
{
    use Billable;
}
```

Set `ridley.model` (or use `--model` at install) to the billable class. Webhooks find the local owner by `ridley_customer_id` on that model. They fall back to the `billable_type` / `billable_id` metadata that the package writes on every customer, subscription and charge.

### Models that already have `subscription()` / `subscriptions()`

The package's canonical relations are `ridleySubscriptions(): MorphMany` and `ridleySubscription(string $type = 'default'): ?Subscription`. Every internal (status helpers, builder, webhooks…) uses these names. `subscriptions()` and `subscription()` are thin Cashier-style aliases.

If your model already defines its own `subscription()` or `subscriptions()`, keep it. Your method takes precedence over the trait's alias, and you use the `ridley*` names for billing:

```php
class Account extends Model
{
    use Billable;

    public function subscription(): HasOne   // your own relation, untouched
    {
        return $this->hasOne(LegacyPlan::class);
    }
}

$account->ridleySubscription()?->active();
$account->subscribed(); // still works – it uses ridleySubscription() internally
```

### Customers

```php
$account->createAsRidleyCustomer(['email' => 'billing@example.com']);
$account->createOrGetRidleyCustomer();
$account->updateRidleyCustomer(['name' => 'New name']);
$account->updateOrCreateRidleyCustomer();
$account->syncRidleyCustomerDetails();     // pushes ridleyName/ridleyEmail/ridleyPhone/ridleyAddress
$account->asRidleyCustomer();              // Ridley\Laravel\Resources\Customer
$account->ridleyId(); $account->hasRidleyId(); $account->ridleyCustomerExists();
$account->balance(); $account->rawBalance();
$account->creditBalance('10.00', 'Goodwill'); $account->debitBalance(500);
$account->balanceTransactions();
$account->billingPortalUrl(route('dashboard'));
return $account->redirectToBillingPortal();
```

Override `ridleyName()`, `ridleyEmail()`, `ridleyPhone()`, `ridleyAddress()`, `ridleyMetadata()` or `preferredCurrency()` to control what is sent. `createAsRidleyCustomer()` throws `InvalidCustomer::exists()` when the billable is already a customer.

### Payment methods

```php
$session = $account->paymentMethodSetupSession($successUrl, $cancelUrl); // Ridley-hosted setup
return redirect($account->updatePaymentMethodUrl($successUrl, $cancelUrl));

$account->paymentMethods();
$account->hasPaymentMethod(); $account->hasDefaultPaymentMethod();
$account->defaultPaymentMethod();
$account->updateDefaultPaymentMethod('pm_...');
$account->updateDefaultPaymentMethodFromRidley();
$account->findPaymentMethod('pm_...');
$account->deletePaymentMethod('pm_...');
```

`pm_brand`, `pm_last_four` and the expiry are mirrored locally, and webhooks keep them current.

## Subscribing

```php
$subscription = $account->newSubscription('default')
    ->catalogue('plan', 'pro', 'USD', 'month')
    ->catalogue('addon', 'extra_seat', 'USD', 'month', quantity: 2)
    ->trialDays(14)
    ->create();                        // uses the default payment method
```

Other builder methods:

- `price(string|Price $price, ?int $quantity = 1)` – add a raw Ridley price or a local `Price`; `newSubscription('default', ['price_a', $priceModel])` works too.
- `catalogueItems(array $items, string $interval, ?string $currency = null, int $intervalCount = 1)` – takes the same `items` shape as `Catalogue::resolve()`.
- `currency()`, `quantity()`, `withMetadata()`, `anchorBillingCycleOn()`.
- `trialDays()`, `trialUntil()`, `trialUntilIfFuture(?DateTimeInterface $date = null)` (only applies a future date, otherwise skips the trial; with no argument it uses the billable's generic `trial_ends_at`), `skipTrial()`.
- `paymentBehavior()`, `errorIfPaymentFails()`, `allowPaymentFailures()`, `defaultIncomplete()`.
- `add()` creates without charging, `create(?string $paymentMethod)` charges, and `checkout($successUrl, $cancelUrl)` returns a subscription-mode Checkout `Session`.

### One call: subscribe or check out

```php
$result = $account->newSubscription('default')
    ->catalogue('plan', 'pro', $account->billingCurrency(), 'month')
    ->trialUntilIfFuture()
    ->createOrCheckout(route('billing.done'), route('billing'));

if ($result->requiresCheckout()) {
    return redirect()->away($result->redirectUrl());
}

$result->subscription(); // Ridley\Laravel\Subscription
```

`createOrCheckout()` makes sure the Ridley customer exists. It then subscribes against the default payment method when there is one, or creates a Checkout session otherwise. `SubscriptionResult` exposes `subscription()`, `checkout()`, `requiresCheckout()` and `redirectUrl()`.

When a payment needs authentication, `create()` throws `IncompletePayment`. Send the customer to `$e->paymentUrl()`.

### Finding the session on return

Ridley doesn't put the session ID into `success_url`, so the package does it for you. Every hosted session it creates gets a `ridley_checkout=<ULID>` reference appended to the success URL, keeping any existing query string and fragment. This covers `checkout()`, `createOrCheckout()`, `checkoutCharge()`, `payOrCheckout()` and `paymentMethodSetupSession()`. The reference is stored in your cache for a day (`ridley:checkout:{ref}`), together with the session ID and the billable.

On your success page:

```php
$session = Ridley::checkoutSession($request, $request->user());   // ?Session

if ($session?->subscription) {
    // sync it, show a confirmation, …
}
```

`checkoutSession()` returns `null` in these cases:

- the reference is missing or expired;
- it was issued to a different billable;
- the session no longer exists or belongs to another customer.

Use a shared cache store when you run several servers. The headless `checkout/success` route uses this automatically.

### Status

```php
$account->subscribed();                         // valid 'default' subscription
$account->subscribed('default', 'price_...');   // …on a given price
$account->subscribedToPrice(['price_a', 'price_b']);
$account->subscribedToCatalogue('plan', 'pro');
$account->onTrial();                            // subscription trial OR generic trial
$account->hasExpiredTrial();
$account->onPrice('price_...');
$account->hasIncompletePayment();

$sub = $account->ridleySubscription();
$sub->active(); $sub->valid(); $sub->onTrial(); $sub->pastDue(); $sub->unpaid();
$sub->incomplete(); $sub->canceled(); $sub->onGracePeriod(); $sub->ended();
$sub->recurring(); $sub->paused(); $sub->hasPrice('price_...');
$sub->hasCatalogueItem('plan', 'pro');
$sub->live();        // still exists in Ridley, even if past due — change it rather than subscribe again
$sub->manageable();  // can be changed or cancelled (live and not waiting on its first payment)

$sub->catalogueKey('plan');            // 'pro'
$sub->itemInGroup('plan');             // ?SubscriptionItem
$sub->itemsInGroup('addon');           // Collection<SubscriptionItem>
$sub->quantityOf('addon', 'extra_seat'); // 2
$sub->selection();                     // ['plan' => ['pro' => 1], 'addon' => ['extra_seat' => 2]]

$item = $sub->items->first();
$item->catalogPrice;                   // ?Price (alias: price())
$item->group(); $item->itemKey();
```

The query scopes are `active`, `valid`, `recurring`, `canceled`, `notCanceled`, `ended`, `onTrial`, `expiredTrial`, `notOnTrial`, `onGracePeriod`, `notOnGracePeriod`, `pastDue`, `unpaid`, `incomplete` and `paused`.

### Managing

```php
$sub->cancel();               // at period end (grace period)
$sub->cancelNow();            // immediately
$sub->cancelNowAndInvoice();
$sub->resume();
$sub->pause(); $sub->unpause();
$sub->endTrial(); $sub->extendTrial(now()->addWeek());
$sub->incrementQuantity(); $sub->decrementQuantity(); $sub->updateQuantity(5, 'price_...');
$sub->addPrice('price_...', 2); $sub->removePrice('price_...');
$sub->updateDefaultPaymentMethod('pm_...');
$sub->updateMetadata(['ref' => 'abc']);
$sub->upcomingInvoice(); $sub->latestInvoice(); $sub->invoices();
$sub->syncFromRidley($sub->asRidleySubscription());
```

Each action updates the local mirror from Ridley's response and dispatches `SubscriptionSynced` (see [Webhooks & events](#webhooks--events)).

## Changing plans

```php
$prices = Ridley::catalogue()->resolve('USD', 'year', [
    ['group' => 'plan', 'key' => 'pro'],
    ['group' => 'addon', 'key' => 'extra_seat', 'quantity' => 3],
]);

$invoice = $sub->previewChange($prices);   // what the customer will pay
$sub->change($prices);                     // apply it
```

`change()` and `previewChange()` choose the proration behaviour from a policy:

- **Increase:** the new per-period total (`ridley_prices.unit_amount × quantity`) is higher than the current one, OR any new price has a different interval, OR any price is unknown to the local catalogue. This uses `ridley.proration.increase` (default `always_invoice`, so the customer pays the difference now).
- **Otherwise:** this uses `ridley.proration.decrease` (default `create_prorations`, so the customer gets a credit on the next invoice).

Calling `prorate()`, `noProrate()` or `alwaysInvoice()` (or `setProrationBehavior()`) first overrides the policy:

```php
$sub->noProrate()->change($prices);
```

`previewChange()` always makes the same decision as `change()`. `isIncrease()` and `changeProrationBehaviorFor()` expose that decision.

The lower-level Cashier-style methods remain available: `swap()`, `swapAndInvoice()`, `previewSwap()`, `previewAddPrice()`, `previewRemovePrice()`, `previewQuantity()`, `updateItems()`, `prorationDate()`, `anchorBillingCycleOn()` and `withIdempotencyKey()`.

## Currency

Ridley locks a customer to one currency once they have subscriptions or invoices. The package mirrors that lock in the billable's `ridley_currency` column:

```php
$account->billingCurrency();   // ridley_currency ?? preferredCurrency()
$account->hasLockedCurrency();
```

- The lock is set on the first subscription (created via the API or synced from a webhook) and on the first invoice-backed charge.
- `newSubscription()->create()` and `createOrCheckout()` default to `billingCurrency()`.
- Once the currency is locked, a different currency throws `Ridley\Laravel\Exceptions\CurrencyLocked`.

Override `preferredCurrency()` to choose a currency per billable before it is locked.

## Trials

The package supports two kinds of trial:

- **Subscription trials:** `trialDays()`, `trialUntil()` or `trialUntilIfFuture()` on the builder. On the subscription, use `onTrial()`, `endTrial()` and `extendTrial()`.
- **Generic trials (no card up front):** set `trial_ends_at` on the billable, for example at registration.

```php
$account->update(['trial_ends_at' => now()->addDays(14)]);

$account->onGenericTrial();          // true
$account->onTrial();                 // true – checks both kinds
$account->genericTrialEndsAt();
$account->hasExpiredGenericTrial();

// Converting later keeps the remaining trial days:
$account->newSubscription('default')->catalogue('plan', 'pro', 'USD', 'month')
    ->trialUntilIfFuture()
    ->createOrCheckout($success, $cancel);
```

If your app already tracks trials somewhere else, set `RIDLEY_GENERIC_TRIALS=false` before migrating. The billable migration then skips `trial_ends_at` and `ridley:doctor` stops requiring it. Override `genericTrialEndsAt()` to return your own date; `onGenericTrial()` and `trialUntilIfFuture()` use it:

```php
public function genericTrialEndsAt(): ?CarbonImmutable
{
    return $this->plan?->trial_ends_at?->toImmutable();
}
```

## Entitlements (optional)

This section is pure opt-in. If you have your own entitlement system, ignore it.

Features and limits come from the valid subscription's catalogue items (the plan plus add-ons):

- features are the union across items;
- additive limits are multiplied by quantity and summed;
- `null` means unlimited.

During a generic trial, the billable gets `ridley.trial.catalogue_item` plus `ridley.trial.features` / `ridley.trial.limits`.

```php
$account->ridleyFeatures();          // Collection<string>
$account->hasRidleyFeature('api');
$account->ridleyLimit('seats');      // e.g. 10 + 2 × 1 = 12; null = unlimited
```

Route middleware:

```php
Route::middleware('ridley.subscribed')->group(...);           // 'default' subscription or generic trial
Route::middleware('ridley.subscribed:premium')->group(...);   // a named subscription type
Route::middleware('ridley.feature:api')->group(...);
```

Customers without access are redirected to `config('ridley.billing_url')`. JSON requests (or apps with no billing URL) get a `402` JSON response. The billable is resolved with `Ridley::resolveBillableUsing()`.

## One-off payments

```php
$payment = $account->charge('25.00', ['description' => 'Setup fee', 'purpose' => 'setup_fee']);
$payment = $account->chargeWithInvoice('25.00', ['purpose' => 'setup_fee']);
$session = $account->checkoutCharge('25.00', 'Setup fee', $successUrl, $cancelUrl, ['purpose' => 'setup_fee']);
```

Amounts may be decimal strings (`'25.00'`), or ints/floats in major units. `purpose` and the `billable_type` / `billable_id` morph are stored in the payment's Ridley metadata.

### One call: charge or check out

```php
$result = $account->payOrCheckout('50.00', [
    'currency' => 'USD',
    'description' => 'Credit top-up',
    'purpose' => 'credit_top_up',
    'invoice' => true,
], $successUrl, $cancelUrl);

if ($result->requiresCheckout()) {
    return redirect()->away($result->redirectUrl());
}

$result->succeeded();    // bool
$result->payment();      // ?Payment
```

`payOrCheckout()` charges the default card if there is one, and otherwise creates a payment-mode Checkout session.

An invoice is only requested when `invoice` is true AND the currency equals `billingCurrency()`, because an invoice in another currency would hit Ridley's customer currency lock. Otherwise it makes a plain charge.

Either way the `purpose` reaches the `PaymentCompleted` webhook event:

```php
Event::listen(function (PaymentCompleted $event) {
    if ($event->purpose() === 'credit_top_up') {
        $event->billable?->credits()->increment($event->amountInMinorUnits());
    }
});
```

`PaymentCompleted` and `PaymentFailed` expose `purpose()`, `paymentId()`, `amountInMinorUnits()`, `currency()`, `reference()` and `metadata()`, plus the resolved `$billable`.

Ridley copies Checkout session metadata to the payment, but session payments carry no `customer_id`. The package therefore resolves the billable from the metadata morph.

`checkoutCharge()` and `payOrCheckout()` also append the `ridley_checkout` reference to the success URL. `Ridley::checkoutSession($request, $billable)?->payment_id` therefore identifies the payment when the customer returns (see [Finding the session on return](#finding-the-session-on-return)). The webhook stays the source of truth for fulfilment.

## Invoices

```php
$account->invoices();                    // Collection<Invoice>
$account->invoicesIncludingPending();
$account->findInvoice('in_...');         // only this customer's invoices
$account->findInvoiceOrFail('in_...');
$account->upcomingInvoice();
$account->payInvoice('in_...');
return $account->downloadInvoice('in_...', 'invoice.pdf');

$invoice->total(); $invoice->amountDue(); $invoice->lines(); $invoice->date();
```

## Billing routes (optional)

Set `RIDLEY_ROUTES=true` to register headless endpoints under `ridley.routes.prefix` (default `billing`), with `ridley.routes.middleware` (default `['web', 'auth']`). Route names are prefixed with `ridley.`.

| Method & URI | Name | Body / behaviour |
| --- | --- | --- |
| `POST subscribe` | `ridley.subscribe` | `{items: [{group, key, quantity?}], interval, currency?, type?}` → `createOrCheckout` |
| `POST preview` | `ridley.preview` | `{items, interval}` → JSON: amount due now, next invoice, lines, proration behaviour |
| `PUT subscription` | `ridley.subscription.update` | `{items, interval}` → `change` |
| `DELETE subscription` | `ridley.subscription.cancel` | cancel at period end |
| `POST subscription/resume` | `ridley.subscription.resume` | resume during the grace period |
| `POST payment-method` | `ridley.payment-method` | redirect to Ridley's setup checkout |
| `GET portal` | `ridley.portal` | redirect to the Ridley customer portal |
| `GET invoices` | `ridley.invoices.index` | JSON list |
| `GET invoices/{invoice}` | `ridley.invoices.show` | PDF download (only the billable's own invoices; others give 404) |
| `PUT details` | `ridley.details` | `{name?, email?, address?, tax_id?}` → customer update |
| `GET checkout/success` | `ridley.checkout.success` | syncs the session's subscription/payment, then redirects |

Every action returns JSON when the request `expectsJson()`. Otherwise it redirects (to `afterActionRedirect` or back) with a flashed `status`.

External redirects (checkout, portal, setup) work as follows:

- Inertia requests get `Inertia::location()`, or a `409` with `X-Inertia-Location` when `inertia-laravel` isn't installed.
- JSON requests get a `409` with a `Location` header.
- Other requests get a normal redirect.

Validation errors are a standard `422` or back-with-errors.

The prefix may contain route parameters, for example `'{account}/billing'`. Use the hooks to resolve the billable from them:

```php
use Ridley\Laravel\Ridley;

// AppServiceProvider::boot()
Ridley::resolveBillableUsing(fn (Request $request) => $request->route('account'));        // default: $request->user()
Ridley::authorizeUsing(fn (Request $request, Model $billable, string $ability) =>
    $request->user()->can('manageBilling', $billable));                                    // default: true
Ridley::afterActionRedirect(fn (Request $request, string $action) => route('settings.billing'));
Ridley::sellableUsing(fn (Model $billable, string $group, string $key) =>
    $group !== 'plan' || $key !== 'enterprise');                                           // default: any current catalogue price
```

The abilities passed to `authorizeUsing` are `subscribe`, `preview`, `change`, `cancel`, `resume`, `payment-method`, `portal`, `invoices`, `details` and `checkout-success`.

Quantities are capped by the catalogue item's `max_quantity`.

`checkout/success` finds the session through the `ridley_checkout` reference. You can also pass an explicit `?session_id=`. The route only syncs the session's subscription, or reports its payment, when it belongs to the current billable: the subscription's customer must match, and the payment's billable metadata must match. Anything else gets a `403`. Without a session, the route re-syncs all of the customer's subscriptions. It then refreshes the default payment method.

## Webhooks & events

The webhook route is `POST /{ridley.path}/webhook` (default `/ridley/webhook`, name `ridley.webhook`). It is CSRF-exempt and verified with the `X-Webhook-Signature` header against `ridley.webhook.secret`. Several comma-separated secrets are accepted while you rotate.

Create or update the endpoint, then copy the printed secret into `RIDLEY_WEBHOOK_SECRET`:

```bash
php artisan ridley:webhook --url=https://your-app.example.com/ridley/webhook
php artisan ridley:webhook --events=invoice.paid --events=payment.completed
php artisan ridley:webhook --roll-secret
php artisan ridley:webhook --disabled
```

Events are deduplicated by event ID (`ridley_webhook_events`), so retries are safe.

The controller performs these updates:

- mirrors subscriptions (`customer.subscription.*`);
- clears local state on `customer.deleted`;
- keeps the card details current (`customer.updated`, `payment_method.*`);
- dispatches typed events.

| Event | Fired for |
| --- | --- |
| `SubscriptionSynced($subscription, $billable, $source)` | **every** write to the local subscription mirror (`source` = `'api'` or `'webhook'`) |
| `SubscriptionCreated` / `SubscriptionUpdated` / `SubscriptionDeleted` / `SubscriptionTrialWillEnd` | subscription webhooks |
| `InvoicePaid` / `InvoicePaymentFailed` / `InvoicePaymentActionRequired` / `InvoiceFinalized` / `InvoiceUpcoming` | invoice webhooks (`invoiceId()`, `subscriptionId()`, `amountDue()`, `paymentUrl()`) |
| `PaymentCompleted` / `PaymentFailed` | payment webhooks (`purpose()`, `paymentId()`, …) |
| `PaymentMethodUpdated` | payment method webhooks |
| `WebhookReceived` / `WebhookHandled` | every webhook (raw payload) |

Typed events carry `$payload`, `$object`, `$previous` and `$billable`, plus `eventId()`, `eventType()` and `metadata()`.

One listener is enough to keep app state in step, whether the change came from your code, the portal or Ridley's dunning:

```php
Event::listen(function (SubscriptionSynced $event) {
    $event->billable->update([
        'plan' => $event->subscription->valid() ? $event->subscription->catalogueKey('plan') : null,
    ]);
});
```

To handle more events, extend the controller and add `handle{StudlyEventName}` methods, for example `handleCustomerSubscriptionCreated`. Then point your own route at your controller with `Ridley::ignoreRoutes()`, keeping the `VerifyRidleySignature` middleware.

## Notifications

Set `RIDLEY_NOTIFICATIONS=true` to email customers about these events:

| Notification | Trigger | Action |
| --- | --- | --- |
| `InvoicePaymentFailed` | `invoice.payment_failed` | "Update payment method", with the amount and next retry date |
| `InvoicePaymentActionRequired` | `invoice.payment_action_required` | "Pay invoice" |
| `TrialWillEnd` | `customer.subscription.trial_will_end` | "Update payment method" |

The action URL is the invoice's `payment_url`, otherwise `$billable->ridleyBillingUrl()`. That defaults to `ridley.notifications.billing_url` (then `ridley.billing_url`); override it when the billing page depends on the billable. The `ridley.subscribed` and `ridley.feature` middleware redirect there too:

```php
public function ridleyBillingUrl(): ?string
{
    return route('billing.show', $this);
}
```

Recipients come from `$billable->ridleyNotifiables()`. By default this is the billable itself when it uses `Notifiable`, otherwise nobody. Override it to notify, for example, an account's admins:

```php
public function ridleyNotifiables(): iterable
{
    return $this->members()->where('role', 'admin')->get();
}
```

Customise the mail views with `php artisan vendor:publish --tag=ridley-views`.

## Ridley API gaps / notes

- **No session ID in `success_url`.** Ridley has no `{CHECKOUT_SESSION_ID}` placeholder, so the package appends its own cached `ridley_checkout` reference instead (see [Finding the session on return](#finding-the-session-on-return)).
- **Session payments have no `customer_id`.** Payments from payment-mode Checkout sessions do carry the session's metadata, so the billable is resolved from the `billable_type` / `billable_id` metadata.
- **No tax ID field on customers.** `PUT details` stores `tax_id` in the customer metadata.
- **Metadata limits.** Ridley allows 50 keys, keys of up to 40 characters and scalar values of up to 500 characters. Catalogue features and limits are therefore stored as JSON strings in product and price metadata.
- **Webhook secrets** are only shown when an endpoint is created or its secret is rolled (`ridley:webhook`, `ridley:webhook --roll-secret`).
- **Webhook URLs must be publicly reachable.** Use a tunnel when developing locally.

## Errors

API errors become typed exceptions. They all extend `Ridley\Laravel\Exceptions\RidleyException`.

| Exception | When |
| --- | --- |
| `AuthenticationException` | 401 – bad API key |
| `PermissionException` | 403 / `plan_upgrade_required` |
| `NotFoundException` | 404 |
| `IdempotencyException` | 409 / `idempotency_*` |
| `InvalidRequestException` | 400 / 422 validation errors |
| `CardException` | 402 / card decline codes |
| `IncompletePayment` | `authentication_required`, or a subscription left `incomplete` (`paymentUrl()`) |
| `RateLimitException` | 429 (after retries) |
| `ApiException` | 5xx (after retries) |
| `ApiConnectionException` | network failures |
| `InvalidCustomer` | the billable is not, or is already, a Ridley customer |
| `SubscriptionUpdateFailure` | invalid local subscription operations |
| `PriceNotFound` | a catalogue price is missing (`$group`, `$key`, `$currency`, `$interval`) |
| `CurrencyLocked` | a currency other than the locked `billingCurrency()` was requested |
| `InvalidSignature` | a webhook signature mismatch |

`Ridley::client()` gives raw access to every API service (`customers()`, `subscriptions()`, `prices()`, `products()`, `invoices()`, `sessions()`, `webhookEndpoints()`, …).

## Testing

### `Ridley::fake()`

`Ridley::fake()` replaces the HTTP layer with an in-memory, stateful Ridley. Customers, prices, subscriptions, invoices, sessions and payments behave like the real API, so no network calls are made:

```php
use Ridley\Laravel\Ridley;

it('subscribes to pro', function () {
    $fake = Ridley::fake();
    Artisan::call('ridley:push-catalogue'); // or $fake->addPrice(['lookup_key' => 'plan.pro.usd.month', 'unit_amount' => '49.00']) and sync

    $user = User::factory()->create();
    $fake->addPaymentMethod($user->createOrGetRidleyCustomer()->id());
    $user->updateDefaultPaymentMethodFromRidley();

    $user->newSubscription('default')->catalogue('plan', 'pro', 'USD', 'month')->create();

    expect($user->subscribedToCatalogue('plan', 'pro'))->toBeTrue();
    $fake->assertCreated('subscriptions');
});
```

The fake also offers:

- seeding: `addCustomer()`, `addPaymentMethod()`, `addProduct()`, `addPrice()`, `addInvoice()`;
- `failNextCharge('card_declined')` and `completeCheckout($sessionId)`;
- `override(['GET customers/cus_x' => [...] | fn ($request) => [...]])`;
- assertions: `assertSent()`, `assertNotSent()`, `assertNothingSent()`, `assertSentCount()`, `assertCreated()`, `assertUpdated()`, `assertCharged()`.

### Webhooks

```php
use Ridley\Laravel\Testing\InteractsWithRidleyWebhooks;

uses(InteractsWithRidleyWebhooks::class);

it('notices a cancellation', function () {
    config(['ridley.webhook.secret' => 'whsec_test']);
    // …create $user with a subscription…

    $this->postRidleyWebhook('customer.subscription.deleted', [
        'subscription_id' => $subscription->ridley_id,
        'customer' => $user->ridleyId(),
        'status' => 'canceled',
    ])->assertOk();

    expect($user->fresh()->subscribed())->toBeFalse();
});
```

`postRidleyWebhook()` builds a correctly signed payload, using `Ridley::webhookPayload()`, which you can also use directly.

## Upgrading

This package follows [Semantic Versioning](https://semver.org). While it is in 0.x, minor versions (0.1 → 0.2) may contain breaking changes, which [CHANGELOG.md](CHANGELOG.md) lists under **Breaking**. Patch versions are always safe.

After upgrading, run:

```bash
php artisan vendor:publish --tag=ridley-migrations   # new migrations, if any
php artisan migrate
php artisan ridley:doctor
```

## License

MIT – see [LICENSE](LICENSE).
