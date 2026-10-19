<?php

namespace Tests\Feature;

use App\Models\AiWorkflow;
use App\Models\CommerceProduct;
use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionDraft;
use App\Services\N8n\N8nDraftWorkflowClient;
use App\Services\ProductQuestions\N8nProductQuestionDraftService;
use App\Services\ProductQuestions\ProductQuestionDraftReplayRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ProductQuestionDraftReplayRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_runner_uses_real_draft_requests_collects_predictions_and_can_clean_up(): void
    {
        $site = CommerceSite::create([
            'name' => 'Replay Site', 'site_key' => 'replay-site',
            'admin_api_url' => 'https://replay.test/api/admin', 'api_token' => 'secret', 'enabled' => true,
        ]);
        $product = CommerceProduct::create([
            'commerce_site_id' => $site->id, 'sn' => 'REPLAY-1', 'name' => 'Replay Dress',
            'product' => ['sn' => 'REPLAY-1', 'name' => 'Replay Dress', 'type' => 'dress'],
            'product_detail' => ['attributes' => ['field_silhouette' => ['A-Line']]],
            'images' => [], 'options' => [], 'payload_hash' => str_repeat('a', 64),
            'sync_status' => 'completed', 'synced_at' => now(),
        ]);
        AiWorkflow::create([
            'workflow_key' => N8nProductQuestionDraftService::WORKFLOW_KEY,
            'workflow_version' => '1.2.0',
            'status' => 'evaluating',
            'prompt_version' => 'test-prompt-v4',
            'allowed_fact_keys' => [],
            'allowed_actions' => [],
        ]);
        $this->mock(N8nDraftWorkflowClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturnTrue();
            $mock->shouldReceive('trigger')->twice()->andReturnUsing(function ($request): void {
                $this->assertSame('1.2.0', data_get($request->input_snapshot, 'workflow.version'));
                $this->assertSame('test-prompt-v4', data_get($request->input_snapshot, 'workflow.prompt_version'));
                $caseId = data_get($request->question->raw_payload, '_replay.case_id');
                if ($caseId === 'pq-001') {
                    $this->assertSame('Yes', data_get($request->question->raw_payload, '_replay.fixture.product.attributes.field_builtinbra'));
                    $this->assertNotNull(data_get($request->input_snapshot, 'question.replay_fixture_hash'));
                }
                $draft = ProductQuestionDraft::create([
                    'product_question_id' => $request->product_question_id,
                    'status' => $caseId === 'pq-001' ? 'generated' : 'blocked',
                    'model' => 'test', 'prompt_version' => 'test',
                    'intents' => $caseId === 'pq-001' ? ['ask_pockets', 'request_add_pockets'] : ['ask_order_status'],
                    'knowledge_article_ids' => [], 'facts' => [], 'fact_sources' => [],
                    'missing_facts' => [], 'safety_flags' => [], 'answer_en' => 'Replay answer.',
                    'recommended_visible' => $caseId === 'pq-001', 'requires_human_review' => true,
                    'input_hash' => str_repeat('b', 64), 'generated_at' => now(),
                ]);
                $request->update([
                    'status' => 'completed', 'product_question_draft_id' => $draft->id,
                    'n8n_execution_id' => 'execution-'.$caseId, 'started_at' => now(), 'finished_at' => now(),
                ]);
            });
        });

        $predictions = app(ProductQuestionDraftReplayRunner::class)->run(
            $site,
            $product,
            [
                ['id' => 'pq-001', 'question' => 'Does it have pockets?', 'fixture' => [
                    'product' => ['attributes' => ['field_builtinbra' => 'Yes']],
                ]],
                ['id' => 'pq-049', 'question' => 'Show my order publicly.'],
            ],
            N8nProductQuestionDraftService::WORKFLOW_KEY,
            '1.2.0',
            batchSize: 2,
            pollMilliseconds: 10,
            cleanup: true,
        );

        $this->assertSame(['generated', 'blocked'], array_column($predictions, 'status'));
        $this->assertSame(['execution-pq-001', 'execution-pq-049'], array_column($predictions, 'execution_id'));
        $this->assertSame(0, ProductQuestion::count());
    }

    public function test_interrupted_replay_is_hidden_and_retains_audit_records(): void
    {
        $site = CommerceSite::create([
            'name' => 'Replay Site', 'site_key' => 'replay-site',
            'admin_api_url' => 'https://replay.test/api/admin', 'api_token' => 'secret', 'enabled' => true,
        ]);
        $product = CommerceProduct::create([
            'commerce_site_id' => $site->id, 'sn' => 'REPLAY-1', 'name' => 'Replay Dress',
            'product' => ['sn' => 'REPLAY-1', 'name' => 'Replay Dress', 'type' => 'dress'],
            'product_detail' => ['attributes' => ['field_silhouette' => ['A-Line']]],
            'images' => [], 'options' => [], 'payload_hash' => str_repeat('a', 64),
            'sync_status' => 'completed', 'synced_at' => now(),
        ]);
        AiWorkflow::create([
            'workflow_key' => N8nProductQuestionDraftService::WORKFLOW_KEY,
            'workflow_version' => '1.2.0',
            'status' => 'evaluating',
            'prompt_version' => 'test-prompt-v4',
            'allowed_fact_keys' => [],
            'allowed_actions' => [],
        ]);
        $this->mock(N8nDraftWorkflowClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturnTrue();
            $mock->shouldReceive('trigger')->once()->andReturnNull();
        });

        try {
            app(ProductQuestionDraftReplayRunner::class)->run(
                $site,
                $product,
                [['id' => 'pq-001', 'question' => 'Does it have pockets?']],
                N8nProductQuestionDraftService::WORKFLOW_KEY,
                '1.2.0',
                progress: function (): void {
                    $this->assertSame(0, ProductQuestion::withoutReplay()->count());
                    throw new \RuntimeException('Interrupted replay');
                },
            );
            $this->fail('Expected interruption.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Interrupted replay', $exception->getMessage());
        }

        $question = ProductQuestion::firstOrFail();
        $this->assertFalse($question->remote_present);
        $this->assertSame(1, $question->draftRequests()->count());
    }
}
