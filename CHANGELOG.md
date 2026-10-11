# Changelog

All notable changes to `ridley/ridley-laravel` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Versioning

- **0.x (now):** minor releases (`0.1` → `0.2`) may contain breaking changes, listed under **Breaking**. Patch releases (`0.1.0` → `0.1.1`) are always backwards compatible. Constrain with `^0.1` and read this file before bumping the minor version.
- **1.0 and later:** breaking changes only in major releases. The public API is every public method on `Ridley`, the `Billable` trait, `Subscription`, `SubscriptionItem`, `SubscriptionBuilder`, `Price`, `Catalogue`, the result and event classes, the config keys, the console commands and the billing routes.
- Releases that add migrations say so. Re-publish with `php artisan vendor:publish --tag=ridley-migrations`.

## [Unreleased]

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
