<?php

namespace App\Console\Commands;

use App\Services\HelpDesk\HelpDeskClient;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class FetchHelpDeskSample extends Command
{
    protected $signature = 'helpdesk:sample
        {--silo=tickets : tickets, archive, spam or trash}
        {--status=open : open, pending, onhold, solved or closed}
        {--limit=20 : Maximum number of tickets to save}
        {--page-size=100 : Tickets requested per API page, maximum 100}
        {--output= : Local JSON output path}';

    protected $description = 'Fetch a read-only HelpDesk ticket sample into a local JSON file';

    public function handle(HelpDeskClient $client): int
    {
        $silo = (string) $this->option('silo');
        $status = (string) $this->option('status');
        $limit = (int) $this->option('limit');
        $pageSize = (int) $this->option('page-size');

        if (! in_array($silo, ['tickets', 'archive', 'spam', 'trash'], true)) {
            $this->error('--silo must be tickets, archive, spam or trash.');

            return self::INVALID;
        }
        if (! in_array($status, ['open', 'pending', 'onhold', 'solved', 'closed'], true)) {
            $this->error('--status must be open, pending, onhold, solved or closed.');

            return self::INVALID;
        }
        if ($limit < 1 || $pageSize < 1 || $pageSize > 100) {
            $this->error('--limit must be at least 1 and --page-size must be between 1 and 100.');

            return self::INVALID;
        }

        try {
            $result = $client->listTickets($silo, $status, $limit, $pageSize);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $output = $this->outputPath();
        $directory = dirname($output);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create output directory: {$directory}");
        }

        $payload = [
            'fetched_at' => now()->toIso8601String(),
            'filters' => ['silo' => $silo, 'status' => $status],
            'result_count' => count($result['tickets']),
            'reported_total_results' => $result['total_results'],
            'api_requests' => $result['requests'],
            'tickets' => $result['tickets'],
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($output, $json."\n", LOCK_EX) === false) {
            throw new RuntimeException("Unable to write HelpDesk sample: {$output}");
        }
        chmod($output, 0600);

        $this->info('HelpDesk sample saved.');
        $this->line(json_encode([
            'output' => $output,
            'tickets' => count($result['tickets']),
            'reported_total_results' => $result['total_results'],
            'api_requests' => $result['requests'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function outputPath(): string
    {
        $output = $this->option('output');
        if (! is_string($output) || $output === '') {
            return storage_path('app/private/helpdesk/ticket-sample.json');
        }

        return str_starts_with($output, '/') ? $output : base_path($output);
    }
}
