<?php

namespace App\Console\Commands;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Services\HelpDesk\HelpDeskClient;
use Illuminate\Console\Command;
use Throwable;

class AuditHelpDeskSync extends Command
{
    protected $signature = 'helpdesk:audit';

    protected $description = 'Compare HelpDesk API totals with locally stored ticket counts';

    public function handle(HelpDeskClient $client): int
    {
        $account = ChannelAccount::where('channel', 'helpdesk')->orderBy('id')->first();
        if ($account === null) {
            $this->error('No HelpDesk channel account exists.');

            return self::FAILURE;
        }

        $rows = [];
        $matches = true;
        try {
            foreach (['tickets', 'archive', 'spam', 'trash'] as $silo) {
                foreach (['open', 'pending', 'onhold', 'solved', 'closed'] as $status) {
                    $api = $client->listTickets($silo, $status, 1, 1)['total_results'];
                    $local = Conversation::where('channel_account_id', $account->id)
                        ->where('silo', $silo)
                        ->where('status', $status)
                        ->count();
                    $match = $api !== null && $api === $local;
                    $matches = $matches && $match;
                    $rows[] = compact('silo', 'status', 'api', 'local', 'match');
                }
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode(['all_match' => $matches, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $matches ? self::SUCCESS : self::FAILURE;
    }
}
