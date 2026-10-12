<?php

declare(strict_types=1);

namespace Ridley\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ridley\Laravel\Billable;
use Ridley\Laravel\Ridley;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'ridley:doctor')]
class DoctorCommand extends Command
{
    protected $signature = 'ridley:doctor';

    protected $description = 'Check the Ridley configuration, database and catalogue';

    /**
     * Columns the Billable trait expects on the billable table.
     *
     * @var list<string>
     */
    public const array BILLABLE_COLUMNS = ['ridley_customer_id', 'pm_brand', 'pm_last_four', 'pm_exp_month', 'pm_exp_year', 'ridley_currency', 'trial_ends_at'];

    protected int $failures = 0;

    public function handle(): int
    {
        $this->failures = 0;

        $this->checkApi();
        $this->checkWebhooks();
        $this->checkBillable();
        $this->checkTables();
        $this->checkCatalogue();

        $this->newLine();

        if ($this->failures > 0) {
            $this->components->error("{$this->failures} check(s) failed.");

            return self::FAILURE;
        }

        $this->components->info('Ridley is ready.');

        return self::SUCCESS;
    }

    protected function checkApi(): void
    {
        if (! config('ridley.key')) {
            $this->failCheck('API key', 'RIDLEY_SECRET is not set.');

            return;
        }

        try {
            $account = Ridley::client()->me();
        } catch (Throwable $exception) {
            $this->failCheck('API key', 'Ridley could not be reached with this key: '.$exception->getMessage());

            return;
        }

        $this->passCheck('API key', 'Connected'.($account->name ? " to {$account->name}" : '').'.');

        $mode = $account->mode ?? (str_starts_with((string) config('ridley.key'), 'sk_live') ? 'live' : 'test');
        $production = app()->environment('production');

        if ($production && $mode !== 'live') {
            $this->failCheck('Key mode', "A {$mode} key is used in production.");
        } elseif (! $production && $mode === 'live') {
            $this->warnCheck('Key mode', 'A live key is used outside production.');
        } else {
            $this->passCheck('Key mode', ucfirst((string) $mode).' key in the '.app()->environment().' environment.');
        }
    }

    protected function checkWebhooks(): void
    {
        config('ridley.webhook.secret')
            ? $this->passCheck('Webhook secret', 'Set.')
            : $this->failCheck('Webhook secret', 'RIDLEY_WEBHOOK_SECRET is not set; run `php artisan ridley:webhook`.');

        Route::has('ridley.webhook')
            ? $this->passCheck('Webhook route', route('ridley.webhook'))
            : $this->failCheck('Webhook route', 'The ridley.webhook route is not registered (Ridley::ignoreRoutes()?).');
    }

    protected function checkBillable(): void
    {
        $model = Ridley::customerModel();

        if (! class_exists($model)) {
            $this->failCheck('Billable model', "{$model} does not exist; set ridley.model.");

            return;
        }

        if (! in_array(Billable::class, class_uses_recursive($model), true)) {
            $this->failCheck('Billable model', "{$model} does not use the Ridley\\Laravel\\Billable trait.");
        } else {
            $this->passCheck('Billable model', $model);
        }

        /** @var Model $instance */
        $instance = new $model;

        try {
            $required = config('ridley.trial.generic', true) ? self::BILLABLE_COLUMNS : array_diff(self::BILLABLE_COLUMNS, ['trial_ends_at']);
            $missing = array_values(array_diff($required, Schema::connection($instance->getConnectionName())->getColumnListing($instance->getTable())));
        } catch (Throwable $exception) {
            $this->failCheck('Billable columns', $exception->getMessage());

            return;
        }

        $missing === []
            ? $this->passCheck('Billable columns', "{$instance->getTable()} has the Ridley columns.")
            : $this->failCheck('Billable columns', "{$instance->getTable()} is missing: ".implode(', ', $missing).'. Run the migrations.');
    }

    protected function checkTables(): void
    {
        $missing = [];

        foreach (['subscriptions', 'subscription_items', 'webhook_events', 'prices'] as $key) {
            $table = (string) config("ridley.tables.{$key}");

            try {
                if (! Schema::hasTable($table)) {
                    $missing[] = $table;
                }
            } catch (Throwable) {
                $missing[] = $table;
            }
        }

        $missing === []
            ? $this->passCheck('Package tables', 'Present.')
            : $this->failCheck('Package tables', 'Missing: '.implode(', ', $missing).'. Run the migrations.');
    }

    protected function checkCatalogue(): void
    {
        try {
            $items = Ridley::catalogue()->items();
            $drift = Ridley::catalogue()->drift();
            $prices = Ridley::catalogue()->prices()->count();
        } catch (Throwable $exception) {
            $this->failCheck('Catalogue', $exception->getMessage());

            return;
        }

        if ($items->isEmpty() && $prices === 0) {
            $this->warnCheck('Catalogue', 'No items defined and no prices synced; run `php artisan ridley:sync-catalogue`.');

            return;
        }

        if ($drift === []) {
            $this->passCheck('Catalogue', "{$items->count()} item(s), {$prices} current price(s).");

            return;
        }

        $this->failCheck('Catalogue', count($drift).' item(s) without a current price; add them on Ridley, then run `php artisan ridley:sync-catalogue`.');

        foreach ($drift as $entry) {
            $this->line("      <fg=gray>{$entry['item']}: {$entry['reason']}</>");
        }
    }

    protected function passCheck(string $check, string $detail): void
    {
        $this->line("  <fg=green>✓</> {$check} <fg=gray>— {$detail}</>");
    }

    protected function warnCheck(string $check, string $detail): void
    {
        $this->line("  <fg=yellow>!</> {$check} <fg=gray>— {$detail}</>");
    }

    protected function failCheck(string $check, string $detail): void
    {
        $this->failures++;

        $this->line("  <fg=red>✗</> {$check} <fg=gray>— {$detail}</>");
    }
}
