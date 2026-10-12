<?php

declare(strict_types=1);

namespace Ridley\Laravel;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ridley\Laravel\Catalogue\Catalogue;
use Ridley\Laravel\Client\RidleyClient;
use Ridley\Laravel\Console\DoctorCommand;
use Ridley\Laravel\Console\InstallCommand;
use Ridley\Laravel\Console\PushCatalogueCommand;
use Ridley\Laravel\Console\SyncCatalogueCommand;
use Ridley\Laravel\Console\WebhookCommand;
use Ridley\Laravel\Http\Middleware\EnsureFeature;
use Ridley\Laravel\Http\Middleware\EnsureSubscribed;

class RidleyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ridley.php', 'ridley');

        // `bind`, not `singleton`: a fresh client per resolve keeps Octane workers free of stale config.
        $this->app->bind(RidleyClient::class, fn () => RidleyClient::fromConfig((array) config('ridley')));
        $this->app->bind(Catalogue::class, fn () => new Catalogue);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ridley');

        $this->registerRoutes();
        $this->registerMiddleware();
        $this->registerMigrations();
        $this->registerPublishing();

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                DoctorCommand::class,
                PushCatalogueCommand::class,
                SyncCatalogueCommand::class,
                WebhookCommand::class,
            ]);
        }
    }

    protected function registerRoutes(): void
    {
        if (! Ridley::$registersRoutes || ($this->app->routesAreCached())) {
            return;
        }

        Route::group([
            'prefix' => trim((string) config('ridley.path', 'ridley'), '/'),
            'as' => 'ridley.',
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php'));

        if (config('ridley.routes.enabled')) {
            Route::group([
                'prefix' => trim((string) config('ridley.routes.prefix', 'billing'), '/'),
                'middleware' => (array) config('ridley.routes.middleware', ['web', 'auth']),
                'as' => 'ridley.',
            ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/billing.php'));
        }
    }

    protected function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');

        $router->aliasMiddleware('ridley.subscribed', EnsureSubscribed::class);
        $router->aliasMiddleware('ridley.feature', EnsureFeature::class);
    }

    protected function registerMigrations(): void
    {
        if (Ridley::$runsMigrations && $this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/ridley.php' => $this->app->configPath('ridley.php'),
        ], 'ridley-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'ridley-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/ridley'),
        ], 'ridley-views');
    }
}
