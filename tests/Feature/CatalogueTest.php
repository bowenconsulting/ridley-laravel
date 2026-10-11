<?php

declare(strict_types=1);

use Ridley\Laravel\Catalogue\PriceDefinition;
use Ridley\Laravel\Catalogue\SyncResult;
use Ridley\Laravel\Exceptions\PriceNotFound;
use Ridley\Laravel\Price;
use Ridley\Laravel\Ridley;

it('builds lookup keys and parses definitions', function () {
    $monthly = PriceDefinition::fromArray(['group' => 'plan', 'item_key' => 'pro', 'currency' => 'usd', 'interval' => 'month', 'amount' => '49.00']);
    $quarterly = new PriceDefinition('plan', 'pro', 'Pro', 'USD', 'month', 12900, intervalCount: 3);

    expect($monthly->lookupKey)->toBe('plan.pro.usd.month')
        ->and($monthly->currency)->toBe('USD')
        ->and($monthly->unitAmount)->toBe(4900)
        ->and($quarterly->lookupKey)->toBe('plan.pro.usd.3month');

    expect(fn () => PriceDefinition::fromArray(['group' => 'plan']))->toThrow(InvalidArgumentException::class);
});

it('merges config and resolver definitions with the resolver winning', function () {
    config(['ridley.catalogue' => [
        'plan' => ['pro' => ['name' => 'Pro', 'prices' => [['currency' => 'USD', 'interval' => 'month', 'amount' => '49.00']], 'features' => ['api']]],
        'prices' => [['group' => 'addon', 'item_key' => 'seat', 'currency' => 'USD', 'interval' => 'month', 'amount' => '5.00']],
    ]]);

    Ridley::defineCatalogueUsing(fn () => [
        ['group' => 'plan', 'item_key' => 'pro', 'currency' => 'USD', 'interval' => 'month', 'unit_amount' => 5900],
        new PriceDefinition('plan', 'basic', 'Basic', 'USD', 'month', 900),
    ]);

    $definitions = Ridley::catalogue()->definitions();

    expect($definitions->keys()->all())->toEqualCanonicalizing(['plan.pro.usd.month', 'addon.seat.usd.month', 'plan.basic.usd.month'])
        ->and($definitions['plan.pro.usd.month']->unitAmount)->toBe(5900);
});

it('syncs the catalogue: created, unchanged, replaced and dry run', function () {
    $fake = Ridley::fake();
    config(['ridley.catalogue' => $this->catalogueConfig()]);

    $results = collect(Ridley::catalogue()->sync());

    expect($results)->toHaveCount(6)
        ->and($results->every(fn (SyncResult $result) => $result->action === SyncResult::CREATED))->toBeTrue()
        ->and(Price::query()->count())->toBe(6)
        ->and($fake->all('products'))->toHaveCount(3);

    $pro = Ridley::catalogue()->price('plan', 'professional', 'usd', 'month');
    $ridleyPrice = $fake->find($pro->ridley_price_id);

    expect($pro->unit_amount)->toBe(4900)
        ->and($pro->features)->toBe(['reports', 'api'])
        ->and($pro->limits)->toBe(['seats' => 10, 'projects' => null])
        ->and($ridleyPrice['metadata']['ridley_laravel_group'])->toBe('plan')
        ->and($ridleyPrice['metadata']['ridley_laravel_item'])->toBe('professional')
        ->and($ridleyPrice['lookup_key'])->toBe('plan.professional.usd.month');

    expect(collect(Ridley::catalogue()->sync())->pluck('action')->unique()->all())->toBe([SyncResult::UNCHANGED]);

    $config = $this->catalogueConfig();
    $config['plan']['professional']['prices'][0]['amount'] = '59.00';
    config(['ridley.catalogue' => $config]);

    $dryRun = collect(Ridley::catalogue()->sync(dryRun: true))->keyBy('lookupKey');
    expect($dryRun['plan.professional.usd.month']->action)->toBe(SyncResult::WOULD_REPLACE)
        ->and(Ridley::catalogue()->price('plan', 'professional', 'USD', 'month')->unit_amount)->toBe(4900);

    $results = collect(Ridley::catalogue()->sync())->keyBy('lookupKey');
    $current = Ridley::catalogue()->price('plan', 'professional', 'USD', 'month');

    expect($results['plan.professional.usd.month']->action)->toBe(SyncResult::REPLACED)
        ->and($current->unit_amount)->toBe(5900)
        ->and($current->ridley_product_id)->toBe($pro->ridley_product_id)
        ->and(Ridley::catalogue()->findByRidleyId($pro->ridley_price_id)?->is_current)->toBeFalse()
        ->and($fake->find($pro->ridley_price_id)['active'])->toBeTrue()
        ->and($fake->all('products'))->toHaveCount(3)
        ->and(Ridley::catalogue()->drift())->toBe([]);
});

it('reuses a Ridley product found by metadata', function () {
    $fake = Ridley::fake();
    $product = $fake->addProduct(['name' => 'Existing', 'metadata' => ['ridley_laravel_group' => 'plan', 'ridley_laravel_item' => 'pro']]);

    Ridley::defineCatalogueUsing(fn () => [new PriceDefinition('plan', 'pro', 'Pro', 'USD', 'month', 4900)]);
    Ridley::catalogue()->sync();

    expect(Price::query()->sole()->ridley_product_id)->toBe($product['id'])
        ->and($fake->all('products'))->toHaveCount(1);
});

it('resolves selections into swap shapes', function () {
    Ridley::fake();
    $this->syncCatalogue();
    $catalogue = Ridley::catalogue();

    $resolved = $catalogue->resolve('usd', 'month', [
        ['group' => 'plan', 'key' => 'professional'],
        ['group' => 'addon', 'key' => 'extra_seat', 'quantity' => 2],
        ['group' => 'addon', 'key' => 'extra_seat', 'quantity' => 1],
        ['group' => 'addon', 'key' => 'ignored', 'quantity' => 0],
    ]);

    expect($resolved)->toBe([
        $catalogue->price('plan', 'professional', 'USD', 'month')->ridley_price_id => ['quantity' => 1],
        $catalogue->price('addon', 'extra_seat', 'USD', 'month')->ridley_price_id => ['quantity' => 3],
    ]);

    try {
        $catalogue->resolve('EUR', 'month', [['group' => 'addon', 'key' => 'extra_seat']]);
        $this->fail('Expected PriceNotFound');
    } catch (PriceNotFound $exception) {
        expect($exception->group)->toBe('addon')
            ->and($exception->key)->toBe('extra_seat')
            ->and($exception->currency)->toBe('EUR')
            ->and($exception->interval)->toBe('month');
    }

    expect($catalogue->prices('plan', 'USD', 'month'))->toHaveCount(2);
});

it('prints a table from the sync command', function () {
    Ridley::fake();
    config(['ridley.catalogue' => $this->catalogueConfig()]);

    $this->artisan('ridley:sync-catalogue', ['--dry-run' => true])
        ->expectsOutputToContain('plan.professional.usd.month')
        ->assertSuccessful();

    expect(Price::query()->count())->toBe(0);

    $this->artisan('ridley:sync-catalogue')->assertSuccessful();

    expect(Price::query()->count())->toBe(6);
});
