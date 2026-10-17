<?php

namespace Tests\Feature;

use App\Models\CommerceSite;
use App\Models\KnowledgeArticle;
use App\Models\ProductQuestionAnalysis;
use App\Models\ProductQuestionPolicyCandidate;
use App\Models\ProductQuestionPolicyRun;
use App\Services\ProductQuestions\ProductQuestionPolicyPrompt;
use App\Services\ProductQuestions\ProductQuestionPolicySynthesizer;
use App\Services\TradeManager\ProductQuestionSyncService;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductQuestionPolicyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.minimax.api_key' => 'test-key',
            'services.minimax.base_url' => 'https://minimax.test/v1',
            'services.minimax.model' => 'MiniMax-Test',
        ]);
    }

    public function test_policy_prompt_requires_site_placeholders_and_canonical_english_templates(): void
    {
        $prompt = app(ProductQuestionPolicyPrompt::class)->system();

        $this->assertStringContainsString('{{ site.public_base_url }}', $prompt);
        $this->assertStringContainsString('{{ site.customer_service_email }}', $prompt);
        $this->assertStringContainsString('{{ site.brand_name }}', $prompt);
        $this->assertStringContainsString('canonical English template', $prompt);
        $this->assertStringContainsString("translate that completed response into the customer's language", $prompt);
        $this->assertStringContainsString('Do not create mixed-language or per-language policy templates', $prompt);
    }

    public function test_synthesizer_groups_analyses_into_auditable_policy_candidate(): void
    {
        $site = $this->site();
        $first = $this->analysis($site, 101, 'Can I order this dress in red?');
        $second = $this->analysis($site, 102, 'Is burgundy available?');
        $run = $this->policyRun($site);
        Http::fakeSequence('minimax.test/*')
            ->push([
                'id' => 'invalid-policy-completion',
                'choices' => [['message' => ['content' => '{invalid json']]],
                'usage' => [],
            ])
            ->push([
                'id' => 'policy-completion',
                'choices' => [['message' => ['content' => json_encode([
                    'strategies' => [$this->strategy([$first->product_question_id, $second->product_question_id])],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]],
                'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 100, 'total_tokens' => 300],
            ]);

        $result = app(ProductQuestionPolicySynthesizer::class)->synthesize($run->load('site'), collect([$first, $second]));

        $this->assertSame(1, $result['candidates']);
        $candidate = ProductQuestionPolicyCandidate::firstOrFail();
        $this->assertSame('ask_available_colors', $candidate->scenario_key);
        $this->assertSame('candidate', $candidate->review_status);
        $this->assertEqualsCanonicalizing([$first->product_question_id, $second->product_question_id], $candidate->evidence_question_ids);
        $this->assertSame(['核实当前产品可选颜色'], $candidate->required_facts);
        $this->assertSame(300, $result['usage']['total_tokens']);
        Http::assertSentCount(2);
    }

    public function test_confirming_one_grouped_candidate_publishes_verified_knowledge_with_evidence(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $site = $this->site();
        $first = $this->analysis($site, 101, 'Can I order this dress in red?');
        $second = $this->analysis($site, 102, 'Is burgundy available?');
        $run = $this->policyRun($site);
        $candidate = ProductQuestionPolicyCandidate::create([
            'product_question_policy_run_id' => $run->id,
            'commerce_site_id' => $site->id,
            ...$this->strategy([$first->product_question_id, $second->product_question_id]),
            'open_questions' => [],
            'review_status' => 'candidate',
        ]);

        $this->from("/admin/product-question-policies/{$candidate->id}")
            ->post("/admin/product-question-policies/{$candidate->id}/review", [
                'review_status' => 'confirmed',
                'review_notes' => '业务确认颜色回复规则。',
            ])->assertRedirect("/admin/product-question-policies/{$candidate->id}");

        $article = KnowledgeArticle::where('key', 'qa_dorriswedding_ask_available_colors')->firstOrFail();
        $this->assertSame('verified', $article->status);
        $this->assertSame('dorriswedding', $article->site_key);
        $this->assertSame('业务确认颜色回复规则。', $candidate->fresh()->review_notes);
        $this->assertSame(2, DB::table('product_question_strategy_evidence')->count());

        $this->post("/admin/product-question-policies/{$candidate->id}/review", [
            'review_status' => 'confirmed',
        ])->assertRedirect();
        $this->assertSame(1, $article->fresh()->version);
    }

    public function test_candidate_with_conflicts_or_open_questions_cannot_be_published_as_verified(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $site = $this->site();
        $analysis = $this->analysis($site, 104, 'Can you guarantee this?');
        $run = $this->policyRun($site);
        $candidate = ProductQuestionPolicyCandidate::create([
            'product_question_policy_run_id' => $run->id,
            'commerce_site_id' => $site->id,
            ...$this->strategy([$analysis->product_question_id], 'ask_guarantee'),
            'conflicts' => ['历史说法不一致'],
            'open_questions' => ['保证范围尚未确认'],
            'review_status' => 'candidate',
        ]);

        $this->from("/admin/product-question-policies/{$candidate->id}")
            ->post("/admin/product-question-policies/{$candidate->id}/review", [
                'review_status' => 'confirmed',
            ])
            ->assertRedirect("/admin/product-question-policies/{$candidate->id}")
            ->assertSessionHasErrors('review_status');

        $this->assertSame('candidate', $candidate->fresh()->review_status);
        $this->assertDatabaseMissing('knowledge_articles', ['key' => 'qa_dorriswedding_ask_guarantee']);
    }

    public function test_confirming_global_candidate_publishes_cross_site_verified_knowledge(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $site = $this->site();
        $analysis = $this->analysis($site, 103, 'Do you ship to Canada?');
        $run = $this->policyRun($site);
        $candidate = ProductQuestionPolicyCandidate::create([
            'product_question_policy_run_id' => $run->id,
            'commerce_site_id' => $site->id,
            ...$this->strategy([$analysis->product_question_id], 'ask_shipping_options'),
            'open_questions' => [],
            'scope' => 'site',
            'review_status' => 'candidate',
        ]);

        $this->post("/admin/product-question-policies/{$candidate->id}/review", [
            'review_status' => 'confirmed',
            'scope' => 'global',
            'review_notes' => '所有站点使用当前站点域名下的 shipping-info.html。',
        ])->assertRedirect();

        $article = KnowledgeArticle::where('key', 'qa_global_ask_shipping_options')->firstOrFail();
        $this->assertNull($article->site_key);
        $this->assertSame('verified', $article->status);
        $this->assertSame('global', $candidate->fresh()->scope);
        $this->assertStringContainsString('证据站点 dorriswedding', $article->source_notes);
    }

    public function test_policy_index_hides_superseded_experimental_prompt_versions_by_default(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $site = $this->site();
        $oldRun = $this->policyRun($site);
        $oldRun->update(['status' => 'completed']);
        ProductQuestionPolicyCandidate::create([
            'product_question_policy_run_id' => $oldRun->id,
            'commerce_site_id' => $site->id,
            ...$this->strategy([1]),
            'title' => '旧试验候选',
        ]);
        ProductQuestionPolicyCandidate::create([
            'product_question_policy_run_id' => $oldRun->id,
            'commerce_site_id' => $site->id,
            ...$this->strategy([1], 'ask_size_recommendation'),
            'title' => '旧版本已确认策略',
            'review_status' => 'confirmed',
        ]);
        $reviewableRun = $oldRun->replicate()->fill([
            'batch_key' => 'reviewable-v3-policy-test',
            'prompt_version' => 'product-question-policy-research-v3',
        ]);
        $reviewableRun->save();
        ProductQuestionPolicyCandidate::create([
            'product_question_policy_run_id' => $reviewableRun->id,
            'commerce_site_id' => $site->id,
            ...$this->strategy([1]),
            'title' => '上一稳定版待审核策略',
        ]);
        $currentRun = $oldRun->replicate()->fill([
            'batch_key' => 'current-policy-test',
            'prompt_version' => ProductQuestionPolicyPrompt::VERSION,
        ]);
        $currentRun->save();
        ProductQuestionPolicyCandidate::create([
            'product_question_policy_run_id' => $currentRun->id,
            'commerce_site_id' => $site->id,
            ...$this->strategy([1]),
            'title' => '当前候选',
        ]);

        $this->get('/admin/product-question-policies')
            ->assertOk()
            ->assertSee('当前候选')
            ->assertSee('上一稳定版待审核策略')
            ->assertSee('旧版本已确认策略')
            ->assertDontSee('旧试验候选');
        $this->get('/admin/product-question-policies?all_versions=1')
            ->assertOk()
            ->assertSee('当前候选')
            ->assertSee('旧试验候选');
    }

    public function test_policy_research_uses_balanced_existing_policy_analyses_without_reanalyzing_questions(): void
    {
        $site = $this->site();
        $deliveryOne = $this->analysis($site, 201, 'How long does delivery take?');
        $deliveryTwo = $this->analysis($site, 202, 'When will this arrive?');
        $return = $this->analysis($site, 203, 'What is your return policy?');
        $deliveryOne->update(['intent_key' => 'ask_delivery_time', 'work_queue' => 'policy_review']);
        $deliveryTwo->update(['intent_key' => 'ask_delivery_time', 'work_queue' => 'policy_review']);
        $return->update(['intent_key' => 'ask_return_policy', 'work_queue' => 'policy_review']);
        $selectedDelivery = collect([$deliveryOne, $deliveryTwo])
            ->sortBy(fn (ProductQuestionAnalysis $analysis): string => hash('sha256', 'balanced-test:'.$analysis->product_question_id))
            ->firstOrFail();
        Http::fakeSequence('minimax.test/*')
            ->push($this->policyCompletion($this->strategy([$selectedDelivery->product_question_id], 'ask_delivery_time')))
            ->push($this->policyCompletion($this->strategy([$return->product_question_id], 'ask_return_policy')));

        $exit = Artisan::call('product-questions:research-policies', [
            '--site' => $site->site_key,
            '--since-days' => 150,
            '--limit' => 10,
            '--per-intent' => 1,
            '--seed' => 'balanced-test',
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $run = ProductQuestionPolicyRun::latest('id')->firstOrFail();
        $this->assertSame(2, $run->selected_count);
        $this->assertSame(2, $run->analyzed_count);
        $this->assertSame(0, $run->failed_count);
        $this->assertSame(2, $run->candidate_count);
        $this->assertDatabaseCount('product_question_analysis_runs', 0);
        Http::assertSentCount(2);
    }

    public function test_policy_research_can_target_one_controlled_intent(): void
    {
        $site = $this->site();
        $delivery = $this->analysis($site, 204, 'How long does delivery take?');
        $return = $this->analysis($site, 205, 'What is your return policy?');
        $delivery->update(['intent_key' => 'ask_delivery_time', 'work_queue' => 'policy_review']);
        $return->update(['intent_key' => 'ask_return_policy', 'work_queue' => 'policy_review']);
        Http::fake(['minimax.test/*' => Http::response(
            $this->policyCompletion($this->strategy([$return->product_question_id], 'ask_return_policy')),
            200,
        )]);

        $exit = Artisan::call('product-questions:research-policies', [
            '--site' => $site->site_key,
            '--intent' => 'ask_return_policy',
            '--batch' => 'single-intent-test',
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $run = ProductQuestionPolicyRun::where('batch_key', 'single-intent-test')->firstOrFail();
        $this->assertSame(1, $run->selected_count);
        $this->assertSame('ask_return_policy', $run->candidates()->firstOrFail()->scenario_key);
        Http::assertSentCount(1);
    }

    private function site(): CommerceSite
    {
        return CommerceSite::create([
            'name' => 'Dorriswedding',
            'site_key' => 'dorriswedding',
            'admin_api_url' => 'https://dorris.test/api/admin',
            'api_token' => 'secret',
            'enabled' => true,
        ]);
    }

    private function analysis(CommerceSite $site, int $externalId, string $text): ProductQuestionAnalysis
    {
        $question = app(ProductQuestionSyncService::class)->storeQuestion($site, [
            'id' => $externalId,
            'sn' => 'DW100',
            'product' => ['name' => 'Wedding Dress'],
            'question' => $text,
            'answer' => 'Please choose from the colors shown on the product page.',
            'reply_status' => 'answered',
            'type' => 1,
            'type_text' => 'User question',
            'visible' => true,
            'images' => [],
            'created_at' => '2026-10-01 10:00:00',
        ]);

        return ProductQuestionAnalysis::create([
            'product_question_id' => $question->id,
            'primary_category' => 'color_material',
            'secondary_categories' => [],
            'intent_key' => 'ask_available_colors',
            'answer_source' => 'verified_policy',
            'work_queue' => 'policy_review',
            'customer_intent' => '询问可选颜色',
            'question_scope' => 'product_specific',
            'answerability' => 'needs_business_facts',
            'risk_level' => 'low',
            'required_facts' => ['当前产品可选颜色'],
            'missing_facts' => [],
            'response_moves' => ['provide_facts'],
            'reply_pattern' => 'direct_answer',
            'answer_summary' => '引导客户选择产品页颜色。',
            'answer_assessment' => [
                'relevance' => 'good', 'completeness' => 'partial', 'clarity' => 'good',
                'consistency' => 'good', 'policy_safety' => 'good', 'strengths' => [], 'gaps' => [],
                'prohibited_or_risky_claims' => [],
            ],
            'automation_recommendation' => 'human_review',
            'confidence' => 0.9,
            'analysis_summary' => '需要读取商品颜色。',
            'review_status' => 'candidate',
            'analyzer_version' => 'test',
            'input_hash' => hash('sha256', $text),
        ]);
    }

    private function policyRun(CommerceSite $site): ProductQuestionPolicyRun
    {
        return ProductQuestionPolicyRun::create([
            'commerce_site_id' => $site->id,
            'batch_key' => 'policy-test',
            'window_start' => now()->subDays(150),
            'window_end' => now(),
            'sample_limit' => 30,
            'selection_seed' => 'test',
            'source_assumption' => 'Recent answers are mostly AI-generated.',
            'status' => 'running',
            'provider' => 'minimax',
            'model' => 'MiniMax-Test',
            'prompt_version' => 'test',
            'started_at' => now(),
        ]);
    }

    /** @param list<int> $ids
     * @return array<string, mixed>
     */
    private function strategy(array $ids, string $scenarioKey = 'ask_available_colors'): array
    {
        return [
            'scenario_key' => $scenarioKey,
            'category' => 'color_material',
            'title' => '可选颜色询问',
            'definition' => '客户询问商品是否支持指定颜色。',
            'applicability' => '适用于产品颜色询问；不适用于定制染色承诺。',
            'required_facts' => ['核实当前产品可选颜色'],
            'policy_rules' => ['历史回复通常引导客户选择产品页现有颜色，仍需业务确认。'],
            'decision_tree' => [['condition' => '颜色在当前产品选项中', 'outcome' => '确认可以选择']],
            'reply_strategy' => [['stage' => 'answer', 'goal' => '明确可选范围', 'content_requirements' => ['引用当前产品颜色']]],
            'reply_templates' => [['conditions' => ['颜色已在产品选项中'], 'body' => 'Yes, {{ color }} is currently available for this dress.']],
            'action_steps' => ['读取最新产品颜色选项'],
            'prohibited_claims' => ['不得承诺产品选项以外的颜色'],
            'escalation_rules' => ['客户要求产品页以外的颜色时转人工'],
            'evidence_question_ids' => $ids,
            'conflicts' => [],
            'open_questions' => ['是否允许特殊染色'],
            'confidence' => 0.86,
        ];
    }

    /** @param array<string, mixed> $strategy */
    private function policyCompletion(array $strategy): array
    {
        return [
            'id' => 'policy-completion',
            'choices' => [['message' => ['content' => json_encode(
                ['strategies' => [$strategy]],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
        ];
    }

    private function administrator(): Administrator
    {
        $administrator = Administrator::create([
            'username' => 'policy-admin',
            'password' => Hash::make('secret'),
            'name' => 'Policy Admin',
        ]);
        $administrator->roles()->attach(Role::where('slug', 'administrator')->firstOrFail());

        return $administrator;
    }
}
