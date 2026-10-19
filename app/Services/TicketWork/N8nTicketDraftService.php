<?php

namespace App\Services\TicketWork;

use App\Models\Conversation;
use App\Models\ConversationDraft;
use App\Models\ConversationEvent;
use App\Models\WorkItem;
use App\Services\N8n\WorkflowRegistry;
use App\Services\Work\WorkItemLeaseService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class N8nTicketDraftService
{
    public const WORKFLOW_KEY = 'work/ticket-draft-v1';

    public const WORKFLOW_VERSION = '1.0.0';

    public function __construct(
        private readonly TicketDraftInput $input,
        private readonly TicketRuntimeContext $context,
        private readonly TicketDraftPlanGuard $plans,
        private readonly TicketFactResolver $facts,
        private readonly TicketPolicyRepository $policies,
        private readonly WorkflowRegistry $workflows,
        private readonly WorkItemLeaseService $leases,
    ) {}

    /** @return array<string,mixed> */
    public function start(WorkItem $item, string $executionId): array
    {
        $workflow = $this->workflows->runnable((string) $item->workflow_key, (string) $item->workflow_version);
        if ($workflow->center !== 'work' || $workflow->domain !== 'tickets') {
            throw new RuntimeException('The workflow is not registered for the Ticket runtime center.');
        }
        if ($item->status === 'completed') {
            return ['already_completed' => true, 'result' => $item->result];
        }
        $item = $this->leases->lease($item, $executionId);
        $conversation = $this->conversation($item);
        $requestedBy = (int) data_get($item->input_snapshot, 'requested_by');
        $current = $this->input->capture(
            $conversation,
            $requestedBy,
            (string) $item->workflow_key,
            (string) $item->workflow_version,
        );
        if (! hash_equals((string) $item->input_hash, $current['hash'])) {
            $this->stale($item, 'Ticket messages, Analysis, or published Policies changed before execution.');
        }
        $replayFixture = $this->replayFixture($item);
        $catalog = $this->policies->available($conversation->site_key);
        if (is_array($replayFixture['available_policy_keys'] ?? null)) {
            $catalog = $catalog->whereIn('key', $replayFixture['available_policy_keys'])->values();
        }
        $item->update(['execution_id' => $executionId, 'result' => ['runtime' => []]]);

        return [
            'work_item_key' => $item->work_item_key,
            'input_hash' => $item->input_hash,
            'ticket' => $this->context->build($conversation, $current['trigger']),
            'routing_hint' => $conversation->analysis ? [
                'primary_category' => $conversation->analysis->primary_category,
                'primary_problem_type' => $conversation->analysis->primary_problem_type,
                'urgency' => $conversation->analysis->urgency,
            ] : null,
            'supported_intents' => TicketDraftPlanGuard::SUPPORTED_INTENTS,
            'verified_policy_catalog' => $catalog->map(fn ($article): array => [
                'key' => $article->key,
                'title' => $article->title,
                'knowledge_type' => $article->knowledge_type,
                'applicability' => $article->applicability,
                'automation_tier' => $article->automation_tier,
            ])->values()->all(),
            'allowed_actions' => $workflow->allowed_actions ?? [],
            'reply_style' => [
                'concise' => true,
                'warm_customer_service_voice' => true,
                'maximum_words' => 180,
                'answer_every_supported_clause' => true,
                'do_not_invent_facts' => true,
                'do_not_promise_future_actions' => true,
                'do_not_disclose_private_identifiers' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function resolveFacts(WorkItem $item, string $executionId, array $plan): array
    {
        $this->assertLease($item, $executionId);
        $plan = $this->plans->validate($plan);
        $plan['policy_keys'] = array_values(array_unique([
            ...$plan['policy_keys'],
            ...$this->policies->baselineKeys($plan['intents']),
        ]));
        $conversation = $this->conversation($item);
        $trigger = $this->trigger($conversation, $item);
        $resolved = $this->facts->resolve($conversation, $trigger, $plan, $this->replayFixture($item));
        $runtime = (array) data_get($item->result, 'runtime', []);
        $item->update(['result' => ['runtime' => [
            ...$runtime,
            'plan' => $plan,
            ...$resolved,
        ]]]);

        return ['plan' => $plan, ...$resolved];
    }

    /** @param list<string> $keys @return array{data:list<array<string,mixed>>} */
    public function policies(WorkItem $item, string $executionId, array $keys): array
    {
        $this->assertLease($item, $executionId);
        $conversation = $this->conversation($item);
        $runtime = (array) data_get($item->result, 'runtime', []);
        $plannedKeys = array_values(array_filter((array) data_get($runtime, 'plan.policy_keys', []), 'is_string'));
        $selected = $this->policies->selected($conversation->site_key, array_values(array_intersect($keys, $plannedKeys)));
        $replayFixture = $this->replayFixture($item);
        if (is_array($replayFixture['available_policy_keys'] ?? null)) {
            $selected = $selected->whereIn('key', $replayFixture['available_policy_keys'])->values();
        }
        $data = $selected->map(fn ($article): array => $this->policies->payload($article))->values()->all();
        $item->update(['result' => ['runtime' => [...$runtime, 'policies' => $data]]]);

        return ['data' => $data];
    }

    /** @return array<string,mixed> */
    public function complete(WorkItem $item, string $executionId, array $review): array
    {
        $this->assertLease($item, $executionId);
        $conversation = $this->conversation($item);
        $current = $this->input->capture(
            $conversation,
            (int) data_get($item->input_snapshot, 'requested_by'),
            (string) $item->workflow_key,
            (string) $item->workflow_version,
        );
        if (! hash_equals((string) $item->input_hash, $current['hash'])) {
            $this->stale($item, 'Ticket messages, Analysis, or published Policies changed during execution.');
        }
        $runtime = (array) data_get($item->result, 'runtime', []);
        $plan = $this->plans->validate((array) ($runtime['plan'] ?? []));
        $facts = array_values((array) ($runtime['facts'] ?? []));
        $missingFacts = array_values(array_filter((array) ($runtime['missing_facts'] ?? []), 'is_string'));
        $policies = array_values((array) ($runtime['policies'] ?? []));
        $answer = trim((string) ($review['final_answer'] ?? $review['answer_customer_language'] ?? $review['answer_en'] ?? ''));
        $coveredIntents = array_values(array_filter((array) ($review['covered_intents'] ?? []), 'is_string'));
        $safetyFlags = array_values(array_unique(array_filter([
            ...((array) ($runtime['safety_flags'] ?? [])),
            ...((array) ($review['safety_flags'] ?? [])),
            ...$this->plans->blockingReasons($plan, $missingFacts, count($policies)),
        ], 'is_string')));
        $approved = ($review['approved'] ?? false) === true
            && ($review['must_block'] ?? true) === false
            && array_diff($plan['intents'], $coveredIntents) === []
            && $answer !== '';
        if ($this->containsPrivateIdentifier($answer)) {
            $approved = false;
            $safetyFlags[] = 'The Draft contains a private email, phone, or order identifier.';
        }
        if ($this->containsRequesterName($answer, $conversation)) {
            $approved = false;
            $safetyFlags[] = 'The Draft unnecessarily repeats the customer name.';
        }
        if ($this->promisesUnsupportedAction($answer)) {
            $approved = false;
            $safetyFlags[] = 'The Draft promises a future human, factory, or carrier action that was not executed.';
        }
        if ($this->plans->blockingReasons($plan, $missingFacts, count($policies)) !== []) {
            $approved = false;
        }
        if ($answer === '') {
            $safetyFlags[] = 'The workflow did not return a usable Draft.';
        }
        if (str_word_count($answer) > 180) {
            $approved = false;
            $safetyFlags[] = 'The Draft exceeds the 180-word runtime limit.';
        }
        if (array_diff($plan['intents'], $coveredIntents) !== []) {
            $safetyFlags[] = 'The Draft does not cover every planned supported intent.';
        }
        $draftStatus = $approved ? 'draft' : 'blocked';

        return DB::transaction(function () use (
            $item,
            $executionId,
            $conversation,
            $plan,
            $facts,
            $missingFacts,
            $policies,
            $review,
            $answer,
            $safetyFlags,
            $draftStatus,
        ): array {
            $draft = ConversationDraft::query()->create([
                'conversation_id' => $conversation->id,
                'work_item_id' => $item->id,
                'body' => $answer,
                'is_private' => false,
                'status_after_send' => null,
                'source' => 'n8n',
                'metadata' => [
                    'workflow_key' => $item->workflow_key,
                    'workflow_version' => $item->workflow_version,
                    'input_hash' => $item->input_hash,
                    'plan' => $plan,
                    'facts' => $facts,
                    'missing_facts' => $missingFacts,
                    'knowledge_articles' => collect($policies)->map(fn ($policy): array => [
                        'id' => $policy['id'] ?? null,
                        'key' => $policy['key'] ?? null,
                        'version' => $policy['version'] ?? null,
                    ])->values()->all(),
                    'review' => $review,
                    'safety_flags' => array_values(array_unique($safetyFlags)),
                ],
                'requires_human_review' => true,
                'status' => $draftStatus,
                'created_by' => (int) data_get($item->input_snapshot, 'requested_by'),
                'updated_by' => (int) data_get($item->input_snapshot, 'requested_by'),
            ]);
            $result = [
                'schema_version' => '1.0.0',
                'draft_id' => $draft->id,
                'draft_status' => $draftStatus,
                'plan' => $plan,
                'facts' => $facts,
                'missing_facts' => $missingFacts,
                'knowledge_articles' => data_get($draft->metadata, 'knowledge_articles', []),
                'review' => $review,
                'safety_flags' => array_values(array_unique($safetyFlags)),
                'requires_human_review' => true,
            ];
            $this->leases->complete($item, $executionId, $result);

            return $result;
        });
    }

    public function fail(WorkItem $item, string $executionId, string $message): void
    {
        $this->assertLease($item, $executionId);
        $this->leases->fail($item, $executionId, $message);
    }

    private function conversation(WorkItem $item): Conversation
    {
        $source = $item->customerCase()->firstOrFail()->sources()
            ->where('source_type', 'conversation')->firstOrFail();

        return Conversation::query()->with('analysis')->findOrFail((int) $source->source_id);
    }

    private function trigger(Conversation $conversation, WorkItem $item): ConversationEvent
    {
        return $conversation->events()->findOrFail((int) data_get($item->input_snapshot, 'trigger_event_id'));
    }

    private function assertLease(WorkItem $item, string $executionId): void
    {
        $item->refresh();
        if ($item->status !== 'running' || $item->lease_owner !== $executionId
            || $item->lease_expires_at === null || $item->lease_expires_at->isPast()
        ) {
            throw new RuntimeException('The Ticket WorkItem is not leased by this n8n execution.');
        }
    }

    private function stale(WorkItem $item, string $message): never
    {
        $item->update([
            'status' => 'stale',
            'error_message' => $message,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'finished_at' => now(),
        ]);

        throw new RuntimeException($message);
    }

    /** @return array<string,mixed>|null */
    private function replayFixture(WorkItem $item): ?array
    {
        if (data_get($item->input_snapshot, 'replay_mode') !== true) {
            return null;
        }
        $fixture = data_get($item->input_snapshot, 'replay_fixture');
        $checksum = data_get($item->input_snapshot, 'replay_fixture_checksum');
        if (! is_array($fixture) || ! is_string($checksum)
            || ! hash_equals($checksum, hash('sha256', json_encode($fixture, JSON_THROW_ON_ERROR)))
        ) {
            throw new RuntimeException('Ticket replay fixture checksum is invalid.');
        }

        return $fixture;
    }

    private function containsPrivateIdentifier(string $answer): bool
    {
        return preg_match('/\b[a-z]{2,12}\d{8}-\d{3,12}\b/i', $answer) === 1
            || preg_match('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', $answer) === 1
            || $this->containsPhoneNumber($answer);
    }

    private function promisesUnsupportedAction(string $answer): bool
    {
        return preg_match('/\b(?:we|our (?:team|support|factory))\s+(?:will|can)\s+(?:check|verify|contact|follow up|escalate|investigate|get back|ask)\b/i', $answer) === 1
            || preg_match('/\bwill be\s+(?:checked|confirmed|reviewed|escalated|noted|forwarded)\b|\bhas been\s+(?:escalated|forwarded)\b/i', $answer) === 1;
    }

    private function containsPhoneNumber(string $answer): bool
    {
        preg_match_all('/(?<!\w)(?:\+?\d[\d\s().-]{7,}\d)(?!\w)/', $answer, $matches);

        return collect($matches[0] ?? [])->contains(function (string $candidate): bool {
            $digits = preg_replace('/\D/', '', $candidate) ?? '';

            return str_starts_with(trim($candidate), '+') ? strlen($digits) >= 8 : strlen($digits) >= 10;
        });
    }

    private function containsRequesterName(string $answer, Conversation $conversation): bool
    {
        return collect([
            data_get($conversation->requester_snapshot, 'name'),
            data_get($conversation->requester_snapshot, 'first_name'),
            data_get($conversation->requester_snapshot, 'last_name'),
            data_get($conversation->requester_snapshot, 'display_name'),
        ])->filter(fn ($name): bool => is_string($name) && mb_strlen(trim($name)) >= 2)
            ->contains(fn ($name): bool => mb_stripos($answer, trim($name)) !== false);
    }
}
