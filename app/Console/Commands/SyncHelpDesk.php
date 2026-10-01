<?php

namespace App\Console\Commands;

use App\Services\HelpDesk\HelpDeskClient;
use App\Services\HelpDesk\HelpDeskImportService;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

class SyncHelpDesk extends Command
{
    protected $signature = 'helpdesk:sync
        {--site-key= : Fallback site for tickets without a recognizable team or brand}
        {--silo=tickets : tickets, archive, spam or trash}
        {--status=open : open, pending, onhold, solved or closed}
        {--limit=100 : Maximum number of tickets to import}
        {--page-size=100 : Tickets requested per API page, maximum 100}
        {--fixture= : Import a local HelpDesk JSON sample instead of calling the API}';

    protected $description = 'Synchronize HelpDesk tickets into the unified channel tables';

    public function handle(HelpDeskClient $client, HelpDeskImportService $importer): int
    {
        $silo = (string) $this->option('silo');
        $status = (string) $this->option('status');
        $limit = (int) $this->option('limit');
        $pageSize = (int) $this->option('page-size');
        if (! in_array($silo, ['tickets', 'archive', 'spam', 'trash'], true)
            || ! in_array($status, ['open', 'pending', 'onhold', 'solved', 'closed'], true)
            || $limit < 1 || $pageSize < 1 || $pageSize > 100) {
            $this->error('Invalid silo, status, limit or page-size.');

            return self::INVALID;
        }

        try {
            $fixtureTickets = $this->fixtureTickets($limit);
            $pages = $fixtureTickets === null
                ? $client->ticketPages($silo, $status, $limit, $pageSize)
                : [['tickets' => $fixtureTickets]];
            $result = ['channel_account_id' => 0, 'tickets' => 0, 'events' => 0, 'attachments' => 0];
            foreach ($pages as $page) {
                $pageResult = $importer->import(
                    tickets: $page['tickets'],
                    fallbackAccountId: (string) config('services.helpdesk.account_id'),
                    siteKey: (string) $this->option('site-key'),
                    filters: ['silo' => $silo, 'status' => $status],
                );
                $result['channel_account_id'] = $pageResult['channel_account_id'];
                foreach (['tickets', 'events', 'attachments'] as $key) {
                    $result[$key] += $pageResult[$key];
                }
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('HelpDesk synchronization completed.');
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>>|null */
    private function fixtureTickets(int $limit): ?array
    {
        $fixture = $this->option('fixture');
        if (! is_string($fixture) || $fixture === '') {
            return null;
        }

        $path = str_starts_with($fixture, '/') ? $fixture : base_path($fixture);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read HelpDesk fixture: {$path}");
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid HelpDesk fixture: {$path}", previous: $exception);
        }
        $tickets = isset($payload['tickets']) ? $payload['tickets'] : $payload;
        if (! is_array($tickets) || ! array_is_list($tickets)) {
            throw new RuntimeException('HelpDesk fixture must contain a ticket array.');
        }

        return array_slice($tickets, 0, $limit);
    }
}
