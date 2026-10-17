<?php

namespace Tests\Feature;

use App\Models\CommerceProduct;
use App\Models\CommerceSite;
use App\Models\KnowledgeArticle;
use App\Models\N8nWorkflowExecution;
use App\Models\ProductQuestionAnalysis;
use App\Models\ProductQuestionDraft;
use App\Models\ProductQuestionDraftRequest;
use App\Services\ProductQuestions\N8nProductQuestionDraftService;
use App\Services\ProductQuestions\ProductQuestionAnalyzer;
use App\Services\ProductQuestions\ProductQuestionDraftGenerator;
use App\Services\TradeManager\ProductQuestionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class N8nProductQuestionWorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.n8n.internal_api_token' => 'test-n8n-token']);
    }

    public function test_internal_api_requires_its_service_token(): void
    {
        $this->postJson('/api/internal/n8n/product-question-drafts/lease', [])
            ->assertUnauthorized();
    }

    public function test_internal_api_queries_the_normalized_dress_length_chart(): void
    {
        $this->withToken('test-n8n-token')
            ->getJson('/api/internal/n8n/reference/dress-lengths?hemline=Short%2FMini&size=2')
            ->assertOk()
            ->assertJsonPath('data.hemline_key', 'short_mini')
            ->assertJsonPath('data.size', '2')
            ->assertJsonPath('data.measurement', 'hollow_to_hem')
            ->assertJsonPath('data.value_cm', 82)
            ->assertJsonPath('data.value_in', 32.3)
            ->assertJsonPath('data.not_equivalent_to', 'shoulder_to_hem');
    }

    public function test_analysis_lease_returns_only_eligible_questions_for_the_site(): void
    {
        $site = $this->site();
        $first = $this->question($site, 101, 'unanswered');
        $reviewed = $this->question($site, 102, 'answered');
        ProductQuestionAnalysis::create($this->analysis($reviewed->id, 'confirmed', 'a'));

        $this->withToken('test-n8n-token')
            ->postJson('/api/internal/n8n/product-question-analysis/lease', [
                'site_key' => $site->site_key,
                'limit' => 10,
                'reply_status' => 'all',
            ])
            ->assertOk()
            ->assertJsonPath('workflow_key', 'analysis/qa-analysis-v1')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.question_id', $first->id);
    }

    public function test_draft_lease_only_returns_unanswered_questions(): void
    {
        $site = $this->site();
        $unanswered = $this->question($site, 201, 'unanswered');
        $this->question($site, 202, 'answered');

        $this->withToken('test-n8n-token')
            ->postJson('/api/internal/n8n/product-question-drafts/lease', [
                'site_key' => $site->site_key,
                'limit' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('workflow_key', 'work/qa-draft-v1')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.question_id', $unanswered->id);
    }

    public function test_analysis_execution_is_audited_and_idempotent(): void
    {
        $question = $this->question($this->site(), 301, 'answered');
        $analysis = ProductQuestionAnalysis::create($this->analysis($question->id, 'candidate', 'b'));
        $this->mock(ProductQuestionAnalyzer::class, function (MockInterface $mock) use ($analysis): void {
            $mock->shouldReceive('isConfigured')->once()->andReturnTrue();
            $mock->shouldReceive('analyze')->once()->andReturn([
                'status' => 'completed',
                'analysis_id' => $analysis->id,
                'run_id' => 9,
                'error' => null,
                'usage' => ['total_tokens' => 10],
            ]);
        });
        $payload = [
            'workflow_key' => 'analysis/qa-analysis-v1',
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'execution_id' => 'execution-analysis-1',
        ];

        $this->withToken('test-n8n-token')
            ->postJson("/api/internal/n8n/product-questions/{$question->id}/analyze", $payload)
            ->assertOk()
            ->assertJsonPath('reused', false)
            ->assertJsonPath('result.analysis_id', $analysis->id);
        $this->withToken('test-n8n-token')
            ->postJson("/api/internal/n8n/product-questions/{$question->id}/analyze", $payload)
            ->assertOk()
            ->assertJsonPath('reused', true)
            ->assertJsonPath('result.analysis_id', $analysis->id);

        $this->assertSame(1, N8nWorkflowExecution::count());
        $this->assertDatabaseHas('n8n_workflow_executions', [
            'product_question_id' => $question->id,
            'execution_id' => 'execution-analysis-1',
            'status' => 'completed',
            'result_id' => $analysis->id,
        ]);
    }

    public function test_draft_execution_persists_a_local_draft_without_site_reply(): void
    {
        $question = $this->question($this->site(), 401, 'unanswered');
        $draft = ProductQuestionDraft::create([
            'product_question_id' => $question->id,
            'status' => 'generated',
            'model' => 'test-model',
            'prompt_version' => 'test-prompt',
            'intents' => ['ask_pockets'],
            'knowledge_article_ids' => [],
            'facts' => [],
            'fact_sources' => [],
            'missing_facts' => [],
            'safety_flags' => [],
            'answer_en' => 'This is a local draft.',
            'recommended_visible' => true,
            'requires_human_review' => true,
            'input_hash' => str_repeat('c', 64),
        ]);
        $this->mock(ProductQuestionDraftGenerator::class, function (MockInterface $mock) use ($draft): void {
            $mock->shouldReceive('isConfigured')->once()->andReturnTrue();
            $mock->shouldReceive('generate')->once()->andReturn($draft);
        });

        $this->withToken('test-n8n-token')
            ->postJson("/api/internal/n8n/product-questions/{$question->id}/draft", [
                'workflow_key' => 'work/qa-draft-v1',
                'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
                'execution_id' => 'execution-draft-1',
            ])
            ->assertOk()
            ->assertJsonPath('result.status', 'generated')
            ->assertJsonPath('result.draft_id', $draft->id);

        $this->assertSame('unanswered', $question->fresh()->reply_status);
        $this->assertNull($question->fresh()->answer);
    }

    public function test_n8n_agent_contract_runs_context_facts_policies_review_and_save(): void
    {
        $site = $this->site();
        $question = $this->question($site, 501, 'unanswered');
        CommerceProduct::create([
            'commerce_site_id' => $site->id,
            'sn' => 'QA-501',
            'name' => 'Long Product Title That Must Not Be Repeated',
            'product_url' => 'https://dressafford.test/product.html',
            'product' => ['sn' => 'QA-501', 'name' => 'Long Product Title That Must Not Be Repeated', 'type' => 'dress'],
            'product_detail' => ['attributes' => ['field_silhouette' => ['A-Line']]],
            'images' => [],
            'options' => [],
            'payload_hash' => str_repeat('d', 64),
            'sync_status' => 'completed',
            'synced_at' => now(),
        ]);
        $article = KnowledgeArticle::create([
            'key' => 'qa_global_ask_pockets',
            'title' => 'Pocket facts',
            'knowledge_type' => 'policy',
            'status' => 'verified',
            'automation_tier' => 'draft_low_risk',
            'applicability' => 'Pocket questions',
            'required_facts' => ['product.attributes.pockets'],
            'policy_rules' => ['Use the current product fact.'],
            'decision_tree' => [],
            'reply_strategy' => [],
            'reply_templates' => [],
            'action_steps' => [],
            'prohibited_claims' => [],
            'escalation_rules' => [],
            'version' => 1,
        ]);
        KnowledgeArticle::create([
            'key' => 'qa_global_request_add_pockets',
            'title' => 'Add pockets',
            'knowledge_type' => 'policy',
            'status' => 'verified',
            'automation_tier' => 'draft_low_risk',
            'applicability' => 'Pocket customization',
            'required_facts' => ['product.attributes.field_silhouette'],
            'policy_rules' => ['Use the current silhouette.'],
            'decision_tree' => [],
            'reply_strategy' => [],
            'reply_templates' => [],
            'action_steps' => [],
            'prohibited_claims' => [],
            'escalation_rules' => [],
            'version' => 1,
        ]);
        $draftRequest = ProductQuestionDraftRequest::create([
            'request_id' => '019a-test-draft-request',
            'product_question_id' => $question->id,
            'workflow_key' => 'work/qa-draft-agent-v1',
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'status' => 'queued',
        ]);
        $headers = ['Authorization' => 'Bearer test-n8n-token'];
        $plan = [
            'intents' => ['ask_pockets'],
            'customer_language' => 'en',
            'country_code' => null,
            'event_date' => null,
            'requested_currency' => null,
            'requested_language' => null,
            'requires_private_reply' => false,
            'unmatched_requests' => [],
            'summary' => 'Pocket question',
        ];

        $this->postJson('/api/internal/n8n/draft-requests/019a-test-draft-request/start', [
            'execution_id' => 'n8n-agent-1',
            'workflow_key' => 'work/qa-draft-agent-v1',
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('context.external_question_id', 501)
            ->assertJsonPath('context.reply_style.warm_customer_service_voice', true)
            ->assertJsonPath('context.reply_style.natural_and_polished', true)
            ->assertJsonPath('context.reply_style.avoid_excessive_enthusiasm_and_exclamation_marks', true);
        $facts = $this->postJson('/api/internal/n8n/draft-requests/019a-test-draft-request/facts', [
            'plan' => $plan,
        ], $headers)->assertOk()->assertJsonPath('plan.intents.1', 'request_add_pockets')->json();
        $policies = $this->postJson('/api/internal/n8n/draft-requests/019a-test-draft-request/policies', [
            'intents' => ['ask_pockets'],
        ], $headers)->assertOk()->assertJsonPath('data.0.id', $article->id)->json('data');

        $this->postJson('/api/internal/n8n/draft-requests/019a-test-draft-request/complete', [
            'plan' => $plan,
            'facts' => $facts['facts'],
            'fact_sources' => $facts['sources'],
            'missing_facts' => $facts['missing'],
            'safety_flags' => $facts['safety_flags'],
            'recommended_visible' => $facts['recommended_visible'],
            'policies' => $policies,
            'review' => [
                'approved' => true,
                'must_block' => false,
                'final_answer_en' => 'We need to verify whether this item has pockets.',
                'final_answer_localized' => 'We need to verify whether this item has pockets.',
                'confidence' => 0.97,
                'safety_flags' => [],
            ],
            'usage' => ['planner' => ['total_tokens' => 10]],
        ], $headers)->assertOk()->assertJsonPath('status', 'generated');

        $draftRequest->refresh();
        $this->assertSame('completed', $draftRequest->status);
        $this->assertSame('unanswered', $question->fresh()->reply_status);
        $this->assertNull($question->fresh()->answer);
        $this->assertSame(
            'This dress does not come with pockets by default, but we’d be happy to add them for you at no extra charge. Simply write “Please add pockets” in the Order Notes section at checkout, and our tailors will take care of it.',
            $draftRequest->draft->answer_en,
        );
        $this->assertStringNotContainsString('QA-501', $draftRequest->draft->answer_en);
    }

    public function test_final_validation_replaces_unsupported_custom_size_timing_claims(): void
    {
        $site = $this->site();
        $question = $this->question($site, 502, 'unanswered');
        $question->update(['question' => 'Where do I enter my custom measurements?']);
        CommerceProduct::create([
            'commerce_site_id' => $site->id,
            'sn' => 'QA-502',
            'name' => 'Custom Dress',
            'product_url' => 'https://dressafford.test/custom.html',
            'product' => ['sn' => 'QA-502', 'name' => 'Custom Dress', 'type' => 'dress', 'customizable' => 1],
            'product_detail' => ['attributes' => []],
            'images' => [],
            'options' => ['editable_fields' => ['size'], 'sizes' => ['Custom Size']],
            'payload_hash' => str_repeat('e', 64),
            'sync_status' => 'completed',
            'synced_at' => now(),
        ]);
        KnowledgeArticle::create([
            'key' => 'qa_global_request_custom_size',
            'title' => 'Custom size entry',
            'knowledge_type' => 'policy',
            'status' => 'verified',
            'automation_tier' => 'draft_low_risk',
            'applicability' => 'Measurement entry',
            'required_facts' => [],
            'policy_rules' => [],
            'decision_tree' => [],
            'reply_strategy' => [],
            'reply_templates' => [],
            'action_steps' => [],
            'prohibited_claims' => [],
            'escalation_rules' => [],
            'version' => 1,
        ]);
        $request = ProductQuestionDraftRequest::create([
            'request_id' => '019a-custom-size-request',
            'product_question_id' => $question->id,
            'workflow_key' => 'work/qa-draft-agent-v1',
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'status' => 'running',
            'n8n_execution_id' => 'custom-size-1',
            'started_at' => now(),
        ]);
        $plan = [
            'intents' => ['request_custom_size'], 'customer_language' => 'en',
            'country_code' => null, 'event_date' => null, 'requested_currency' => null,
            'requested_language' => null, 'requires_private_reply' => false,
            'unmatched_requests' => [], 'summary' => 'Measurement entry',
        ];
        $resolved = app(N8nProductQuestionDraftService::class)
            ->resolveFacts($request, $plan);

        $result = app(N8nProductQuestionDraftService::class)->complete($request, [
            'plan' => $plan,
            'facts' => $resolved['facts'],
            'fact_sources' => $resolved['sources'],
            'missing_facts' => $resolved['missing'],
            'safety_flags' => $resolved['safety_flags'],
            'recommended_visible' => true,
            'policies' => [['id' => KnowledgeArticle::firstOrFail()->id]],
            'review' => [
                'approved' => true,
                'must_block' => false,
                'final_answer_en' => 'Enter measurements and production takes 3–5 business days. Contact support.',
                'final_answer_localized' => null,
                'confidence' => 0.8,
                'safety_flags' => [],
            ],
        ]);

        $draft = ProductQuestionDraft::findOrFail($result['draft_id']);
        $this->assertSame('generated', $draft->status);
        $this->assertSame('You can enter your measurements by opening the Size options and selecting Custom Size. Complete the measurement fields that appear, then continue with your order. For your privacy, please don’t post your measurements in this public Q&A.', $draft->answer_en);
        $this->assertStringNotContainsString('business days', $draft->answer_en);
        $this->assertStringNotContainsString('support', strtolower($draft->answer_en));
    }

    public function test_final_validation_blocks_a_generic_contact_referral_not_required_by_policy(): void
    {
        $site = $this->site();
        $question = $this->question($site, 506, 'unanswered');
        $question->update(['question' => 'What does the back of this dress look like?']);
        $policy = KnowledgeArticle::create([
            'key' => 'qa_global_ask_guarantee',
            'title' => 'Image consistency',
            'knowledge_type' => 'policy',
            'status' => 'verified',
            'automation_tier' => 'draft_sensitive',
            'applicability' => 'Product image questions',
            'required_facts' => [],
            'policy_rules' => ['Use only supplied product facts.'],
            'decision_tree' => [],
            'reply_strategy' => [],
            'reply_templates' => [],
            'action_steps' => [],
            'prohibited_claims' => [],
            'escalation_rules' => [],
            'version' => 1,
        ]);
        $request = ProductQuestionDraftRequest::create([
            'request_id' => '019a-generic-contact-request',
            'product_question_id' => $question->id,
            'workflow_key' => N8nProductQuestionDraftService::WORKFLOW_KEY,
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'status' => 'running',
            'n8n_execution_id' => 'generic-contact-1',
            'started_at' => now(),
        ]);
        $service = app(N8nProductQuestionDraftService::class);
        $plan = ['intents' => ['ask_guarantee'], 'customer_language' => 'en'];
        $resolved = $service->resolveFacts($request, $plan);
        $resolved['facts']['product']['name'] = 'Blue Dress';

        $result = $service->complete($request, [
            'plan' => $plan,
            'facts' => $resolved['facts'],
            'fact_sources' => $resolved['sources'],
            'missing_facts' => $resolved['missing'],
            'safety_flags' => $resolved['safety_flags'],
            'recommended_visible' => true,
            'policies' => [['id' => $policy->id, 'policy_rules' => $policy->policy_rules]],
            'review' => [
                'approved' => true,
                'must_block' => false,
                'final_answer_en' => 'The Blue Dress uses a zipper closure. For a closer look, please contact us at support@example.com.',
                'final_answer_localized' => null,
                'confidence' => 0.9,
                'safety_flags' => [],
            ],
        ]);

        $draft = ProductQuestionDraft::findOrFail($result['draft_id']);
        $this->assertSame('blocked', $draft->status);
        $this->assertContains(
            'The answer adds a generic contact referral that is not required by the selected policy.',
            $draft->safety_flags,
        );
        $this->assertContains('The answer unnecessarily repeats the product title or identifier.', $draft->safety_flags);
    }

    public function test_unmatched_request_is_returned_as_a_blocking_draft_instead_of_a_workflow_error(): void
    {
        $site = $this->site();
        $question = $this->question($site, 507, 'unanswered');
        $question->update(['question' => 'Can you provide a video of the back?']);
        $request = ProductQuestionDraftRequest::create([
            'request_id' => '019a-unmatched-request',
            'product_question_id' => $question->id,
            'workflow_key' => N8nProductQuestionDraftService::WORKFLOW_KEY,
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'status' => 'running',
            'n8n_execution_id' => 'unmatched-1',
            'started_at' => now(),
        ]);

        $resolved = app(N8nProductQuestionDraftService::class)->resolveFacts($request, [
            'intents' => [],
            'customer_language' => 'en',
            'unmatched_requests' => ['Can you provide a video of the back?'],
        ]);

        $this->assertSame([], $resolved['plan']['intents']);
        $this->assertFalse($resolved['recommended_visible']);
        $this->assertContains('unsupported_request:Can you provide a video of the back?', $resolved['missing']);

        $this->withToken('test-n8n-token')
            ->postJson('/api/internal/n8n/draft-requests/019a-unmatched-request/policies', ['intents' => []])
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $result = app(N8nProductQuestionDraftService::class)->complete($request, [
            'plan' => $resolved['plan'],
            'facts' => $resolved['facts'],
            'fact_sources' => $resolved['sources'],
            'missing_facts' => $resolved['missing'],
            'safety_flags' => $resolved['safety_flags'],
            'recommended_visible' => false,
            'policies' => [],
            'review' => [
                'approved' => false,
                'must_block' => true,
                'final_answer_en' => 'A back-view video is not available in the supplied product details.',
                'final_answer_localized' => null,
                'confidence' => 0.8,
                'safety_flags' => [],
            ],
        ]);

        $this->assertSame('blocked', ProductQuestionDraft::findOrFail($result['draft_id'])->status);
    }

    public function test_color_customization_question_is_not_treated_as_custom_length(): void
    {
        $site = $this->site();
        $question = $this->question($site, 503, 'unanswered');
        $question->update([
            'question' => 'Kann man das Spitzenkleid auch in der Farbe dunkelgrün oder königsblau anfertigen lassen?',
        ]);
        CommerceProduct::create([
            'commerce_site_id' => $site->id,
            'sn' => 'QA-503',
            'name' => 'Lace Dress',
            'product_url' => 'https://dressafford.test/lace.html',
            'product' => ['sn' => 'QA-503', 'name' => 'Lace Dress', 'type' => 'dress', 'customizable' => 1],
            'product_detail' => ['attributes' => []],
            'images' => [],
            'options' => ['colors' => ['Dark Green', 'Royal Blue']],
            'payload_hash' => str_repeat('f', 64),
            'sync_status' => 'completed',
            'synced_at' => now(),
        ]);
        foreach (['request_custom_color', 'request_custom_length'] as $intent) {
            KnowledgeArticle::create([
                'key' => 'qa_global_'.$intent,
                'title' => $intent,
                'knowledge_type' => 'policy',
                'status' => 'verified',
                'automation_tier' => 'draft_low_risk',
                'applicability' => 'Customization',
                'required_facts' => [],
                'policy_rules' => [],
                'decision_tree' => [],
                'reply_strategy' => [],
                'reply_templates' => [],
                'action_steps' => [],
                'prohibited_claims' => [],
                'escalation_rules' => [],
                'version' => 1,
            ]);
        }
        $request = ProductQuestionDraftRequest::create([
            'request_id' => '019a-custom-color-request',
            'product_question_id' => $question->id,
            'workflow_key' => 'work/qa-draft-agent-v1',
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'status' => 'running',
            'n8n_execution_id' => 'custom-color-1',
            'started_at' => now(),
        ]);

        $resolved = app(N8nProductQuestionDraftService::class)
            ->resolveFacts($request, [
                'intents' => ['request_custom_length'],
                'customer_language' => 'de',
            ]);

        $this->assertSame(['request_custom_color'], $resolved['plan']['intents']);
        $this->assertSame('de', $resolved['plan']['customer_language']);
        $this->assertSame(['Dark Green', 'Royal Blue'], data_get($resolved, 'facts.product.colors'));
        $this->assertNotContains('current_product_snapshot', $resolved['missing']);
    }

    public function test_order_status_question_never_sends_private_order_facts_to_ai(): void
    {
        $site = $this->site();
        $question = $this->question($site, 504, 'unanswered');
        $question->update(['question' => 'Where is my order DA12345678?']);
        KnowledgeArticle::create([
            'key' => 'qa_global_ask_order_status',
            'title' => 'Order status',
            'knowledge_type' => 'policy',
            'status' => 'verified',
            'automation_tier' => 'draft_sensitive',
            'applicability' => 'Existing orders',
            'required_facts' => [],
            'policy_rules' => [],
            'decision_tree' => [],
            'reply_strategy' => [],
            'reply_templates' => [],
            'action_steps' => [],
            'prohibited_claims' => [],
            'escalation_rules' => [],
            'version' => 1,
        ]);
        $request = ProductQuestionDraftRequest::create([
            'request_id' => '019a-order-status-request',
            'product_question_id' => $question->id,
            'workflow_key' => 'work/qa-draft-agent-v1',
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'status' => 'running',
            'n8n_execution_id' => 'order-status-1',
            'started_at' => now(),
        ]);
        $service = app(N8nProductQuestionDraftService::class);
        $plan = ['intents' => ['ask_order_status'], 'customer_language' => 'en', 'requires_private_reply' => true];
        $resolved = $service->resolveFacts($request, $plan);

        $this->assertFalse($resolved['recommended_visible']);
        $this->assertContains('private_order_lookup', $resolved['missing']);
        $this->assertArrayNotHasKey('order', $resolved['facts']);

        $result = $service->complete($request, [
            'plan' => $plan,
            'facts' => $resolved['facts'],
            'fact_sources' => $resolved['sources'],
            'missing_facts' => $resolved['missing'],
            'safety_flags' => $resolved['safety_flags'],
            'recommended_visible' => $resolved['recommended_visible'],
            'policies' => [['id' => KnowledgeArticle::firstOrFail()->id]],
            'review' => [
                'approved' => true,
                'must_block' => false,
                'final_answer_en' => 'Your order is in production.',
                'final_answer_localized' => null,
                'confidence' => 0.9,
                'safety_flags' => [],
            ],
        ]);

        $draft = ProductQuestionDraft::findOrFail($result['draft_id']);
        $this->assertSame('blocked', $draft->status);
        $this->assertFalse($draft->recommended_visible);
        $this->assertContains('private_order_lookup', $draft->missing_facts);
    }

    public function test_petite_size_question_uses_standard_us_size_and_custom_size_policy(): void
    {
        $site = $this->site();
        $question = $this->question($site, 505, 'unanswered');
        $question->update(['question' => 'I’m looking for wedding dress for petite size 4']);
        CommerceProduct::create([
            'commerce_site_id' => $site->id,
            'sn' => 'QA-505',
            'name' => 'Wedding Dress Title That Should Not Be Repeated',
            'product_url' => 'https://dressafford.test/petite.html',
            'product' => ['sn' => 'QA-505', 'name' => 'Wedding Dress Title That Should Not Be Repeated', 'type' => 'dress', 'customizable' => 1],
            'product_detail' => ['attributes' => []],
            'images' => [],
            'options' => [
                'sizes' => ['2', '4', '6'],
                'size_chart' => ['4' => ['bust' => '33.5', 'waist' => '26.5', 'hips' => '36.75', 'hollow_to_floor' => '58']],
            ],
            'payload_hash' => str_repeat('9', 64),
            'sync_status' => 'completed',
            'synced_at' => now(),
        ]);
        $articles = collect(['ask_size_recommendation', 'request_custom_size'])->map(function (string $intent): KnowledgeArticle {
            return KnowledgeArticle::create([
                'key' => 'qa_global_'.$intent,
                'title' => $intent,
                'knowledge_type' => 'policy',
                'status' => 'verified',
                'automation_tier' => 'draft_low_risk',
                'applicability' => 'Size guidance',
                'required_facts' => [],
                'policy_rules' => [],
                'decision_tree' => [],
                'reply_strategy' => [],
                'reply_templates' => [],
                'action_steps' => [],
                'prohibited_claims' => [],
                'escalation_rules' => [],
                'version' => 1,
            ]);
        });
        $request = ProductQuestionDraftRequest::create([
            'request_id' => '019a-petite-size-request',
            'product_question_id' => $question->id,
            'workflow_key' => 'work/qa-draft-agent-v1',
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'status' => 'running',
            'n8n_execution_id' => 'petite-size-1',
            'started_at' => now(),
        ]);
        $service = app(N8nProductQuestionDraftService::class);
        $plan = ['intents' => ['ask_plus_size'], 'customer_language' => 'en'];
        $resolved = $service->resolveFacts($request, $plan);

        $this->assertSame(['ask_size_recommendation', 'request_custom_size'], $resolved['plan']['intents']);
        $this->assertSame(['2', '4', '6'], data_get($resolved, 'facts.product.sizes'));
        $this->assertTrue(data_get($resolved, 'facts.product.custom_size_input_available'));

        $result = $service->complete($request, [
            'plan' => $plan,
            'facts' => $resolved['facts'],
            'fact_sources' => $resolved['sources'],
            'missing_facts' => $resolved['missing'],
            'safety_flags' => $resolved['safety_flags'],
            'recommended_visible' => true,
            'policies' => $articles->map(fn (KnowledgeArticle $article): array => ['id' => $article->id])->all(),
            'review' => [
                'approved' => true,
                'must_block' => false,
                'final_answer_en' => 'Size 4 should fit you perfectly.',
                'final_answer_localized' => null,
                'confidence' => 0.9,
                'safety_flags' => [],
            ],
        ]);

        $draft = ProductQuestionDraft::findOrFail($result['draft_id']);
        $this->assertSame('generated', $draft->status);
        $this->assertSame(
            'While we don’t offer a separate petite Size 4, this dress is available in standard US Size 4. We recommend comparing your bust, waist, hips, and hollow-to-floor measurements with our Size Chart. If you’d prefer a closer fit for petite proportions, you’re welcome to choose Custom Size and enter your exact measurements. Please note that custom-size dresses cannot be returned or exchanged.',
            $draft->answer_en,
        );
        $this->assertStringNotContainsString('QA-505', $draft->answer_en);
        $this->assertStringNotContainsString('Wedding Dress Title', $draft->answer_en);
    }

    public function test_lining_and_length_question_uses_current_product_and_verified_hollow_to_hem_chart(): void
    {
        $site = $this->site();
        $question = $this->question($site, 889, 'unanswered');
        $question->update([
            'sn' => '356469',
            'question' => 'Is this dress lined. What is the length from shoulder to hem in a size 2.',
        ]);
        CommerceProduct::create([
            'commerce_site_id' => $site->id,
            'sn' => '356469',
            'name' => 'Product Title That Should Not Be Repeated',
            'product_url' => 'https://dressafford.test/product.html',
            'product' => ['sn' => '356469', 'name' => 'Product Title That Should Not Be Repeated', 'type' => 'dress'],
            'product_detail' => ['attributes' => [
                'field_fullylined' => 'Yes',
                'field_hemlinetrain' => ['Short/Mini'],
            ]],
            'images' => [],
            'options' => ['sizes' => ['2', '4']],
            'payload_hash' => str_repeat('8', 64),
            'sync_status' => 'completed',
            'synced_at' => now(),
        ]);
        $request = ProductQuestionDraftRequest::create([
            'request_id' => '019a-dress-length-request',
            'product_question_id' => $question->id,
            'workflow_key' => 'work/qa-draft-agent-v1',
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'status' => 'running',
            'n8n_execution_id' => 'dress-length-1',
            'started_at' => now(),
        ]);
        $service = app(N8nProductQuestionDraftService::class);
        $plan = [
            'intents' => [
                'ask_lining' => 'Customer asks whether the dress is lined.',
                'ask_dress_length' => 'Customer asks for the shoulder-to-hem length in size 2.',
            ],
            'customer_language' => 'en',
        ];
        $resolved = $service->resolveFacts($request, $plan);
        $plan = $resolved['plan'];

        $this->assertSame(['ask_lining', 'ask_dress_length'], $plan['intents']);
        $this->assertSame(82, data_get($resolved, 'facts.product.dress_length.value_cm'));
        $this->assertSame(32.3, data_get($resolved, 'facts.product.dress_length.value_in'));
        $this->assertSame('hollow_to_hem', data_get($resolved, 'facts.product.dress_length.measurement'));
        $this->assertSame('Yes', data_get($resolved, 'facts.product.attributes.field_fullylined'));
        $this->assertSame([], $resolved['missing']);

        $articles = KnowledgeArticle::query()
            ->whereIn('key', ['qa_global_ask_lining', 'qa_global_ask_dress_length'])
            ->get();
        $result = $service->complete($request, [
            'plan' => $plan,
            'facts' => $resolved['facts'],
            'fact_sources' => $resolved['sources'],
            'missing_facts' => $resolved['missing'],
            'safety_flags' => $resolved['safety_flags'],
            'recommended_visible' => true,
            'policies' => $articles->map(fn (KnowledgeArticle $article): array => ['id' => $article->id])->all(),
            'review' => [
                'approved' => true,
                'must_block' => false,
                'final_answer_en' => 'It is 82 cm from the shoulder.',
                'final_answer_localized' => null,
                'confidence' => 0.9,
                'safety_flags' => [],
            ],
        ]);

        $draft = ProductQuestionDraft::findOrFail($result['draft_id']);
        $this->assertSame('generated', $draft->status);
        $this->assertSame(
            'Yes, this dress is fully lined. For standard US Size 2, the Short/Mini hollow-to-hem measurement is approximately 82 cm (32.3 in). Just a quick note: this is measured from the hollow at the center base of the neck rather than from the shoulder.',
            $draft->answer_en,
        );
        $this->assertStringNotContainsString('356469', $draft->answer_en);
        $this->assertStringNotContainsString('Product Title', $draft->answer_en);
    }

    public function test_failed_draft_request_truncates_an_oversized_workflow_error(): void
    {
        $question = $this->question($this->site(), 890, 'unanswered');
        ProductQuestionDraftRequest::create([
            'request_id' => '019a-oversized-error-request',
            'product_question_id' => $question->id,
            'workflow_key' => 'work/qa-draft-agent-v1',
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'status' => 'running',
            'n8n_execution_id' => 'oversized-error-1',
            'started_at' => now(),
        ]);

        $this->postJson('/api/internal/n8n/draft-requests/019a-oversized-error-request/fail', [
            'error_message' => str_repeat('x', 6000),
        ], ['Authorization' => 'Bearer test-n8n-token'])
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        $request = ProductQuestionDraftRequest::where('request_id', '019a-oversized-error-request')->firstOrFail();
        $this->assertSame('failed', $request->status);
        $this->assertSame(5000, mb_strlen($request->error_message));
    }

    private function site(): CommerceSite
    {
        return CommerceSite::create([
            'name' => 'DressAfford',
            'site_key' => 'dressafford',
            'admin_api_url' => 'https://dressafford.test/api/admin',
            'api_token' => 'secret',
            'enabled' => true,
        ]);
    }

    private function question(CommerceSite $site, int $externalId, string $replyStatus)
    {
        return app(ProductQuestionSyncService::class)->storeQuestion($site, [
            'id' => $externalId,
            'sn' => 'QA-'.$externalId,
            'question' => 'Does this item have pockets?',
            'answer' => $replyStatus === 'answered' ? 'Yes.' : null,
            'reply_status' => $replyStatus,
            'visible' => true,
            'status' => 1,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    private function analysis(int $questionId, string $reviewStatus, string $hashCharacter): array
    {
        return [
            'product_question_id' => $questionId,
            'primary_category' => 'product_details',
            'secondary_categories' => [],
            'customer_intent' => 'Ask whether the item has pockets.',
            'question_scope' => 'product_specific',
            'answerability' => 'direct',
            'risk_level' => 'low',
            'required_facts' => [],
            'missing_facts' => [],
            'response_moves' => ['direct_conclusion'],
            'reply_pattern' => 'direct_answer',
            'answer_assessment' => [],
            'automation_recommendation' => 'human_review',
            'confidence' => 0.9,
            'analysis_summary' => 'Test analysis.',
            'review_status' => $reviewStatus,
            'analyzer_version' => 'test',
            'input_hash' => str_repeat($hashCharacter, 64),
        ];
    }
}
