<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Ridley\Laravel\Ridley;

beforeEach(function () {
    Ridley::fake();
    $this->syncCatalogue();
});

it('computes features and limits from the plan and add-ons', function () {
    $user = $this->customerWithCard();

    expect($user->ridleyFeatures()->all())->toBe([])
        ->and($user->ridleyLimit('seats'))->toBe(0);

    $user->newSubscription('default')
        ->catalogue('plan', 'starter', 'USD', 'month')
        ->catalogue('addon', 'extra_seat', 'USD', 'month', quantity: 4)
        ->create();

    expect($user->ridleyFeatures()->all())->toBe(['reports'])
        ->and($user->hasRidleyFeature('api'))->toBeFalse()
        ->and($user->ridleyLimit('seats'))->toBe(7)
        ->and($user->ridleyLimit('projects'))->toBe(5);

    $user->ridleySubscription()->change(Ridley::catalogue()->resolve('USD', 'month', [['group' => 'plan', 'key' => 'professional']]));

    expect($user->hasRidleyFeature('api'))->toBeTrue()
        ->and($user->ridleyLimit('seats'))->toBe(10)
        ->and($user->ridleyLimit('projects'))->toBeNull();

    $user->ridleySubscription()->cancelNow();

    expect($user->ridleyFeatures()->all())->toBe([]);
});

it('grants generic trial access', function () {
    config(['ridley.trial' => ['catalogue_item' => 'plan.starter', 'features' => ['beta'], 'limits' => ['seats' => 2]]]);
    $user = $this->user(['trial_ends_at' => Carbon::now()->addDays(7)]);

    expect($user->onGenericTrial())->toBeTrue()
        ->and($user->onTrial())->toBeTrue()
        ->and($user->subscribed())->toBeFalse()
        ->and($user->ridleyFeatures()->sort()->values()->all())->toBe(['beta', 'reports'])
        ->and($user->ridleyLimit('seats'))->toBe(3);

    $user->update(['trial_ends_at' => Carbon::now()->subDay()]);

    expect($user->onGenericTrial())->toBeFalse()
        ->and($user->hasExpiredGenericTrial())->toBeTrue()
        ->and($user->ridleyFeatures()->all())->toBe([]);
});

it('guards routes with the middleware aliases', function () {
    Route::middleware(['web', 'ridley.subscribed'])->get('/_subscribed', fn () => 'ok');
    Route::middleware(['web', 'ridley.feature:api'])->get('/_api', fn () => 'ok');
    $user = $this->customerWithCard();

    $this->actingAs($user)->getJson('/_subscribed')->assertStatus(402)->assertJsonPath('message', 'A subscription is required.');

    config(['ridley.billing_url' => 'https://app.example.com/billing']);
    $this->actingAs($user)->get('/_subscribed')->assertRedirect('https://app.example.com/billing');

    $user->newSubscription('default')->catalogue('plan', 'starter', 'USD', 'month')->create();

    $this->actingAs($user)->get('/_subscribed')->assertOk();
    $this->actingAs($user)->getJson('/_api')->assertStatus(402);

    $user->ridleySubscription()->change(Ridley::catalogue()->resolve('USD', 'month', [['group' => 'plan', 'key' => 'professional']]));

    $this->actingAs($user)->get('/_api')->assertOk();
});
