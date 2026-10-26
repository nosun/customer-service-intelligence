<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Services\Agents\TicketAgentWorkflow;
use Illuminate\Console\Command;
use Throwable;

class AssistTicketWithAgent extends Command
{
    protected $signature = 'tickets:agent-assist {conversation : Local Conversation ID} {--actor= : Local administrator ID} {--provider= : openai, omniroute or minimax}';

    protected $description = 'Investigate one Ticket with the Agents SDK, independently review and save local bilingual drafts';

    public function handle(TicketAgentWorkflow $workflow): int
    {
        try {
            $item = $workflow->create(Conversation::findOrFail($this->argument('conversation')),
                (int) ($this->option('actor') ?: config('ticket-assistance.actor_id')),
                (string) ($this->option('provider') ?: config('services.draft_ai.provider')));
            $this->line('work_item='.$item->id.' status='.$item->status);
            if (! $item->wasRecentlyCreated) {
                $this->warn('The same Ticket input is already queued or running.');

                return self::SUCCESS;
            }
            $item = $workflow->run($item);
            $this->line('status='.$item->status.' draft_status='.($item->result['draft_status'] ?? '').' draft_id='.($item->result['draft_id'] ?? 'none'));

            return $item->status === 'completed' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Ticket Agent did not complete. Inspect its local task state and provider/runtime configuration.');

            return self::FAILURE;
        }
    }
}
