<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Ridley\Laravel\Catalogue\ItemDefinition;
use Ridley\Laravel\Catalogue\LookupKey;
use Ridley\Laravel\Catalogue\PriceDefinition;
use Ridley\Laravel\Catalogue\SyncResult;
use Ridley\Laravel\Events\CatalogueSynced;
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

it('pushes definitions: created, unchanged, differs, replaced and dry run', function () {
    $fake = Ridley::fake();
    config(['ridley.catalogue' => $this->catalogueConfig()]);

    $results = collect(Ridley::catalogue()->push());

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
        ->and($ridleyPrice['lookup_key'])->toBe('plan.professional.usd.month');

    expect(collect(Ridley::catalogue()->push())->pluck('action')->unique()->all())->toBe([SyncResult::UNCHANGED]);

    $config = $this->catalogueConfig();
    $config['plan']['professional']['prices'][0]['amount'] = '59.00';
    config(['ridley.catalogue' => $config]);

    $differs = collect(Ridley::catalogue()->push())->keyBy('lookupKey');
    expect($differs['plan.professional.usd.month']->action)->toBe(SyncResult::DIFFERS)
        ->and($differs['plan.professional.usd.month']->note)->toContain('49.00')
        ->and(Ridley::catalogue()->price('plan', 'professional', 'USD', 'month')->unit_amount)->toBe(4900);

    $dryRun = collect(Ridley::catalogue()->push(dryRun: true, replace: true))->keyBy('lookupKey');
    expect($dryRun['plan.professional.usd.month']->action)->toBe(SyncResult::WOULD_REPLACE)
        ->and(Ridley::catalogue()->price('plan', 'professional', 'USD', 'month')->unit_amount)->toBe(4900);

    $results = collect(Ridley::catalogue()->push(replace: true))->keyBy('lookupKey');
    $current = Ridley::catalogue()->price('plan', 'professional', 'USD', 'month');

    expect($results['plan.professional.usd.month']->action)->toBe(SyncResult::REPLACED)
        ->and($current->unit_amount)->toBe(5900)
        ->and($current->ridley_product_id)->toBe($pro->ridley_product_id)
        ->and(Ridley::catalogue()->findByRidleyId($pro->ridley_price_id)?->is_current)->toBeFalse()
        ->and($fake->find($pro->ridley_price_id)['active'])->toBeTrue()
        ->and(Ridley::catalogue()->drift())->toBe([]);
});

it('reuses a Ridley product found by metadata', function () {
    $fake = Ridley::fake();
    $product = $fake->addProduct(['name' => 'Existing', 'metadata' => ['ridley_laravel_group' => 'plan', 'ridley_laravel_item' => 'pro']]);

    Ridley::defineCatalogueUsing(fn () => [new PriceDefinition('plan', 'pro', 'Pro', 'USD', 'month', 4900)]);
    Ridley::catalogue()->push();

    expect(Price::query()->sole()->ridley_product_id)->toBe($product['id'])
        ->and($fake->all('products'))->toHaveCount(1);
});

it('parses lookup keys, with and without a prefix', function () {
    expect(LookupKey::parse('plan.pro.usd.month'))->toBe(['group' => 'plan', 'item_key' => 'pro', 'currency' => 'USD', 'interval' => 'month', 'interval_count' => 1])
        ->and(LookupKey::parse('addon.extra.seat.jmd.3month'))->toBe(['group' => 'addon', 'item_key' => 'extra.seat', 'currency' => 'JMD', 'interval' => 'month', 'interval_count' => 3])
        ->and(LookupKey::parse('plan.pro.month'))->toBeNull()
        ->and(LookupKey::parse('plan.pro.usd.fortnight'))->toBeNull()
        ->and(LookupKey::parse(null))->toBeNull();

    config(['ridley.catalogue_prefix' => 'acme']);

    expect(LookupKey::make('plan', 'pro', 'usd', 'year'))->toBe('acme.plan.pro.usd.year')
        ->and(LookupKey::parse('acme.plan.pro.usd.year')['item_key'])->toBe('pro')
        ->and(LookupKey::parse('other.plan.pro.usd.year'))->toBeNull()
        ->and(LookupKey::parse('plan.pro.usd.year'))->toBeNull()
        ->and((new PriceDefinition('plan', 'pro', 'Pro', 'USD', 'month', 100))->lookupKey)->toBe('acme.plan.pro.usd.month');
});

it('pulls prices from Ridley by lookup key and retires the ones Ridley stops selling', function () {
    Event::fake([CatalogueSynced::class]);
    $fake = Ridley::fake();
    config(['ridley.catalogue_prefix' => 'acme']);
    Ridley::defineItemsUsing(fn () => [
        new ItemDefinition('plan', 'pro', 'Pro', features: ['api'], limits: ['seats' => 10]),
        ['group' => 'addon', 'key' => 'seat', 'limits' => ['seats' => 1], 'max_quantity' => 5],
    ]);

    $pro = $fake->addPrice(['lookup_key' => 'acme.plan.pro.usd.month', 'unit_amount' => '49.00']);
    $seat = $fake->addPrice(['lookup_key' => 'acme.addon.seat.usd.month', 'unit_amount' => '5.00']);
    $fake->addPrice(['lookup_key' => 'other-app.plan.pro.usd.month']);
    $fake->addPrice(['lookup_key' => null]);
    $fake->addPrice(['lookup_key' => 'acme.plan.pro.usd.year', 'interval' => 'month']);
    $fake->addPrice(['lookup_key' => 'acme.plan.unknown.usd.month']);
    $fake->addPrice(['lookup_key' => 'acme.plan.pro.eur.month', 'currency' => 'EUR', 'active' => false]);
    $fake->addPrice(['lookup_key' => 'acme.plan.pro.usd.week', 'interval' => 'week', 'type' => 'one_time']);

    $results = collect(Ridley::catalogue()->sync())->keyBy('lookupKey');

    expect($results->keys()->all())->toEqualCanonicalizing(['acme.plan.pro.usd.month', 'acme.addon.seat.usd.month', 'acme.plan.pro.usd.year', 'acme.plan.unknown.usd.month'])
        ->and($results['acme.plan.pro.usd.month']->action)->toBe(SyncResult::IMPORTED)
        ->and($results['acme.plan.pro.usd.year']->action)->toBe(SyncResult::SKIPPED)
        ->and($results['acme.plan.unknown.usd.month']->note)->toContain('no item [plan.unknown]')
        ->and(Price::query()->count())->toBe(2);

    $local = Ridley::catalogue()->price('plan', 'pro', 'USD', 'month');
    expect($local->ridley_price_id)->toBe($pro['id'])
        ->and($local->unit_amount)->toBe(4900)
        ->and($local->features)->toBe(['api'])
        ->and($local->limits)->toBe(['seats' => 10])
        ->and(Ridley::catalogue()->price('addon', 'seat', 'USD', 'month')->max_quantity)->toBe(5);

    Event::assertDispatched(CatalogueSynced::class, fn (CatalogueSynced $event) => count($event->results) === 4);

    // A new price takes the lookup key over; the old one is retired but kept.
    $fake->addPrice(['lookup_key' => 'acme.plan.pro.usd.month', 'unit_amount' => '59.00']);
    Ridley::client()->prices()->update($pro['id'], ['lookup_key' => null]);
    Ridley::client()->prices()->update($seat['id'], ['active' => false]);

    $results = collect(Ridley::catalogue()->sync());

    expect(Ridley::catalogue()->price('plan', 'pro', 'USD', 'month')->unit_amount)->toBe(5900)
        ->and(Ridley::catalogue()->findByRidleyId($pro['id'])->is_current)->toBeFalse()
        ->and(Ridley::catalogue()->price('addon', 'seat', 'USD', 'month'))->toBeNull()
        ->and($results->where('action', SyncResult::RETIRED)->pluck('ridleyPriceId')->all())->toEqualCanonicalizing([$pro['id'], $seat['id']])
        ->and(Ridley::catalogue()->drift())->toBe([['item' => 'addon.seat', 'reason' => 'no current price; add one on Ridley with a lookup key like acme.addon.seat.usd.month']]);

    // Changing what an item grants reaches retired prices (existing subscribers) too.
    Ridley::defineItemsUsing(fn () => [new ItemDefinition('plan', 'pro', features: ['api', 'sso'])]);
    Ridley::catalogue()->sync();

    expect(Ridley::catalogue()->findByRidleyId($pro['id'])->features)->toBe(['api', 'sso']);
});

it('imports every conventional price when no items are defined', function () {
    $fake = Ridley::fake();
    $fake->addPrice(['lookup_key' => 'plan.pro.usd.month']);

    expect(collect(Ridley::catalogue()->sync())->sole()->action)->toBe(SyncResult::IMPORTED)
        ->and(Ridley::catalogue()->price('plan', 'pro', 'USD', 'month'))->not->toBeNull();
});

it('mirrors price webhooks', function () {
    Event::fake([CatalogueSynced::class]);
    Ridley::defineItemsUsing(fn () => [new ItemDefinition('plan', 'pro', limits: ['seats' => 10])]);
    $price = fn (array $overrides = []) => array_merge([
        'price_id' => 'price_1', 'product' => 'prod_1', 'type' => 'recurring', 'currency' => 'USD', 'unit_amount' => '49.00',
        'interval' => 'month', 'interval_count' => 1, 'lookup_key' => 'plan.pro.usd.month', 'active' => true, 'livemode' => false, 'metadata' => [],
    ], $overrides);

    $this->postRidleyWebhook('price.created', $price())->assertOk();

    expect(Ridley::catalogue()->price('plan', 'pro', 'USD', 'month')->ridley_price_id)->toBe('price_1')
        ->and(Ridley::catalogue()->price('plan', 'pro', 'USD', 'month')->limits)->toBe(['seats' => 10]);

    $this->postRidleyWebhook('price.created', $price(['price_id' => 'price_2', 'unit_amount' => '59.00']))->assertOk();

    expect(Ridley::catalogue()->price('plan', 'pro', 'USD', 'month')->unit_amount)->toBe(5900)
        ->and(Ridley::catalogue()->findByRidleyId('price_1')->is_current)->toBeFalse();

    $this->postRidleyWebhook('price.created', $price(['price_id' => 'price_live', 'livemode' => true]))->assertOk();
    $this->postRidleyWebhook('price.updated', $price(['price_id' => 'price_2', 'active' => false]))->assertOk();

    expect(Ridley::catalogue()->findByRidleyId('price_live'))->toBeNull()
        ->and(Ridley::catalogue()->price('plan', 'pro', 'USD', 'month'))->toBeNull();

    Event::assertDispatchedTimes(CatalogueSynced::class, 3);
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

it('pushes and pulls from the commands', function () {
    $fake = Ridley::fake();
    config(['ridley.catalogue' => $this->catalogueConfig()]);

    $this->artisan('ridley:push-catalogue', ['--dry-run' => true])
        ->expectsOutputToContain('plan.professional.usd.month')
        ->assertSuccessful();

    expect(Price::query()->count())->toBe(0);

    $this->artisan('ridley:push-catalogue')->assertSuccessful();

    expect(Price::query()->count())->toBe(6);

    $fake->addPrice(['lookup_key' => 'plan.mystery.usd.month']);

    $this->artisan('ridley:sync-catalogue')
        ->expectsOutputToContain('no item [plan.mystery]')
        ->expectsOutputToContain('1 price(s) skipped')
        ->assertSuccessful();
});
