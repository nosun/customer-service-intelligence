<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Services\Cancellations\CancellationCaseVerifier;
use Illuminate\Console\Command;

class VerifyCancellationCases extends Command
{
    protected $signature = 'helpdesk:cancellations:verify
        {--conversation-id= : Local conversation ID, for example 2334}
        {--ticket-id= : HelpDesk external Ticket ID}
        {--order-no= : Explicit order number; only valid when selecting one Ticket}
        {--limit=1 : Maximum Tickets; zero means no limit}
        {--force : Re-query Tickets that already have a successful console-new snapshot}';

    protected $description = 'Verify cancellation Ticket outcomes against the read-only console-new order API';

    public function handle(CancellationCaseVerifier $verifier): int
    {
        if (! $verifier->isConfigured()) {
            $this->error('console-new is not configured. Set CONSOLE_NEW_API_URL and CONSOLE_NEW_API_TOKEN.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        if ($limit < 0) {
            $this->error('The limit must be zero or a positive integer.');

            return self::INVALID;
        }
        $query = Conversation::query()
            ->whereIn('silo', ['tickets', 'archive'])
            ->whereHas('analysis', fn ($analysis) => $analysis->where('primary_problem_type', 'production_cancellation'));
        if ($this->option('conversation-id') !== null) {
            $query->whereKey((int) $this->option('conversation-id'));
        }
        if (trim((string) $this->option('ticket-id')) !== '') {
            $query->where('external_id', trim((string) $this->option('ticket-id')));
        }
        $isSingleSelection = $this->option('conversation-id') !== null || trim((string) $this->option('ticket-id')) !== '';
        if (trim((string) $this->option('order-no')) !== '' && ! $isSingleSelection) {
            $this->error('--order-no requires --conversation-id or --ticket-id.');

            return self::INVALID;
        }
        if (! $this->option('force')) {
            $query->whereDoesntHave('businessContextSnapshots', function ($snapshots): void {
                $snapshots->where('source', 'console_new')
                    ->where('context_type', 'cancellation')
                    ->where('lookup_status', 'found');
            });
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $selected = 0;
        $failed = 0;
        foreach ($query->orderBy('id')->cursor() as $conversation) {
            $selected++;
            $result = $verifier->verify($conversation, $this->option('order-no'));
            if ($result['status'] === 'failed') {
                $failed++;
            }
            $this->line(implode(' | ', array_filter([
                "Conversation {$conversation->id}",
                "Ticket {$conversation->external_short_id}",
                $result['order_number'] === null ? null : "Order {$result['order_number']}",
                $result['status'],
                $result['outcome'],
                $result['message'],
            ])));
        }

        if ($selected === 0) {
            $this->line($this->option('force') ? '没有符合条件的取消 Ticket。' : '没有待核验的取消 Ticket；已成功核验的案例默认跳过。');
        }
        $this->info("处理完成：{$selected} 张，失败 {$failed} 张。");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
