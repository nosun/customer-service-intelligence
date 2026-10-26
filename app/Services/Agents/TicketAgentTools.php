<?php

namespace App\Services\Agents;

use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Services\TicketWork\N8nTicketDraftService;
use App\Services\TicketWork\TicketDraftInput;
use App\Services\TicketWork\TicketDraftPlanGuard;
use App\Services\TicketWork\TicketFactResolver;
use App\Services\TicketWork\TicketOrderWorkbench;
use App\Services\TicketWork\TicketPolicyRepository;
use App\Services\TicketWork\TicketRuntimeContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use RuntimeException;

/** A single-run capability. Tool callers cannot supply a Ticket, URL, path or credential. */
class TicketAgentTools
{
    public array $ledger = [];

    public ?array $plan = null;

    public array $facts = [];

    public array $policies = [];

    public ?array $returnAssessment = null;

    private ?array $siteReturnPolicy = null;

    private array $documents = [];

    private array $documentResults = [];

    private string $expectedHash;

    public function __construct(public readonly Conversation $ticket)
    {
        if ($ticket->silo !== 'tickets' || $ticket->source_type === 'replay' || ! in_array($ticket->status, ['open', 'pending', 'onhold'], true)) {
            throw new RuntimeException('Only unresolved non-replay Tickets can run the Agent.');
        }
        $this->expectedHash = $this->fingerprint();
    }

    public function initial(): array
    {
        $context = app(TicketRuntimeContext::class);

        return [
            'case_key' => 'ticket:'.$this->ticket->id, 'input_hash' => $this->expectedHash,
            'site_key' => $this->ticket->site_key,
            'subject' => $context->redactForConversation((string) $this->ticket->subject, $this->ticket),
            'policy_catalog' => app(TicketPolicyRepository::class)->available($this->ticket->site_key)->map(fn ($p) => [
                'key' => $p->key, 'title' => $p->title, 'intents' => $p->bindings->pluck('intent_key')->all(), 'version' => $p->version,
            ])->all(),
        ];
    }

    public function trigger(): ConversationEvent
    {
        return $this->ticket->events()->where('event_type', 'message')->where('is_private', false)
            ->whereIn('author_type', ['client', 'customer', 'agent'])->orderByDesc('occurred_at')->orderByDesc('id')->first()
            ?? throw new RuntimeException('Ticket has no public message to assess.');
    }

    public function fingerprint(bool $includeOrderLink = true): string
    {
        $ticket = $this->ticket->fresh();
        $input = app(TicketDraftInput::class)->capture($ticket, 0, N8nTicketDraftService::INVESTIGATION_KEY, '2.0.0', $this->trigger());
        $snapshot = $input['snapshot'];
        unset($snapshot['order_context']); // Own read-only lookups may create local snapshots.
        if (! $includeOrderLink) {
            unset($snapshot['linked_order_number']);
        }
        $events = $ticket->events()->with('attachments')->orderBy('id')->get();

        return hash('sha256', json_encode([
            hash_file('sha256', resource_path('data/ticket-return-playbook-v1.json')),
            $snapshot, $ticket->only(['channel_account_id', 'source_type', 'requester_email', 'requester_snapshot', 'subject']),
            $events->map(fn ($e) => [$e->only(['id', 'event_type', 'body_text', 'is_private', 'author_type', 'occurred_at']),
                $e->attachments->map(fn ($a) => $a->only(['id', 'filename', 'sha256', 'remote_url', 'updated_at']))->all()])->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function hash(): string
    {
        return $this->expectedHash;
    }

    public function assertCurrent(): void
    {
        if (! hash_equals($this->expectedHash, $this->fingerprint())) {
            throw new RuntimeException('Ticket input or verified policy changed during the Agent run.');
        }
        foreach ($this->facts as $fact) {
            if (Carbon::parse($fact['fresh_until'])->lte(now())) {
                throw new RuntimeException('Agent business facts expired.');
            }
        }
        if (isset($this->siteReturnPolicy['fresh_until']) && Carbon::parse($this->siteReturnPolicy['fresh_until'])->lte(now())) {
            throw new RuntimeException('Current site return policy evidence expired.');
        }
    }

    public function call(string $name, array $arguments): array
    {
        $this->assertCurrent();
        if (count($this->ledger) >= config('ticket-agent.max_tool_calls')) {
            throw new RuntimeException('Agent tool budget exceeded.');
        }
        $result = match ($name) {
            'read_conversation' => $this->conversation(),
            'record_plan' => $this->recordPlan($arguments),
            'read_order' => $this->order(),
            'read_policy' => $this->policy((string) ($arguments['key'] ?? '')),
            'read_product' => $this->product((string) ($arguments['product_sn'] ?? '')),
            'list_documents' => $this->listDocuments(),
            'inspect_document' => $this->document((string) ($arguments['document_id'] ?? '')),
            'read_return_status' => $this->returnStatus(),
            'read_return_playbook' => $this->returnPlaybook(),
            'read_site_return_policy' => $this->sitePolicy(),
            'record_return_assessment' => $this->assessReturn($arguments),
            'read_return_template' => $this->returnTemplate(),
            default => throw new RuntimeException('Unknown Agent tool.'),
        };
        $this->assertCurrent();
        $this->ledger[] = ['name' => $name, 'arguments' => $arguments, 'result' => $result];

        return $result;
    }

    public function evidenceRefs(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn ($call) => $call['result']['evidence_refs'] ?? [], $this->ledger))));
    }

    private function conversation(): array
    {
        $context = app(TicketRuntimeContext::class);
        $trigger = $this->trigger();
        $ticket = $context->build($this->ticket, $trigger, true);
        // Attachment events can be separate from their corresponding message events.
        $documents = $this->listDocuments();
        $attachmentEvents = $this->ticket->events()->where('is_private', false)->where('event_type', 'attachments')->orderBy('occurred_at')->get();
        foreach ($attachmentEvents as $event) {
            $ticket['messages'][] = ['event_id' => $event->id, 'role' => $event->author_type === 'agent' ? 'agent' : 'customer',
                'event_type' => 'attachments', 'occurred_at' => $event->occurred_at?->toIso8601String(),
                'body' => 'Documents supplied: '.implode(', ', array_column(array_filter($documents['documents'], fn ($d) => $d['event_id'] === $event->id), 'id'))];
        }
        $guidance = $this->ticket->fresh()->draftGuidance();

        return [
            ...$ticket, 'operator_guidance' => $guidance === null ? null : $context->redactForConversation($guidance['text'], $this->ticket),
            'documents' => $documents['documents'],
            'evidence_refs' => [...array_map(fn ($e) => 'event:'.$e['event_id'], [...$ticket['messages'], ...$ticket['related_messages']]), ...($guidance ? ['guidance:current'] : [])],
        ];
    }

    private function recordPlan(array $arguments): array
    {
        if (! collect($this->ledger)->contains('name', 'read_conversation')) {
            throw new RuntimeException('Read the conversation before planning.');
        }
        $plan = app(TicketDraftPlanGuard::class)->validate($arguments, true);
        $steps = $arguments['steps'] ?? [];
        if (! is_array($steps) || $steps === [] || count($steps) > 12 || collect($steps)->contains(fn ($s) => ! is_string($s) || trim($s) === '' || mb_strlen($s) > 1000)) {
            throw new RuntimeException('An Agent plan needs bounded investigation steps.');
        }
        $this->plan = [...$plan, 'steps' => $steps];

        return ['plan' => $this->plan, 'evidence_refs' => []];
    }

    private function requirePlan(): void
    {
        if ($this->plan === null) {
            throw new RuntimeException('Record a plan before investigating.');
        }
    }

    private function order(): array
    {
        $this->requirePlan();
        $unchanged = $this->fingerprint(false);
        $snapshot = $this->ticket->latestOrderContext()->first();
        if ($snapshot !== null && $snapshot->queried_at->lt(now()->subSeconds(30))) {
            // Start a multi-turn investigation with enough lifetime; reading a cache does not renew its facts.
            app(TicketOrderWorkbench::class)->inspect($this->ticket);
        }
        $resolved = app(TicketFactResolver::class)->resolveInvestigation($this->ticket, $this->trigger(), $this->plan);
        // The existing verified lookup may link the selected order locally. No other changes are accepted.
        if (! hash_equals($unchanged, $this->fingerprint(false))) {
            throw new RuntimeException('Ticket changed while reading the order.');
        }
        $this->ticket->refresh();
        $this->expectedHash = $this->fingerprint();
        $facts = array_values(array_filter($resolved['facts'], fn ($f) => ($f['shareable_with_ai'] ?? false) === true
            && in_array($f['sensitivity'] ?? '', ['public', 'internal'], true)
            && isset($f['fresh_until']) && Carbon::parse($f['fresh_until'])->gt(now())));
        $identity = collect($facts)->firstWhere('key', 'order.identity_match');
        if ($resolved['safety_flags'] !== [] || ($identity['value'] ?? false) !== true) {
            return ['facts' => [], 'missing_facts' => ['order.identity_verified'], 'safety_flags' => ['Order lookup or identity is unverified.'], 'evidence_refs' => []];
        }
        $snapshot = $this->ticket->latestOrderContext()->first();
        if ($snapshot !== null && $snapshot->lookup_status === 'found') {
            $items = collect(data_get($snapshot->summary, 'order.items', []))->map(fn ($item) => Arr::only($item,
                ['type', 'size', 'quantity', 'unit_price', 'total_amount', 'product_sn']))->values()->all();
            $amounts = Arr::only((array) data_get($snapshot->summary, 'order.amounts', []), ['total_amount', 'pay_amount', 'goods_amount', 'shipping_amount', 'shipping_protection_amount']);
            foreach (['order.return_items' => $items, 'order.return_amounts' => $amounts] as $key => $value) {
                $facts[] = ['key' => $key, 'value' => $value, 'source' => 'console-new', 'captured_at' => $snapshot->queried_at->toIso8601String(),
                    'fresh_until' => $snapshot->queried_at->copy()->addMinutes(10)->toIso8601String(), 'shareable_with_ai' => true, 'sensitivity' => 'internal'];
            }
            foreach ($facts as &$fact) {
                $fact['captured_at'] = $snapshot->queried_at->toIso8601String();
                $fact['fresh_until'] = $snapshot->queried_at->copy()->addMinutes(10)->toIso8601String();
            }
            unset($fact);
        }
        foreach ($facts as $fact) {
            $this->facts = [...array_values(array_filter($this->facts, fn ($f) => $f['key'] !== $fact['key'])), $fact];
        }

        return ['facts' => $facts, 'missing_facts' => $resolved['missing_facts'], 'safety_flags' => [],
            'limitations' => ['Shipment facts refer to the original outbound shipment, not the customer return.', 'Empty refund records do not establish payment gateway settlement.'],
            'evidence_refs' => array_map(fn ($f) => 'fact:'.$f['key'], $facts)];
    }

    private function policy(string $key): array
    {
        $this->requirePlan();
        $article = app(TicketPolicyRepository::class)->available($this->ticket->site_key)->firstWhere('key', $key);
        if ($article === null || ($article->bindings->pluck('intent_key')->intersect(['*', ...$this->plan['intents']])->isEmpty())) {
            return ['status' => 'unavailable', 'reason' => 'Policy is not verified or applicable to this case plan. Select an applicable key from the policy catalog.', 'evidence_refs' => []];
        }
        $payload = app(TicketPolicyRepository::class)->payload($article);
        $this->policies[$key] = $payload;

        return ['policy' => $payload, 'evidence_refs' => ['policy:'.$key.'@'.$article->version]];
    }

    private function product(string $sn): array
    {
        $this->requirePlan();
        $plan = $this->plan;
        $plan['clauses'] = array_map(fn ($clause) => [...$clause, 'product_sn' => $sn], $plan['clauses']);
        $context = app(TicketRuntimeContext::class);
        $resolved = app(TicketFactResolver::class)->resolveProducts($this->ticket, $this->trigger(), $plan, null);
        foreach ($resolved['facts'] as $fact) {
            $this->facts = [...array_values(array_filter($this->facts, fn ($f) => $f['key'] !== $fact['key'])), $fact];
        }

        return [...$resolved, 'evidence_refs' => array_map(fn ($f) => 'fact:'.$f['key'], $resolved['facts'])];
    }

    private function listDocuments(): array
    {
        $context = app(TicketRuntimeContext::class);
        $events = $this->ticket->events()->with('attachments')->where('is_private', false)->whereIn('event_type', ['message', 'attachments'])->orderByDesc('occurred_at')->orderByDesc('id')->get();
        foreach ($events as $event) {
            foreach ($event->attachments as $attachment) {
                $id = 'attachment:'.$attachment->id;
                $this->documents[$id] = ['id' => $id, 'event_id' => $event->id, 'filename' => $context->redact($attachment->filename ?? ''), 'url' => $attachment->remote_url, 'sha256' => $attachment->sha256];
            }
            preg_match_all('~https://drive\.google\.com/file/d/[a-zA-Z0-9_-]+(?:/[^\s<>]*)?~', (string) $event->body_text, $links);
            foreach ($links[0] as $url) {
                $id = 'link:'.substr(hash('sha256', $url), 0, 16);
                $this->documents[$id] = ['id' => $id, 'event_id' => $event->id, 'filename' => 'Linked document', 'url' => $url, 'sha256' => null];
            }
        }

        return ['documents' => array_values(array_map(fn ($d) => array_diff_key($d, ['url' => 1, 'sha256' => 1]), $this->documents)), 'evidence_refs' => []];
    }

    private function document(string $id): array
    {
        $this->requirePlan();
        if (! isset($this->documents[$id])) {
            throw new RuntimeException('Document is not in the current Ticket catalog.');
        }
        $result = $this->documentResults[$id] ??= app(TicketAgentDocuments::class)->inspect($this->documents[$id]);

        return ['document_id' => $id, ...$result, 'evidence_refs' => ['document:'.$id]];
    }

    private function returnStatus(): array
    {
        $this->requirePlan();
        $numbers = collect($this->documentResults)->flatMap(fn ($d) => collect($d['pages'] ?? [])->flatMap(fn ($p) => $p['reference_numbers'] ?? []))->unique()->values()->all();

        return ['reference_numbers_from_documents' => $numbers, 'carrier_status' => 'unavailable', 'warehouse_status' => 'unavailable',
            'missing_facts' => ['return.carrier_tracking', 'return.warehouse_receipt', 'return.inspection'],
            'limitations' => ['No return-carrier or warehouse query adapter is configured. This call did not verify receipt, inspection or delivery.'],
            'evidence_refs' => ['capability:return_status']];
    }

    private function returnPlaybook(): array
    {
        $this->requirePlan();

        $playbook = app(TicketReturnAssessment::class)->agentPlaybook();

        return ['playbook' => $playbook, 'evidence_refs' => ['strategy:return@'.$playbook['version']]];
    }

    private function sitePolicy(): array
    {
        $this->requirePlan();
        if ($this->siteReturnPolicy === null) {
            $this->siteReturnPolicy = app(TicketSiteReturnPolicy::class)->read($this->ticket->site_key);
            if ($this->siteReturnPolicy['status'] === 'read') {
                $this->facts[] = ['key' => 'site.return_policy', 'value' => ['content_hash' => $this->siteReturnPolicy['content_hash'], 'url' => $this->siteReturnPolicy['url']],
                    'source' => 'current-site-policy', 'captured_at' => $this->siteReturnPolicy['captured_at'], 'fresh_until' => $this->siteReturnPolicy['fresh_until'],
                    'shareable_with_ai' => true, 'sensitivity' => 'public'];
            }
        }

        return $this->siteReturnPolicy;
    }

    private function assessReturn(array $arguments): array
    {
        $this->requirePlan();
        if (! collect($this->ledger)->contains('name', 'read_return_playbook')) {
            throw new RuntimeException('Read return strategy before recording the assessment.');
        }
        $result = app(TicketReturnAssessment::class)->recordForTool($arguments, $this->evidenceRefs());
        $this->returnAssessment = $result['assessment'] ?? null;

        return $result;
    }

    private function returnTemplate(): array
    {
        if (! ($this->returnAssessment['applicable'] ?? false)) {
            throw new RuntimeException('Record an applicable return assessment before selecting its template.');
        }
        $stage = $this->returnAssessment['stage'];
        $playbook = app(TicketReturnAssessment::class)->agentPlaybook();

        return ['stage' => $stage, ...$playbook['stages'][$stage],
            'evidence_refs' => ['template:'.$this->returnAssessment['template_id'].'@'.$playbook['version']]];
    }
}
