<?php

declare(strict_types=1);

namespace Ridley\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\TestCase as Orchestra;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\RidleyServiceProvider;
use Ridley\Laravel\Testing\FakeRidley;
use Ridley\Laravel\Testing\InteractsWithRidleyWebhooks;
use Ridley\Laravel\Tests\Fixtures\User;

abstract class TestCase extends Orchestra
{
    use InteractsWithRidleyWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        Ridley::flushState();

        parent::setUp();

        Sleep::fake();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RidleyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('ridley.key', 'sk_test_123');
        $app['config']->set('ridley.base_url', 'https://api.ridley.test/v1');
        $app['config']->set('ridley.model', User::class);
        $app['config']->set('ridley.webhook.secret', 'whsec_test');
        $app['config']->set('ridley.http.retry_delay_ms', 0);
        $app['config']->set('ridley.routes.enabled', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    /**
     * A grouped catalogue: two plans and a seat add-on in USD (monthly and yearly).
     *
     * @return array<string, mixed>
     */
    protected function catalogueConfig(): array
    {
        return [
            'plan' => [
                'starter' => [
                    'name' => 'Starter',
                    'prices' => [
                        ['currency' => 'USD', 'interval' => 'month', 'amount' => '19.00'],
                        ['currency' => 'USD', 'interval' => 'year', 'amount' => '190.00'],
                    ],
                    'features' => ['reports'],
                    'limits' => ['seats' => 3, 'projects' => 5],
                ],
                'professional' => [
                    'name' => 'Professional',
                    'prices' => [
                        ['currency' => 'USD', 'interval' => 'month', 'amount' => '49.00'],
                        ['currency' => 'USD', 'interval' => 'year', 'amount' => '490.00'],
                        ['currency' => 'EUR', 'interval' => 'month', 'amount' => '45.00'],
                    ],
                    'features' => ['reports', 'api'],
                    'limits' => ['seats' => 10, 'projects' => null],
                ],
            ],
            'addon' => [
                'extra_seat' => [
                    'name' => 'Extra seat',
                    'prices' => [['currency' => 'USD', 'interval' => 'month', 'amount' => '5.00']],
                    'limits' => ['seats' => 1],
                    'max_quantity' => 5,
                ],
            ],
        ];
    }

    /**
     * Configure the catalogue and sync it to the given fake.
     */
    protected function syncCatalogue(): void
    {
        config(['ridley.catalogue' => $this->catalogueConfig()]);

        Ridley::catalogue()->push();
    }

    /**
     * A customer with a default card.
     */
    protected function customerWithCard(array $attributes = []): User
    {
        $user = $this->user($attributes);
        $user->createAsRidleyCustomer();
        app(FakeRidley::class)->addPaymentMethod((string) $user->ridleyId());

        return $user->updateDefaultPaymentMethodFromRidley();
    }

    protected function user(array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ], $attributes));
    }
}
