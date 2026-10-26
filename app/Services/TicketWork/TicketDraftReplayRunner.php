<?php

namespace App\Services\TicketWork;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\ConversationDraft;
use App\Models\ConversationEvent;
use App\Models\CustomerCase;
use App\Models\WorkItem;
use App\Services\N8n\N8nTicketDraftWorkflowClient;
use App\Services\N8n\WorkflowRegistry;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TicketDraftReplayRunner
{
    public function __construct(
        private readonly N8nTicketDraftWorkflowClient $n8n,
        private readonly TicketWorkItemManager $workItems,
        private readonly WorkflowRegistry $workflows,
    ) {}

    /** @param list<array<string,mixed>> $cases
     * @param  callable(string):void|null  $progress
     * @param  callable(array<string,mixed>):void|null  $checkpoint
     * @return list<array<string,mixed>>
     */
    public function run(
        array $cases,
        string $workflowKey,
        string $workflowVersion,
        int $batchSize = 5,
        int $timeoutSeconds = 900,
        int $pollMilliseconds = 2000,
        bool $cleanup = true,
        ?callable $progress = null,
        ?callable $checkpoint = null,
    ): array {
        if (! $this->n8n->isConfigured()) {
            throw new RuntimeException('n8n Ticket Draft workflow is not configured.');
        }
        $workflow = $this->workflows->runnable($workflowKey, $workflowVersion);
        if ($workflow->center !== 'work' || $workflow->domain !== 'tickets') {
            throw new RuntimeException('The selected workflow is not a Ticket runtime workflow.');
        }

        $runId = (string) Str::uuid();
        $account = ChannelAccount::query()->firstOrCreate(
            ['channel' => 'replay', 'external_account_id' => 'ticket-draft-v1'],
            ['display_name' => 'Ticket Draft Replay'],
        );
        $predictions = [];

        foreach (array_chunk($cases, max(1, $batchSize)) as $batch) {
            $items = [];
            foreach ($batch as $case) {
                $conversation = $this->conversation($account, $case, $runId);
                $item = $this->workItems->create($conversation, 0, $workflowKey, $workflowVersion);
                $fixture = (array) ($case['fixture'] ?? []);
                $snapshot = (array) $item->input_snapshot;
                $item->update([
                    'input_snapshot' => [
                        ...$snapshot,
                        'replay_mode' => true,
                        'replay_run_id' => $runId,
                        'replay_case_id' => (string) $case['id'],
                        'replay_fixture' => $fixture,
                        'replay_fixture_checksum' => hash('sha256', json_encode($fixture, JSON_THROW_ON_ERROR)),
                    ],
                ]);
                $item->customerCase()->update(['case_type' => 'ticket_replay', 'queue' => 'replay']);
                if (($case['simulate_stale'] ?? false) === true) {
                    $this->message($conversation, 'Context changed after the replay snapshot.', now());
                }
                try {
                    $this->n8n->trigger($item);
                } catch (Throwable $exception) {
                    $item->update([
                        'status' => 'failed',
                        'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                        'finished_at' => now(),
                    ]);
                }
                $items[(string) $case['id']] = $item;
                if ($progress !== null) {
                    $progress("triggered={$case['id']} work_item={$item->work_item_key}");
                }
            }

            $deadline = microtime(true) + max(1, $timeoutSeconds);
            do {
                $pending = 0;
                foreach ($items as $item) {
                    $item->refresh();
                    $pending += in_array($item->status, ['queued', 'running'], true) ? 1 : 0;
                }
                if ($pending === 0) {
                    break;
                }
                usleep(max(10, $pollMilliseconds) * 1000);
            } while (microtime(true) < $deadline);

            foreach ($items as $caseId => $item) {
                $item->refresh();
                $timedOut = in_array($item->status, ['queued', 'running'], true);
                if ($timedOut) {
                    $item->update([
                        'status' => 'failed',
                        'error_message' => 'Ticket Draft replay runner timed out waiting for n8n.',
                        'finished_at' => now(),
                    ]);
                    $item->refresh();
                }
                $conversationDraft = ConversationDraft::query()->where('work_item_id', $item->id)->first();
                $result = (array) $item->result;
                $review = (array) ($result['review'] ?? []);
                $prediction = [
                    'id' => $caseId,
                    'status' => $timedOut ? 'timed_out' : ($result['draft_status'] ?? $item->status),
                    'intents' => data_get($result, 'plan.intents', []),
                    'answer_en' => $review['answer_en'] ?? null,
                    'answer_localized' => $conversationDraft?->body,
                    'knowledge_articles' => $result['knowledge_articles'] ?? [],
                    'facts' => $result['facts'] ?? [],
                    'missing_facts' => $result['missing_facts'] ?? [],
                    'reply_plan' => $result['reply_plan'] ?? null,
                    'review' => $result['review'] ?? [],
                    'safety_flags' => $result['safety_flags'] ?? [],
                    'duration_ms' => $this->duration($item),
                    'work_item_key' => $item->work_item_key,
                    'execution_id' => $item->execution_id,
                    'draft_id' => $result['draft_id'] ?? null,
                    'error_message' => $item->error_message,
                ];
                $predictions[] = $prediction;
                if ($checkpoint !== null) {
                    $checkpoint($prediction);
                }
                if ($progress !== null) {
                    $progress("completed={$caseId} status={$prediction['status']}");
                }
                if ($cleanup) {
                    $conversationId = (int) $item->customerCase->sources()
                        ->where('source_type', 'conversation')->value('source_id');
                    Conversation::query()->whereKey($conversationId)->delete();
                    CustomerCase::query()->whereKey($item->customer_case_id)->delete();
                }
            }
        }

        return $predictions;
    }

    /** @param array<string,mixed> $case */
    private function conversation(ChannelAccount $account, array $case, string $runId): Conversation
    {
        $conversation = Conversation::query()->create([
            'channel_account_id' => $account->id,
            'external_id' => "replay:{$runId}:{$case['id']}",
            'external_short_id' => (string) $case['id'],
            'site_key' => (string) ($case['site_key'] ?? 'dressafford.com'),
            'requester_email' => (string) data_get($case, 'fixture.requester_email', 'replay-customer@example.invalid'),
            'requester_snapshot' => ['name' => 'Replay Customer'],
            'draft_guidance' => $case['draft_guidance'] ?? null,
            'draft_guidance_updated_at' => isset($case['draft_guidance']) ? now() : null,
            'subject' => (string) ($case['subject'] ?? 'Order status question'),
            'silo' => 'replay',
            'status' => 'open',
            'source_type' => 'ticket_draft_replay',
            'raw_payload' => ['replay_run_id' => $runId, 'replay_case_id' => $case['id']],
            'created_at_external' => now(),
            'updated_at_external' => now(),
            'last_message_at' => now(),
        ]);
        if (isset($case['prior_agent_reply'])) {
            $this->message($conversation, (string) $case['prior_agent_reply'], now()->subSeconds(4), 'agent');
        }
        $this->message($conversation, (string) $case['question'], now()->subSeconds(3));
        if (isset($case['last_agent_reply'])) {
            $this->message($conversation, (string) $case['last_agent_reply'], now()->subSeconds(2), 'agent');
        }
        ConversationAnalysis::query()->create([
            'conversation_id' => $conversation->id,
            'primary_category' => 'replay_hint_only',
            'primary_problem_type' => data_get($case, 'expected_intents.0'),
            'urgency' => 'normal',
            'analyzer_version' => 'ticket-replay-fixture-v1',
            'analyzed_at' => now(),
        ]);

        return $conversation->load('analysis');
    }

    private function message(Conversation $conversation, string $body, $occurredAt, string $author = 'client'): void
    {
        ConversationEvent::query()->create([
            'conversation_id' => $conversation->id,
            'external_id' => (string) Str::uuid(),
            'event_type' => 'message',
            'occurred_at' => $occurredAt,
            'author_type' => $author,
            'is_private' => false,
            'body_text' => $body,
            'content_hash' => hash('sha256', $body.'|'.Str::uuid()),
            'raw_payload' => ['replay' => true],
        ]);
    }

    private function duration(WorkItem $item): int
    {
        $start = $item->started_at ?? $item->created_at;
        $finish = $item->finished_at ?? now();

        return max(0, (int) $start->diffInMilliseconds($finish));
    }
}
