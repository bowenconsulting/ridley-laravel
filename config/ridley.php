<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Ridley API key
    |--------------------------------------------------------------------------
    |
    | Your secret API key (sk_live_… or sk_test_…) from the Ridley dashboard.
    |
    */

    'key' => env('RIDLEY_SECRET'),

    'base_url' => env('RIDLEY_BASE_URL', 'https://api.ridleypay.com/v1'),

    /*
    |--------------------------------------------------------------------------
    | Webhook route prefix
    |--------------------------------------------------------------------------
    |
    | Webhooks are received at POST /{path}/webhook (route name ridley.webhook).
    |
    */

    'path' => env('RIDLEY_PATH', 'ridley'),

    'webhook' => [
        // One secret, or several separated by commas while rotating secrets.
        'secret' => env('RIDLEY_WEBHOOK_SECRET'),
        // Maximum age of a signed webhook in seconds; 0 disables the check.
        'tolerance' => env('RIDLEY_WEBHOOK_TOLERANCE', 300),
        // The events `php artisan ridley:webhook` subscribes the endpoint to.
        'events' => [
            'customer.updated',
            'customer.deleted',
            'payment_method.attached',
            'payment_method.updated',
            'payment_method.detached',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'customer.subscription.paused',
            'customer.subscription.resumed',
            'customer.subscription.trial_will_end',
            'invoice.finalized',
            'invoice.paid',
            'invoice.payment_failed',
            'invoice.payment_action_required',
            'invoice.upcoming',
            'payment.completed',
            'payment.failed',
            'price.created',
            'price.updated',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Billable model
    |--------------------------------------------------------------------------
    */

    'model' => env('RIDLEY_MODEL', 'App\\Models\\User'),

    'currency' => env('RIDLEY_CURRENCY', 'USD'),

    'currency_locale' => env('RIDLEY_CURRENCY_LOCALE', 'en'),

    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        'retries' => 2,
        'retry_delay_ms' => 500,
    ],

    'tables' => [
        'billable' => env('RIDLEY_BILLABLE_TABLE', 'users'),
        'subscriptions' => 'ridley_subscriptions',
        'subscription_items' => 'ridley_subscription_items',
        'webhook_events' => 'ridley_webhook_events',
        'prices' => 'ridley_prices',
    ],

    /*
    |--------------------------------------------------------------------------
    | Proration policy for Subscription::change()
    |--------------------------------------------------------------------------
    |
    | Upgrades (a higher per-period total, an interval change or an unknown
    | price) use `increase`; downgrades use `decrease`. Values: always_invoice,
    | create_prorations or none.
    |
    */

    'proration' => [
        'increase' => env('RIDLEY_PRORATION_INCREASE', 'always_invoice'),
        'decrease' => env('RIDLEY_PRORATION_DECREASE', 'create_prorations'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalogue
    |--------------------------------------------------------------------------
    |
    | Prices live on Ridley; `php artisan ridley:sync-catalogue` (and the
    | price.* webhooks) mirror them locally. A price belongs to an item by its
    | lookup key: `[{prefix}.]{group}.{item}.{currency}.{every}`, e.g.
    | `acme.plan.starter.usd.month` or `acme.addon.extra_seat.usd.3month`.
    |
    | Set a prefix when several apps share one Ridley account, so each only
    | imports its own prices.
    |
    | Under `catalogue`, define what each item grants: [group => [item_key =>
    | [name, features?, limits?, max_quantity?]]]. Ridley::defineItemsUsing()
    | adds items at runtime. When items are defined, Ridley prices for other
    | items are skipped. Optional `prices` per item are only used by
    | `ridley:push-catalogue` to create the first prices on a fresh account.
    |
    */

    'catalogue_prefix' => env('RIDLEY_CATALOGUE_PREFIX'),

    'catalogue' => [
        // 'plan' => [
        //     'starter' => [
        //         'name' => 'Starter',
        //         'features' => ['reports'],
        //         'limits' => ['seats' => 3, 'projects' => null], // null = unlimited
        //     ],
        // ],
        // 'addon' => [
        //     'extra_seat' => [
        //         'name' => 'Extra seat',
        //         'limits' => ['seats' => 1], // multiplied by quantity
        //         'max_quantity' => 50,
        //     ],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dunning notifications
    |--------------------------------------------------------------------------
    |
    | When enabled, failed payments, payments needing authentication and
    | ending trials are emailed to $billable->ridleyNotifiables().
    |
    */

    'notifications' => [
        'enabled' => env('RIDLEY_NOTIFICATIONS', false),
        'billing_url' => env('RIDLEY_BILLING_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing page
    |--------------------------------------------------------------------------
    |
    | Where the ridley.subscribed / ridley.feature middleware send customers
    | without access (JSON requests get a 402 instead).
    |
    */

    'billing_url' => env('RIDLEY_BILLING_URL'),

    /*
    |--------------------------------------------------------------------------
    | Generic trial entitlements
    |--------------------------------------------------------------------------
    |
    | What a billable on a generic trial (trial_ends_at on the billable, no
    | subscription yet) may use: the features/limits of a catalogue item
    | (e.g. 'plan.starter') and/or explicit features and limits.
    |
    | Set `generic` to false when your app tracks trials elsewhere: the
    | migration then skips the `trial_ends_at` column, and you override
    | genericTrialEndsAt() on the billable to read your own trial date.
    |
    */

    'trial' => [
        'generic' => env('RIDLEY_GENERIC_TRIALS', true),
        'catalogue_item' => env('RIDLEY_TRIAL_ITEM'),
        'features' => [],
        'limits' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Headless billing routes (optional)
    |--------------------------------------------------------------------------
    |
    | JSON/redirect endpoints for subscribing, changing plans, cancelling,
    | invoices and the portal. The prefix may contain route parameters,
    | e.g. '{account}/billing'. Route names are prefixed with `ridley.`.
    |
    */

    'routes' => [
        'enabled' => env('RIDLEY_ROUTES', false),
        'prefix' => env('RIDLEY_ROUTES_PREFIX', 'billing'),
        'middleware' => ['web', 'auth'],
    ],

];
