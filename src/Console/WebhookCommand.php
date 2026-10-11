<?php

declare(strict_types=1);

namespace Ridley\Laravel\Console;

use Illuminate\Console\Command;
use Ridley\Laravel\Resources\WebhookEndpoint;
use Ridley\Laravel\Ridley;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'ridley:webhook')]
class WebhookCommand extends Command
{
    protected $signature = 'ridley:webhook
        {--url= : The endpoint URL (defaults to the ridley.webhook route)}
        {--events=* : Events to enable (defaults to config ridley.webhook.events)}
        {--disabled : Create or update the endpoint as inactive}
        {--roll-secret : Roll the signing secret of an existing endpoint}';

    protected $description = 'Create or update the Ridley webhook endpoint for this application';

    public function handle(): int
    {
        $url = (string) ($this->option('url') ?: route('ridley.webhook'));

        /** @var list<string> $events */
        $events = array_values(array_filter((array) $this->option('events'))) ?: array_values((array) config('ridley.webhook.events', []));

        $params = array_filter([
            'url' => $url,
            'enabled_events' => $events === [] ? null : $events,
            'active' => ! $this->option('disabled'),
        ], fn ($value) => $value !== null);

        $endpoints = Ridley::client()->webhookEndpoints();

        /** @var WebhookEndpoint|null $existing */
        $existing = $endpoints->all()->first(fn (WebhookEndpoint $endpoint) => $endpoint->url === $url);

        if ($existing === null) {
            $endpoint = $endpoints->create($params);
            $this->components->info("Webhook endpoint [{$endpoint->id()}] created for {$url}.");
        } else {
            $endpoint = $endpoints->update((string) $existing->id(), $params);
            $this->components->info("Webhook endpoint [{$endpoint->id()}] updated for {$url}.");

            if ($this->option('roll-secret')) {
                $endpoint = $endpoints->rollSecret((string) $existing->id());
            }
        }

        if (is_string($endpoint->secret) && $endpoint->secret !== '') {
            $this->line("  Signing secret: <comment>{$endpoint->secret}</comment>");
            $this->line('  Add it to your environment: RIDLEY_WEBHOOK_SECRET='.$endpoint->secret);
        } else {
            $this->line('  Ridley only reveals the signing secret on creation; use --roll-secret to issue a new one.');
        }

        return self::SUCCESS;
    }
}
