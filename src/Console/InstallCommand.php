<?php

declare(strict_types=1);

namespace Ridley\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'ridley:install')]
class InstallCommand extends Command
{
    protected $signature = 'ridley:install
        {--model= : The billable model class (default App\\Models\\User)}
        {--table= : The billable table (default: the model\'s table)}
        {--force : Overwrite an existing config/ridley.php}
        {--no-env : Do not touch .env / .env.example}';

    protected $description = 'Publish the Ridley config and migrations and prepare the environment';

    /**
     * Keys appended to .env / .env.example when missing.
     *
     * @var array<string, string>
     */
    public const array ENV_KEYS = [
        'RIDLEY_SECRET' => '',
        'RIDLEY_WEBHOOK_SECRET' => '',
        'RIDLEY_CURRENCY' => 'USD',
        'RIDLEY_BILLING_URL' => '',
    ];

    public function handle(Filesystem $files): int
    {
        $model = $this->option('model');

        if (! is_string($model) || $model === '') {
            $model = $this->input->isInteractive()
                ? (string) $this->ask('Which model is billable?', 'App\\Models\\User')
                : 'App\\Models\\User';
        }

        $model = ltrim($model, '\\');
        $table = $this->option('table') ?: $this->guessTable($model);

        $this->callSilently('vendor:publish', ['--tag' => 'ridley-config', '--force' => (bool) $this->option('force')]);
        $this->callSilently('vendor:publish', ['--tag' => 'ridley-migrations']);
        $this->components->task('Published config/ridley.php and the migrations');

        $this->writeConfig($files, $model, (string) $table);
        $this->components->task("Billable model set to {$model} (table {$table})");

        if (! $this->option('no-env')) {
            foreach (['.env', '.env.example'] as $file) {
                $added = $this->addEnvironmentKeys($files, $this->laravel->basePath($file));

                if ($added !== null) {
                    $this->components->task("{$file}: ".($added === [] ? 'already configured' : 'added '.implode(', ', $added)));
                }
            }
        }

        $this->newLine();
        $this->components->info('Next steps');
        $this->components->bulletList([
            "Add the <comment>Ridley\\Laravel\\Billable</comment> trait to {$model}.",
            'Set <comment>RIDLEY_SECRET</comment> in .env, then run <comment>php artisan migrate</comment>.',
            'Define your catalogue in config/ridley.php and run <comment>php artisan ridley:sync-catalogue</comment>.',
            'Create the webhook endpoint with <comment>php artisan ridley:webhook</comment> and set <comment>RIDLEY_WEBHOOK_SECRET</comment>.',
            'Run <comment>php artisan ridley:doctor</comment> to check everything.',
        ]);

        return self::SUCCESS;
    }

    protected function guessTable(string $model): string
    {
        if (class_exists($model) && is_subclass_of($model, Model::class)) {
            return (new $model)->getTable();
        }

        return Str::snake(Str::pluralStudly(class_basename($model)));
    }

    protected function writeConfig(Filesystem $files, string $model, string $table): void
    {
        $path = $this->laravel->configPath('ridley.php');

        if (! $files->exists($path)) {
            return;
        }

        $contents = $files->get($path);

        $contents = (string) preg_replace_callback(
            "/'model' => env\\('RIDLEY_MODEL', '[^']*'\\)/",
            fn () => "'model' => env('RIDLEY_MODEL', ".var_export($model, true).')',
            $contents,
        );

        $contents = (string) preg_replace_callback(
            "/'billable' => env\\('RIDLEY_BILLABLE_TABLE', '[^']*'\\)/",
            fn () => "'billable' => env('RIDLEY_BILLABLE_TABLE', ".var_export($table, true).')',
            $contents,
        );

        $files->put($path, $contents);

        config(['ridley.model' => $model, 'ridley.tables.billable' => $table]);
    }

    /**
     * @return list<string>|null The keys added, or null when the file does not exist.
     */
    protected function addEnvironmentKeys(Filesystem $files, string $path): ?array
    {
        if (! $files->exists($path)) {
            return null;
        }

        $contents = $files->get($path);
        $added = [];

        foreach (self::ENV_KEYS as $key => $default) {
            if (! preg_match('/^'.preg_quote($key, '/').'=/m', $contents)) {
                $added[] = $key;
            }
        }

        if ($added !== []) {
            $block = implode(PHP_EOL, array_map(fn (string $key) => $key.'='.self::ENV_KEYS[$key], $added));
            $files->put($path, rtrim($contents).PHP_EOL.PHP_EOL.$block.PHP_EOL);
        }

        return $added;
    }
}
