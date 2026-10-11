<?php

declare(strict_types=1);

namespace Ridley\Laravel\Console;

use Illuminate\Console\Command;
use Ridley\Laravel\Catalogue\SyncResult;
use Ridley\Laravel\Ridley;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'ridley:sync-catalogue')]
class SyncCatalogueCommand extends Command
{
    protected $signature = 'ridley:sync-catalogue {--dry-run : Show what would change without calling Ridley write endpoints}';

    protected $description = 'Create or replace the catalogue prices on Ridley and mirror them locally';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $results = Ridley::catalogue()->sync($dryRun);

        if ($results === []) {
            $this->components->warn('No catalogue definitions found (config `ridley.catalogue` or Ridley::defineCatalogueUsing()).');

            return self::SUCCESS;
        }

        $this->table(
            ['Lookup key', 'Amount', 'Action', 'Ridley price'],
            array_map(fn (SyncResult $result) => [$result->lookupKey, $result->amount, $result->action, $result->ridleyPriceId ?? '—'], $results),
        );

        $changed = count(array_filter($results, fn (SyncResult $result) => $result->changed()));

        $this->components->info($dryRun
            ? "{$changed} price(s) would change. Run without --dry-run to apply."
            : "Catalogue synced: {$changed} price(s) created or replaced.");

        return self::SUCCESS;
    }
}
