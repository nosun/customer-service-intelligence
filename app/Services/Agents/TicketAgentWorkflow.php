<?php

namespace App\Services\Agents;

use App\Models\Conversation;
use App\Models\ConversationDraft;
use App\Models\WorkItem;
use App\Services\TicketWork\TicketOrderWorkbench;
use App\Services\TicketWork\TicketRuntimeCaseManager;
use Encore\Admin\Auth\Database\Administrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TicketAgentWorkflow
{
    public const KEY = 'work/ticket-agent-sdk';

    public const VERSION = '1.2.0';

    public function create(Conversation $ticket, int $actor, string $provider): WorkItem
    {
        if (! Administrator::whereKey($actor)->exists() || ! in_array($provider, ['openai', 'omniroute', 'minimax'], true)) {
            throw new RuntimeException('A valid administrator and supported provider are required.');
        }

        return DB::transaction(function () use ($ticket, $actor, $provider): WorkItem {
            $ticket = Conversation::lockForUpdate()->findOrFail($ticket->id);
            $tools = new TicketAgentTools($ticket);
            $existing = WorkItem::where('workflow_key', self::KEY)->where('input_snapshot->conversation_id', $ticket->id)
                ->where('input_hash', $tools->hash())->whereIn('status', ['queued', 'running'])->latest('id')->first();
            if ($existing !== null) {
                return $existing;
            }
            $trigger = $tools->trigger();
            $case = app(TicketRuntimeCaseManager::class)->forConversation($ticket, $trigger->id);

            return WorkItem::create([
                'work_item_key' => (string) Str::uuid(), 'customer_case_id' => $case->id,
                'work_type' => 'ticket_agent', 'status' => 'queued', 'workflow_key' => self::KEY, 'workflow_version' => self::VERSION,
                'input_hash' => $tools->hash(), 'input_snapshot' => ['conversation_id' => $ticket->id, 'requested_by' => $actor, 'provider' => $provider],
                'max_attempts' => 1, 'deadline_at' => now()->addSeconds((int) config('ticket-agent.timeout') + 120),
            ]);
        });
    }

    public function run(WorkItem $item): WorkItem
    {
        if ($item->workflow_key !== self::KEY || ! WorkItem::whereKey($item->id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now(), 'attempt_count' => 1])) {
            throw new RuntimeException('Ticket Agent task is not available for execution.');
        }
        try {
            $ticket = Conversation::findOrFail(data_get($item->input_snapshot, 'conversation_id'));
            $tools = new TicketAgentTools($ticket);
            if (! hash_equals($item->input_hash, $tools->hash()) || $item->deadline_at?->isPast()) {
                $item->update(['status' => 'stale', 'error_message' => 'Ticket changed or the queued task expired.', 'finished_at' => now()]);

                return $item->fresh();
            }
            $report = app(TicketAgentWorker::class)->run($tools->initial(), data_get($item->input_snapshot, 'provider'), function (string $name, array $arguments) use ($tools, $item): array {
                $result = $tools->call($name, $arguments);
                $item->update(['result' => [...($item->fresh()->result ?? []), 'runtime' => ['plan' => $tools->plan, 'facts' => $tools->facts,
                    'return_assessment' => $tools->returnAssessment, 'last_tool' => $name, 'tool_calls_completed' => count($tools->ledger), 'evidence' => $tools->ledger]]]);

                return $result;
            }, function (array $checkpoint) use ($item): void {
                $result = $item->fresh()->result ?? [];
                $result['checkpoints'][] = $checkpoint;
                $item->update(['result' => $result]);
            });
            $tools->assertCurrent();
            $output = app(TicketAgentOutput::class)->validate($report, $tools);

            return DB::transaction(function () use ($ticket, $item, $tools, $report, $output): WorkItem {
                Conversation::lockForUpdate()->findOrFail($ticket->id);
                $locked = WorkItem::lockForUpdate()->findOrFail($item->id);
                if ($locked->status !== 'running' || $locked->deadline_at?->isPast()) {
                    throw new RuntimeException('Ticket Agent task is no longer active.');
                }
                $tools->assertCurrent();
                $candidate = $report['candidate'];
                $result = [
                    ...$output, 'schema_version' => '1.0.0', 'plan' => $tools->plan,
                    'facts' => $tools->facts, 'missing_facts' => $candidate['missing_facts'],
                    'knowledge_articles' => array_values($tools->policies), 'requires_human_review' => true,
                    'review' => [...$output['review'], 'reviewed_answer' => $candidate['answer_customer_language'], 'final_answer' => $candidate['answer_customer_language']],
                    'answer_en' => $candidate['answer_en'], 'answer_zh' => $candidate['answer_zh'],
                    'agent_report' => $report, 'evidence' => $tools->ledger,
                ];
                if (in_array($output['draft_status'], ['draft', 'blocked'], true)) {
                    $snapshot = $ticket->latestOrderContext()->first();
                    $draft = ConversationDraft::create([
                        'conversation_id' => $ticket->id, 'work_item_id' => $item->id,
                        'source' => 'openai_agents', 'status' => $output['draft_status'],
                        'body' => $candidate['answer_customer_language'], 'is_private' => false,
                        'created_by' => $item->input_snapshot['requested_by'], 'updated_by' => $item->input_snapshot['requested_by'],
                        'requires_human_review' => true,
                        'metadata' => [
                            'workflow_key' => self::KEY, 'workflow_version' => self::VERSION,
                            'workbench_input_hash' => app(TicketOrderWorkbench::class)->inputHash($ticket->fresh()),
                            'input_hash' => $tools->hash(), 'requires_order' => $tools->facts !== [],
                            'order_context_id' => $snapshot?->id, 'order_context_hash' => $snapshot?->response_hash,
                            'answer_en' => $candidate['answer_en'], 'answer_zh' => $candidate['answer_zh'],
                            'reply_plan' => $output['reply_plan'], 'review' => $result['review'],
                            'return_assessment' => $output['return_assessment'],
                        ],
                    ]);
                    $result['draft_id'] = $draft->id;
                }
                $directory = storage_path('app/private/ticket-agent-runs');
                File::ensureDirectoryExists($directory, 0700);
                $path = $directory.'/'.$item->work_item_key.'.json';
                $handle = fopen($path, 'x');
                if ($handle === false) {
                    throw new RuntimeException('Unable to save the private Agent audit.');
                }
                try {
                    chmod($path, 0600);
                    if (fwrite($handle, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
                        throw new RuntimeException('Unable to write the Agent audit.');
                    }
                } finally {
                    fclose($handle);
                }
                $locked->update(['status' => 'completed', 'input_hash' => $tools->hash(), 'result' => $result, 'finished_at' => now()]);

                return $locked;
            });
        } catch (Throwable $exception) {
            $message = get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'Ticket Agent failed validation or execution ('.class_basename($exception).').';
            $result = $item->fresh()->result ?? [];
            if (isset($report)) {
                $result['failed_report'] = $report;
                $result['evidence'] = $tools->ledger;
            }
            if ($exception instanceof ValidationException) {
                $result['validation_errors'] = $exception->errors();
            }
            $item->update(['status' => 'failed', 'result' => $result, 'error_message' => mb_substr($message, 0, 1000), 'finished_at' => now()]);
            throw $exception;
        }
    }

    public function current(Conversation $ticket, WorkItem $item): bool
    {
        try {
            $tools = new TicketAgentTools($ticket);
            $tools->facts = $item->result['facts'] ?? [];
            $tools->assertCurrent();

            return hash_equals((string) $item->input_hash, $tools->hash());
        } catch (Throwable) {
            return false;
        }
    }
}
