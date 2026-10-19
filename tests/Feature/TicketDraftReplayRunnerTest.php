<?php

namespace Tests\Feature;

use App\Models\KnowledgeArticle;
use App\Models\WorkItem;
use App\Services\TicketWork\N8nTicketDraftService;
use App\Services\TicketWork\TicketDraftReplayRunner;
use App\Services\TicketWork\TicketDraftReplaySuite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TicketDraftReplayRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_runner_uses_real_webhook_boundary_frozen_facts_and_cleans_isolated_records(): void
    {
        config([
            'services.n8n.base_url' => 'https://n8n.test',
            'services.n8n.webhook_token' => 'replay-token',
            'services.n8n.ticket_draft_webhook_path' => '/webhook/ticket-draft-v1',
        ]);
        $this->policy('production_status_eta_verification');
        $service = app(N8nTicketDraftService::class);
        Http::fake(function (Request $request) use ($service) {
            $item = WorkItem::query()->where('work_item_key', $request['work_item_key'])->firstOrFail();
            $executionId = 'replay-execution-'.$item->id;
            $service->start($item, $executionId);
            $plan = [
                'clauses' => [['text' => 'Production status?', 'intent' => 'production_order_status']],
                'intents' => ['production_order_status'],
                'policy_keys' => [],
                'unsupported_requests' => [],
                'customer_language' => 'en',
            ];
            $service->resolveFacts($item, $executionId, $plan);
            $service->policies($item, $executionId, ['production_status_eta_verification']);
            $service->complete($item, $executionId, [
                'approved' => true,
                'must_block' => false,
                'covered_intents' => ['production_order_status'],
                'answer_en' => 'Your order is currently in production.',
                'final_answer' => 'Your order is currently in production.',
                'safety_flags' => [],
            ]);

            return Http::response(['accepted' => true], 200);
        });

        $case = app(TicketDraftReplaySuite::class)->cases()[0];
        $predictions = app(TicketDraftReplayRunner::class)->run(
            cases: [$case],
            workflowKey: N8nTicketDraftService::WORKFLOW_KEY,
            workflowVersion: N8nTicketDraftService::WORKFLOW_VERSION,
            batchSize: 1,
            timeoutSeconds: 2,
            pollMilliseconds: 10,
            cleanup: true,
        );

        $this->assertCount(1, $predictions);
        $this->assertSame('draft', $predictions[0]['status']);
        $this->assertSame(['production_order_status'], $predictions[0]['intents']);
        $this->assertSame('ticket-replay-fixture', collect($predictions[0]['facts'])->firstWhere('key', 'order.status')['source']);
        $this->assertDatabaseMissing('customer_cases', ['case_type' => 'ticket_replay']);
        $this->assertDatabaseMissing('conversations', ['source_type' => 'ticket_draft_replay']);
        Http::assertSentCount(1);
    }

    private function policy(string $key): void
    {
        KnowledgeArticle::query()->create([
            'key' => $key,
            'title' => str_replace('_', ' ', $key),
            'knowledge_type' => 'process',
            'status' => 'verified',
            'site_key' => null,
            'applicability' => 'Existing orders',
            'required_facts' => ['order status'],
            'policy_rules' => ['Use current order facts.'],
            'decision_tree' => [],
            'reply_strategy' => [],
            'reply_templates' => [],
            'action_steps' => [],
            'prohibited_claims' => ['Do not promise a future action.'],
            'escalation_rules' => [],
            'automation_tier' => 'draft_sensitive',
            'version' => 1,
        ]);
    }
}
