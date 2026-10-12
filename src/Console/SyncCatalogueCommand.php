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
    protected $signature = 'ridley:sync-catalogue';

    protected $description = "Pull the app's prices from Ridley into the local catalogue";

    public function handle(): int
    {
        $results = Ridley::catalogue()->sync();

        if ($results === []) {
            $this->components->warn('Ridley has no active recurring prices with this app\'s lookup keys. Add prices on Ridley, or bootstrap them with `php artisan ridley:push-catalogue`.');

            return self::SUCCESS;
        }

        $this->table(
            ['Lookup key', 'Amount', 'Action', 'Ridley price', 'Note'],
            array_map(fn (SyncResult $result) => [$result->lookupKey, $result->amount, $result->action, $result->ridleyPriceId ?? '—', $result->note ?? ''], $results),
        );

        $changed = count(array_filter($results, fn (SyncResult $result) => $result->changed()));
        $skipped = count(array_filter($results, fn (SyncResult $result) => $result->action === SyncResult::SKIPPED));

        $this->components->info("Catalogue synced: {$changed} price(s) changed.");

        if ($skipped > 0) {
            $this->components->warn("{$skipped} price(s) skipped; see the notes above.");
        }

        foreach (Ridley::catalogue()->drift() as $entry) {
            $this->components->warn("{$entry['item']}: {$entry['reason']}.");
        }

        return self::SUCCESS;
    }
}
