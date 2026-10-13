<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Ridley\Laravel\Console\InstallCommand;
use Ridley\Laravel\Ridley;

/**
 * Snapshot and restore the files ridley:install touches in the Testbench skeleton.
 */
function skeletonFiles(): array
{
    return [base_path('.env'), base_path('.env.example'), config_path('ridley.php')];
}

function cleanPublishedMigrations(): void
{
    foreach (File::glob(database_path('migrations/*ridley*.php')) as $file) {
        File::delete($file);
    }
}

describe('ridley:install', function () {
    beforeEach(function () {
        $this->snapshot = collect(skeletonFiles())->mapWithKeys(fn (string $path) => [$path => File::exists($path) ? File::get($path) : null])->all();
        File::put(base_path('.env'), "APP_NAME=Testing\nRIDLEY_CURRENCY=EUR\n");
    });

    afterEach(function () {
        foreach ($this->snapshot as $path => $contents) {
            $contents === null ? File::delete($path) : File::put($path, $contents);
        }

        cleanPublishedMigrations();
    });

    it('publishes, configures the model and adds env keys without prompting', function () {
        $this->artisan('ridley:install', ['--model' => 'App\\Models\\Account', '--no-interaction' => true])
            ->expectsOutputToContain('Next steps')
            ->assertSuccessful();

        $config = File::get(config_path('ridley.php'));
        $env = File::get(base_path('.env'));

        expect($config)->toContain("env('RIDLEY_MODEL', 'App\\\\Models\\\\Account')")
            ->and($env)->toContain('RIDLEY_SECRET=')
            ->toContain('RIDLEY_WEBHOOK_SECRET=')
            ->and(substr_count($env, 'RIDLEY_CURRENCY='))->toBe(1)
            ->and(File::get(base_path('.env.example')))->toContain('RIDLEY_SECRET=')
            ->and(File::glob(database_path('migrations/*create_ridley_subscriptions_table.php')))->toHaveCount(1)
            ->and(File::glob(database_path('migrations/*create_ridley_customers_table.php')))->toHaveCount(1);

        // Re-running is idempotent.
        $this->artisan('ridley:install', ['--model' => 'App\\Models\\Account', '--no-interaction' => true])->assertSuccessful();

        expect(substr_count(File::get(base_path('.env')), 'RIDLEY_SECRET='))->toBe(1)
            ->and(array_keys(InstallCommand::ENV_KEYS))->toContain('RIDLEY_SECRET');
    });

    it('leaves the env files alone with --no-env', function () {
        $this->artisan('ridley:install', ['--no-env' => true, '--no-interaction' => true])->assertSuccessful();

        expect(File::get(base_path('.env')))->not->toContain('RIDLEY_SECRET');
    });
});

describe('ridley:doctor', function () {
    it('passes when everything is configured', function () {
        Ridley::fake();
        $this->syncCatalogue();

        $this->artisan('ridley:doctor')
            ->expectsOutputToContain('Connected')
            ->expectsOutputToContain('3 item(s), 6 current price(s)')
            ->assertSuccessful();
    });

    it('fails on a missing webhook secret and catalogue drift', function () {
        Ridley::fake();
        $this->syncCatalogue();
        config(['ridley.webhook.secret' => null]);

        $catalogue = $this->catalogueConfig();
        $catalogue['plan']['enterprise'] = ['name' => 'Enterprise'];
        config(['ridley.catalogue' => $catalogue]);

        $this->artisan('ridley:doctor')
            ->expectsOutputToContain('RIDLEY_WEBHOOK_SECRET is not set')
            ->expectsOutputToContain('1 item(s) without a current price')
            ->assertFailed();
    });

    it('fails when the API is unreachable', function () {
        Ridley::fake(['GET me' => fn () => ['errors' => [['status' => '401', 'code' => 'unauthenticated', 'detail' => 'Invalid API key.']]]]);
        config(['ridley.http.retries' => 0]);

        $this->artisan('ridley:doctor')->expectsOutputToContain('could not be reached')->assertFailed();
    });

    it('warns about a live key outside production and fails a test key in production', function () {
        Ridley::fake();
        config(['ridley.key' => 'sk_live_123']);

        $this->artisan('ridley:doctor')->expectsOutputToContain('A live key is used outside production')->assertSuccessful();

        config(['ridley.key' => 'sk_test_123']);
        $this->app['env'] = 'production';

        $this->artisan('ridley:doctor')->expectsOutputToContain('A test key is used in production')->assertFailed();
    });
});

describe('ridley:webhook', function () {
    it('creates an endpoint and prints the secret, then updates it', function () {
        $fake = Ridley::fake();

        $this->artisan('ridley:webhook', ['--url' => 'https://app.example.com/ridley/webhook', '--events' => ['invoice.paid']])
            ->expectsOutputToContain('created')
            ->expectsOutputToContain('RIDLEY_WEBHOOK_SECRET=whsec_')
            ->assertSuccessful();

        $this->artisan('ridley:webhook', ['--url' => 'https://app.example.com/ridley/webhook'])
            ->expectsOutputToContain('updated')
            ->expectsOutputToContain('only reveals the signing secret on creation')
            ->assertSuccessful();

        expect($fake->all('webhook-endpoints'))->toHaveCount(1);
    });
});
