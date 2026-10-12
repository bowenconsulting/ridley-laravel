<?php

declare(strict_types=1);

namespace Ridley\Laravel\Console;

use Illuminate\Console\Command;
use Ridley\Laravel\Catalogue\SyncResult;
use Ridley\Laravel\Ridley;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'ridley:push-catalogue')]
class PushCatalogueCommand extends Command
{
    protected $signature = 'ridley:push-catalogue
        {--dry-run : Show what would change without calling Ridley write endpoints}
        {--replace : Replace Ridley prices that differ from the definitions}';

    protected $description = 'Create the price definitions on Ridley (a bootstrap; manage prices on Ridley afterwards)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $results = Ridley::catalogue()->push($dryRun, (bool) $this->option('replace'));

        if ($results === []) {
            $this->components->warn('No price definitions found (`prices` in config `ridley.catalogue` or Ridley::defineCatalogueUsing()).');

            return self::SUCCESS;
        }

        $this->table(
            ['Lookup key', 'Amount', 'Action', 'Ridley price', 'Note'],
            array_map(fn (SyncResult $result) => [$result->lookupKey, $result->amount, $result->action, $result->ridleyPriceId ?? '—', $result->note ?? ''], $results),
        );

        $changed = count(array_filter($results, fn (SyncResult $result) => $result->changed() || in_array($result->action, [SyncResult::WOULD_CREATE, SyncResult::WOULD_REPLACE], true)));
        $differs = count(array_filter($results, fn (SyncResult $result) => $result->action === SyncResult::DIFFERS));

        $this->components->info($dryRun
            ? "{$changed} price(s) would be created or replaced. Run without --dry-run to apply."
            : "Catalogue pushed: {$changed} price(s) created or replaced, then pulled.");

        if ($differs > 0) {
            $this->components->warn("{$differs} price(s) differ on Ridley and were left alone. Ridley is the source of truth; pass --replace to overwrite.");
        }

        return self::SUCCESS;
    }
}
