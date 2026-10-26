<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Services\N8n\N8nTicketDraftWorkflowClient;
use App\Services\TicketWork\TicketAssistanceDispatcher;
use App\Services\TicketWork\TicketRuntimeContext;
use Encore\Admin\Auth\Database\Administrator;
use Illuminate\Console\Command;
use Throwable;

class DispatchTicketAssistance extends Command
{
    protected $signature = 'tickets:assist {--limit= : Maximum new tasks} {--ticket= : Explicit Ticket ID}';

    protected $description = 'Queue v2 analysis, handling advice and local email drafts for unresolved Tickets';

    public function handle(TicketAssistanceDispatcher $dispatcher, N8nTicketDraftWorkflowClient $client, TicketRuntimeContext $context): int
    {
        if (! config('ticket-assistance.enabled')) {
            $this->info('Ticket assistance is disabled.');

            return self::SUCCESS;
        }
        $actor = (int) config('ticket-assistance.actor_id');
        if (! Administrator::whereKey($actor)->exists() || ! $client->isConfigured()) {
            $this->error('Configure a valid assistance administrator and n8n connection first.');

            return self::FAILURE;
        }
        $limit = max(1, min(100, (int) ($this->option('limit') ?: config('ticket-assistance.batch_size'))));
        $created = 0;
        $failed = 0;
        $query = Conversation::where('silo', 'tickets')->whereIn('status', ['open', 'pending', 'onhold'])
            ->when($this->option('ticket'), fn ($query) => $query->whereKey($this->option('ticket')))
            ->orderBy('last_message_at')->orderBy('id');
        foreach ($query->lazy(100) as $ticket) {
            try {
                $context->triggerEvent($ticket);
            } catch (\RuntimeException) {
                continue; // No customer message or no new guidance after our last reply.
            }
            try {
                $item = $dispatcher->dispatch($ticket, $actor);
                $created += (int) $item->wasRecentlyCreated;
                if ($created >= $limit) {
                    break;
                }
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }
        $this->info("queued={$created} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
