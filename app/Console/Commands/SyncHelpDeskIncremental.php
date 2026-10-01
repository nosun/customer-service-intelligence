<?php

namespace App\Console\Commands;

use App\Models\ChannelAccount;
use App\Models\ChannelSyncCursor;
use App\Models\Conversation;
use App\Services\HelpDesk\HelpDeskClient;
use App\Services\HelpDesk\HelpDeskImportService;
use Illuminate\Console\Command;
use Throwable;

class SyncHelpDeskIncremental extends Command
{
    private const SILOS = ['tickets', 'archive', 'spam', 'trash'];

    private const STATUSES = ['open', 'pending', 'onhold', 'solved', 'closed'];

    protected $signature = 'helpdesk:sync-incremental
        {--silo=* : Limit synchronization to one or more silos}
        {--status=* : Limit synchronization to one or more statuses}
        {--page-size=100 : Tickets requested per API page, maximum 100}
        {--limit-per-filter=100000 : Safety limit for each silo and status combination}';

    protected $description = 'Synchronize HelpDesk tickets updated after the saved cursor';

    public function handle(HelpDeskClient $client, HelpDeskImportService $importer): int
    {
        $account = ChannelAccount::where('channel', 'helpdesk')->orderBy('id')->first();
        if ($account === null) {
            $this->error('Run the initial HelpDesk synchronization before incremental synchronization.');

            return self::FAILURE;
        }

        $silos = $this->filters('silo', self::SILOS);
        $statuses = $this->filters('status', self::STATUSES);
        $pageSize = (int) $this->option('page-size');
        $limit = (int) $this->option('limit-per-filter');
        if ($silos === null || $statuses === null || $pageSize < 1 || $pageSize > 100 || $limit < 1) {
            $this->error('Invalid silo, status, page-size or limit-per-filter.');

            return self::INVALID;
        }

        $totals = ['filters' => 0, 'tickets' => 0, 'events' => 0, 'attachments' => 0];
        try {
            foreach ($silos as $silo) {
                foreach ($statuses as $status) {
                    $cursor = $this->cursor($account, $silo, $status);
                    $initialCursor = $cursor?->exists
                        ? ['value' => $cursor->cursor_value, 'ID' => $cursor->cursor_external_id]
                        : null;

                    foreach ($client->ticketPages(
                        silo: $silo,
                        status: $status,
                        limit: $limit,
                        pageSize: $pageSize,
                        sortBy: 'updatedAt',
                        initialCursor: $initialCursor,
                    ) as $page) {
                        if ($page['tickets'] === []) {
                            continue;
                        }

                        $result = $importer->import(
                            tickets: $page['tickets'],
                            fallbackAccountId: $account->external_account_id,
                            siteKey: null,
                            filters: ['silo' => $silo, 'status' => $status, 'mode' => 'incremental'],
                        );
                        $last = $page['tickets'][array_key_last($page['tickets'])];
                        $cursor = ChannelSyncCursor::updateOrCreate(
                            [
                                'channel_account_id' => $account->id,
                                'source_resource' => 'tickets',
                                'silo' => $silo,
                                'status' => $status,
                            ],
                            [
                                'sort_by' => 'updatedAt',
                                'cursor_value' => $last['updatedAt'],
                                'cursor_external_id' => $last['ID'],
                                'last_synced_at' => now(),
                            ],
                        );
                        foreach (['tickets', 'events', 'attachments'] as $key) {
                            $totals[$key] += $result[$key];
                        }
                    }
                    $totals['filters']++;
                }
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($totals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** @param list<string> $allowed
     * @return list<string>|null
     */
    private function filters(string $name, array $allowed): ?array
    {
        $values = $this->option($name);
        $values = is_array($values) && $values !== [] ? array_values(array_unique($values)) : $allowed;

        return array_diff($values, $allowed) === [] ? $values : null;
    }

    private function cursor(ChannelAccount $account, string $silo, string $status): ?ChannelSyncCursor
    {
        $cursor = ChannelSyncCursor::where([
            'channel_account_id' => $account->id,
            'source_resource' => 'tickets',
            'silo' => $silo,
            'status' => $status,
        ])->first();
        if ($cursor !== null) {
            return $cursor;
        }

        $last = Conversation::where('channel_account_id', $account->id)
            ->where('silo', $silo)
            ->where('status', $status)
            ->whereNotNull('updated_at_external')
            ->orderByDesc('updated_at_external')
            ->orderByDesc('external_id')
            ->first();
        if ($last === null) {
            return null;
        }

        return ChannelSyncCursor::create([
            'channel_account_id' => $account->id,
            'source_resource' => 'tickets',
            'silo' => $silo,
            'status' => $status,
            'sort_by' => 'updatedAt',
            'cursor_value' => $last->updated_at_external->toISOString(),
            'cursor_external_id' => $last->external_id,
            'last_synced_at' => now(),
        ]);
    }
}
