# Changelog

All notable changes to `ridley/ridley-laravel` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Versioning

- **0.x (now):** minor releases (`0.1` → `0.2`) may contain breaking changes, listed under **Breaking**. Patch releases (`0.1.0` → `0.1.1`) are always backwards compatible. Constrain with `^0.1` and read this file before bumping the minor version.
- **1.0 and later:** breaking changes only in major releases. The public API is every public method on `Ridley`, the `Billable` trait, `Subscription`, `SubscriptionItem`, `SubscriptionBuilder`, `Price`, `Catalogue`, the result and event classes, the config keys, the console commands and the billing routes.
- Releases that add migrations say so. Re-publish with `php artisan vendor:publish --tag=ridley-migrations`.

## [Unreleased]

## [0.3.0] - 2026-10-13

This release adds a migration.

### Breaking

- Customer state moved off the billable table into a polymorphic `ridley_customers` table (`Ridley\Laravel\Customer`, reached through `$billable->ridleyCustomer`), like Cashier Paddle. It holds the Ridley ID (`ridley_id`), the locked `currency`, the default card's `pm_*` display details and the generic `trial_ends_at`. The `add_ridley_columns_to_billable_table` migration is replaced by `create_ridley_customers_table`. Upgrading apps copy their billable's `ridley_customer_id`, `ridley_currency`, `pm_*` and (generic) `trial_ends_at` into the new table, then drop those columns.
- Removed the `ridley.tables.billable` (`RIDLEY_BILLABLE_TABLE`) and `ridley.trial.generic` (`RIDLEY_GENERIC_TRIALS`) config keys and the `--table` option of `ridley:install`.
- `ridley:doctor` checks for the `ridley_customers` table instead of billable columns.

### Added

- `Billable::ridleyCustomer()` and `Billable::updateRidleyCustomerRecord()`.
- `Customer::hasDefaultPaymentMethod()`, `hasExpiredCard()`, `onGenericTrial()` and `hasExpiredGenericTrial()`.
- `Ridley::useRidleyCustomerModel()` / `Ridley::ridleyCustomerModel()`.
- `Ridley::findBillable()` resolves any billable model type through `ridley_customers`, not only `ridley.model`.

## [0.2.1] - 2026-10-12

### Fixed

- The billing routes answer a failed Ridley API call with a `billing` validation error (a card decline's own message, or a generic one after reporting the exception) instead of a server error.
- `ridley:push-catalogue` no longer reuses a product cached from the other mode (a test key's product after switching to a live key); it checks the product exists and otherwise finds or creates one.

## [0.2.0] - 2026-10-12

Ridley now owns prices, as Stripe does. The app defines items (what it sells and what each one grants), and the package pulls the prices from Ridley.

### Breaking

- `php artisan ridley:sync-catalogue` and `Catalogue::sync()` now **pull** prices from Ridley instead of pushing definitions. The old push is `ridley:push-catalogue` / `Catalogue::push()`, and it no longer replaces Ridley prices that differ unless you pass `--replace` / `replace: true`.
- Prices map to items by lookup key, `[{prefix}.]{group}.{item}.{currency}.{every}`. Prices pushed by 0.1 already follow it. Prices created by hand on Ridley need such a lookup key.
- `Catalogue::drift()` returns `[{item, reason}]` for items without a current price, instead of `[{lookup_key, reason}]` for definitions out of date.
- `price.created` and `price.updated` are added to the default `ridley.webhook.events`. Re-run `php artisan ridley:webhook --url=…` to subscribe an existing endpoint.

### Added

- `ItemDefinition` and `Ridley::defineItemsUsing()`; `Catalogue::items()`, `item()`, `syncPrice()` and `applyItems()`.
- `ridley.catalogue_prefix` (`RIDLEY_CATALOGUE_PREFIX`) for apps sharing one Ridley account, and `Catalogue\LookupKey`.
- `price.created` / `price.updated` webhook handling, and the `CatalogueSynced` event.
- `SyncResult` actions `imported`, `updated`, `retired`, `skipped` and `differs`, and `SyncResult::$note`.
- `FakeRidley` filters prices by `type`.

### Upgrading

1. Move features, limits and `max_quantity` you defined only in `defineCatalogueUsing()` to `defineItemsUsing()` (definitions still supply items, so this is optional).
2. Run `php artisan ridley:webhook --url=…` to add the price events, then `php artisan ridley:sync-catalogue`.
3. Manage amounts on Ridley from now on.

## [0.1.4] - 2026-10-12

### Fixed

- Subscription dates (`trial_ends_at`, `current_period_end`, `ends_at`, …) were stored hours off when Ridley's offset differed from the app's timezone. Ridley timestamps are now converted to the app's timezone before saving.
- `testClocks()->advance()` sends ISO 8601, which Ridley requires, instead of a Unix timestamp.

## [0.1.3] - 2026-10-12

### Added

- `Subscription::live()` and `Subscription::manageable()`.

### Fixed

- The billing routes no longer start a second subscription when the billable's current one is past due, unpaid or waiting on its first payment.
- Past-due and unpaid subscriptions can be previewed, changed and cancelled through the billing routes, as Ridley allows.

## [0.1.2] - 2026-10-12

### Added

- `Billable::ridleyBillingUrl()`: the billing page for a billable, used by notifications and the `ridley.subscribed` / `ridley.feature` middleware. Override it when the URL depends on the billable (an account slug in the path). Defaults to `ridley.notifications.billing_url` / `ridley.billing_url`.
- `RidleyBillingNotification::forBillable()`.

## [0.1.1] - 2026-10-11

### Added

- `ridley.trial.generic` (`RIDLEY_GENERIC_TRIALS`): set it to false to keep trials outside the billable. The migration skips `trial_ends_at` and `ridley:doctor` stops requiring it.

### Fixed

- `trialUntilIfFuture()` now reads the default date through `genericTrialEndsAt()`, so overriding that method works.

## [0.1.0] - 2026-10-11

Initial early-development release.

### Added

- HTTP client for the Ridley JSON:API with typed resources, typed exceptions, retries with stable idempotency keys and cursor pagination.
- `Billable` trait:
  - customers, balances and the portal;
  - payment methods and setup checkout;
  - subscriptions, using the canonical `ridleySubscriptions()` / `ridleySubscription()` names plus Cashier-style aliases;
  - charges, invoices and PDFs.
- `SubscriptionBuilder` with trials, catalogue items, `createOrCheckout()` and `trialUntilIfFuture()`.
- `Subscription` management: swap, change with a proration policy (`ridley.proration.*`), previews, quantities, add-ons, cancel, resume, pause and status helpers and scopes.
- Catalogue:
  - config or runtime definitions;
  - `ridley_prices` mapping table;
  - `ridley:sync-catalogue` command;
  - `Catalogue::resolve()` and `PriceNotFound`.
- Currency lock (`ridley_currency`, `billingCurrency()`, `CurrencyLocked`).
- One-off payments with `purpose` metadata, and `payOrCheckout()` / `PaymentResult`.
- Generic trials (`trial_ends_at`, `onGenericTrial()`).
- Optional entitlements (`ridleyFeatures()`, `hasRidleyFeature()`, `ridleyLimit()`) and the `ridley.subscribed` / `ridley.feature` middleware.
- Optional headless billing routes with resolver, authorisation, redirect and sellable hooks.
- Webhook controller:
  - signature verification with secret rotation;
  - deduplication;
  - subscription mirroring;
  - typed events and `SubscriptionSynced`.
- Optional dunning mail notifications.
- Checkout return references: hosted sessions get `ridley_checkout=<ulid>` on the success URL, and `Ridley::checkoutSession()` finds the session again. The `checkout/success` route verifies that the subscription and payment belong to the billable.
- Console commands `ridley:install`, `ridley:doctor` and `ridley:webhook`.
- Testing helpers `Ridley::fake()` (a stateful in-memory API) and `InteractsWithRidleyWebhooks`.
