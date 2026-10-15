<?php

namespace Tests\Feature;

use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnalysis;
use App\Models\ProductQuestionAnalysisRun;
use App\Services\ProductQuestions\ProductQuestionAnalyzer;
use App\Services\ProductQuestions\ProductQuestionBatchAnalyzer;
use App\Services\TradeManager\ProductQuestionSyncService;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductQuestionAnalysisWorkflowTest extends TestCase
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

    public function test_analyzer_persists_structured_candidate_and_auditable_run_without_pii(): void
    {
        $question = $this->question();
        Http::fake(['minimax.test/*' => Http::response($this->completion($this->validOutput()), 200)]);

        $result = app(ProductQuestionAnalyzer::class)->analyze($question);

        $this->assertSame('completed', $result['status']);
        $analysis = ProductQuestionAnalysis::firstOrFail();
        $this->assertSame('shipping_delivery', $analysis->primary_category);
        $this->assertSame('candidate', $analysis->review_status);
        $this->assertSame(['配送目的地', '产品制作时间'], $analysis->required_facts);
        $this->assertSame('human_review', $analysis->automation_recommendation);
        $this->assertDatabaseHas('product_question_analysis_runs', [
            'product_question_id' => $question->id,
            'status' => 'completed',
            'prompt_version' => 'product-question-analysis-v2',
        ]);
        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return str_contains($body, 'When will this ship?')
                && str_contains($body, 'Usually within 7 days.')
                && ! str_contains($body, 'customer@example.com')
                && ! str_contains($body, 'Customer Name');
        });
    }

    public function test_analyzer_retries_invalid_auto_candidate_and_accepts_corrected_output(): void
    {
        $question = $this->question();
        $invalid = $this->validOutput([
            'risk_level' => 'high',
            'automation_recommendation' => 'auto_candidate',
        ]);
        Http::fakeSequence('minimax.test/*')
            ->push($this->completion($invalid), 200)
            ->push($this->completion($this->validOutput()), 200);

        $result = app(ProductQuestionAnalyzer::class)->analyze($question);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, ProductQuestionAnalysisRun::firstOrFail()->usage['validation_retries']);
        Http::assertSentCount(2);
    }

    public function test_reviewed_analysis_is_never_overwritten_even_with_force(): void
    {
        $question = $this->question();
        Http::fake(['minimax.test/*' => Http::response($this->completion($this->validOutput()), 200)]);
        app(ProductQuestionAnalyzer::class)->analyze($question);
        $analysis = ProductQuestionAnalysis::firstOrFail();
        $analysis->update(['review_status' => 'corrected', 'primary_category' => 'production_time']);

        $result = app(ProductQuestionAnalyzer::class)->analyze($question->fresh(), true);

        $this->assertSame('skipped_reviewed', $result['status']);
        $this->assertSame('production_time', $analysis->fresh()->primary_category);
        Http::assertSentCount(1);
    }

    public function test_command_selects_only_requested_site_and_reply_status(): void
    {
        $site = $this->site();
        $this->question();
        $this->question(['id' => 121, 'answer' => null, 'reply_status' => 'unanswered']);
        Http::fake(['minimax.test/*' => Http::response($this->completion($this->validOutput()), 200)]);

        $exit = Artisan::call('product-questions:analyze', [
            '--site' => $site->site_key,
            '--limit' => 5,
            '--reply-status' => 'answered',
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(1, ProductQuestionAnalysis::count());
        $this->assertSame(1, ProductQuestionAnalysisRun::where('status', 'completed')->count());
    }

    public function test_batch_analyzer_classifies_multiple_questions_in_one_minimax_request(): void
    {
        $first = $this->question();
        $second = $this->question(['id' => 121, 'question' => 'Can you add pockets?', 'answer' => 'Please leave a note.']);
        $firstOutput = ['record_id' => $first->id, ...$this->validOutput()];
        $secondOutput = ['record_id' => $second->id, ...$this->validOutput([
            'primary_category' => 'customization',
            'secondary_categories' => [],
            'intent_key' => 'request_add_pockets',
            'customer_intent' => '客户要求增加口袋',
            'question_scope' => 'mixed',
            'answer_source' => 'factory_capability',
            'work_queue' => 'factory_review',
            'answerability' => 'needs_business_facts',
            'required_facts' => ['款式是否支持口袋', '费用', '制作周期影响'],
            'missing_facts' => ['适用款式与例外'],
            'factory_capability_key' => 'add_pockets',
            'factory_question' => '哪些款式可以增加口袋，是否收费并影响制作周期？',
            'automation_recommendation' => 'human_review',
        ])];
        Http::fake(['minimax.test/*' => Http::response($this->completion(['items' => [$firstOutput, $secondOutput]]), 200)]);

        $result = app(ProductQuestionBatchAnalyzer::class)->analyze(collect([$first, $second]));

        $this->assertSame(2, collect($result['results'])->where('status', 'completed')->count());
        $this->assertSame('factory_review', $second->analysis()->firstOrFail()->work_queue);
        $this->assertSame('add_pockets', $second->analysis()->firstOrFail()->factory_capability_key);
        $this->assertSame(2, ProductQuestionAnalysisRun::where('status', 'completed')->count());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Can you add pockets?')
            && ! str_contains($request->body(), 'customer@example.com'));
    }

    public function test_batch_analyzer_normalizes_product_fact_and_customization_routes(): void
    {
        $weight = $this->question(['id' => 130, 'question' => 'How much does this dress weigh?']);
        $custom = $this->question(['id' => 131, 'question' => 'Can this be shortened?']);
        Http::fake(['minimax.test/*' => Http::response($this->completion(['items' => [
            ['record_id' => $weight->id, ...$this->routingOutput([
                'intent_key' => 'ask_weight',
                'work_queue' => 'factory_review',
                'answer_source' => 'factory_capability',
                'factory_capability_key' => 'other_customization',
                'factory_question' => '重量是多少？',
            ])],
            ['record_id' => $custom->id, ...$this->routingOutput([
                'intent_key' => 'request_custom_length',
                'work_queue' => 'customer_clarification',
                'answer_source' => 'customer_input',
            ])],
        ]]), 200)]);

        app(ProductQuestionBatchAnalyzer::class)->analyze(collect([$weight, $custom]));

        $this->assertSame('catalog_data_missing', $weight->analysis()->firstOrFail()->work_queue);
        $this->assertNull($weight->analysis()->firstOrFail()->factory_capability_key);
        $this->assertSame('factory_review', $custom->analysis()->firstOrFail()->work_queue);
        $this->assertSame('custom_length', $custom->analysis()->firstOrFail()->factory_capability_key);
    }

    public function test_batch_analyzer_does_not_treat_merchant_product_photos_as_customer_clarification(): void
    {
        $question = $this->question(['id' => 132, 'question' => 'Can you show me a real photo?']);
        Http::fake(['minimax.test/*' => Http::response($this->completion(['items' => [[
            'record_id' => $question->id,
            ...$this->routingOutput([
                'intent_key' => 'ask_product_photos',
                'work_queue' => 'customer_clarification',
                'answer_source' => 'customer_input',
            ]),
        ]]]), 200)]);

        app(ProductQuestionBatchAnalyzer::class)->analyze(collect([$question]));

        $analysis = $question->analysis()->firstOrFail();
        $this->assertSame('catalog_data_missing', $analysis->work_queue);
        $this->assertSame('product_catalog', $analysis->answer_source);
    }

    public function test_batch_analyzer_routes_multi_intent_to_human_review(): void
    {
        $question = $this->question(['id' => 133, 'question' => 'Can I return it and what colors are available?']);
        Http::fake(['minimax.test/*' => Http::response($this->completion(['items' => [[
            'record_id' => $question->id,
            ...$this->routingOutput([
                'intent_key' => 'unclear_or_multi_intent',
                'work_queue' => 'factory_review',
                'answer_source' => 'factory_capability',
                'factory_capability_key' => 'other_customization',
                'factory_question' => '是否可行？',
            ]),
        ]]]), 200)]);

        app(ProductQuestionBatchAnalyzer::class)->analyze(collect([$question]));

        $analysis = $question->analysis()->firstOrFail();
        $this->assertSame('human_review', $analysis->work_queue);
        $this->assertSame('human_judgment', $analysis->answer_source);
        $this->assertNull($analysis->factory_capability_key);
    }

    public function test_batch_analyzer_routes_spam_to_human_review(): void
    {
        $question = $this->question(['id' => 137, 'question' => 'Visit my website for cheap traffic']);
        Http::fake(['minimax.test/*' => Http::response($this->completion(['items' => [[
            'record_id' => $question->id,
            ...$this->routingOutput([
                'primary_category' => 'other_spam',
                'intent_key' => 'spam_or_irrelevant',
                'work_queue' => 'policy_review',
                'answer_source' => 'verified_policy',
            ]),
        ]]]), 200)]);

        app(ProductQuestionBatchAnalyzer::class)->analyze(collect([$question]));

        $analysis = $question->analysis()->firstOrFail();
        $this->assertSame('human_review', $analysis->work_queue);
        $this->assertSame('human_judgment', $analysis->answer_source);
    }

    public function test_batch_analyzer_routes_general_production_time_to_policy_review(): void
    {
        $question = $this->question(['id' => 134, 'question' => 'How much time does custom sizing add?']);
        Http::fake(['minimax.test/*' => Http::response($this->completion(['items' => [[
            'record_id' => $question->id,
            ...$this->routingOutput([
                'primary_category' => 'production_time',
                'intent_key' => 'ask_production_time',
                'work_queue' => 'customer_clarification',
                'answer_source' => 'customer_input',
            ]),
        ]]]), 200)]);

        app(ProductQuestionBatchAnalyzer::class)->analyze(collect([$question]));

        $analysis = $question->analysis()->firstOrFail();
        $this->assertSame('policy_review', $analysis->work_queue);
        $this->assertSame('product_and_policy', $analysis->answer_source);
    }

    public function test_batch_command_can_reanalyze_only_one_existing_work_queue(): void
    {
        $target = $this->question(['id' => 135, 'question' => 'What fabric is this?']);
        $other = $this->question(['id' => 136, 'question' => 'Can you add pockets?']);
        foreach ([[$target, 'catalog_data_missing'], [$other, 'factory_review']] as [$question, $queue]) {
            ProductQuestionAnalysis::create([
                'product_question_id' => $question->id,
                ...$this->validOutput(['work_queue' => $queue]),
                'review_status' => 'candidate',
                'analyzer_version' => 'old-version',
                'input_hash' => 'old-hash',
            ]);
        }
        Http::fake(['minimax.test/*' => Http::response($this->completion(['items' => [[
            'record_id' => $target->id,
            ...$this->routingOutput(['intent_key' => 'ask_fabric']),
        ]]]), 200)]);

        $exit = Artisan::call('product-questions:classify-batch', [
            '--site' => $target->site->site_key,
            '--days' => 150,
            '--work-queue' => 'catalog_data_missing',
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(ProductQuestionBatchAnalyzer::VERSION, $target->analysis()->firstOrFail()->analyzer_version);
        $this->assertSame('old-version', $other->analysis()->firstOrFail()->analyzer_version);
        $this->assertSame(1, ProductQuestionAnalysisRun::count());
        Http::assertSentCount(1);
    }

    public function test_administrator_can_filter_and_review_ai_analysis(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $question = $this->question();
        Http::fake(['minimax.test/*' => Http::response($this->completion($this->validOutput()), 200)]);
        app(ProductQuestionAnalyzer::class)->analyze($question);
        $site = $question->site;

        $this->get("/admin/product-questions?site_id={$site->id}&primary_category=shipping_delivery&analysis_review_status=candidate")
            ->assertOk()
            ->assertSee('配送时间、费用与地区')
            ->assertSee('待审核');
        $this->get("/admin/product-questions/{$question->external_id}?site_id={$site->id}")
            ->assertOk()
            ->assertSee('AI 分类与回复模式分析')
            ->assertSee('客户询问发货时间');

        $this->from("/admin/product-questions/{$question->external_id}?site_id={$site->id}")
            ->post("/admin/product-questions/{$question->external_id}/review-analysis", [
                'site_id' => $site->id,
                'primary_category' => 'production_time',
                'secondary_categories' => ['shipping_delivery'],
                'customer_intent' => '客户询问制作和发货时间。',
                'question_scope' => 'product_specific',
                'answerability' => 'needs_business_facts',
                'risk_level' => 'medium',
                'required_facts' => "产品制作时间\n配送目的地",
                'missing_facts' => '配送目的地',
                'response_moves' => ['direct_conclusion', 'state_conditions'],
                'reply_pattern' => 'conditional_answer',
                'answer_summary' => '历史回答给出七天预期。',
                'analysis_summary' => '需要区分制作周期与运输时间。',
                'assessment_relevance' => 'good',
                'assessment_completeness' => 'partial',
                'assessment_clarity' => 'good',
                'assessment_consistency' => 'not_applicable',
                'assessment_policy_safety' => 'partial',
                'assessment_strengths' => '回应了时间问题',
                'assessment_gaps' => '没有说明条件',
                'assessment_risky_claims' => '',
                'automation_recommendation' => 'draft_only',
                'review_status' => 'corrected',
                'review_notes' => '应归入制作周期。',
            ])->assertRedirect("/admin/product-questions/{$question->external_id}?site_id={$site->id}");

        $analysis = ProductQuestionAnalysis::firstOrFail();
        $this->assertSame('corrected', $analysis->review_status);
        $this->assertSame('production_time', $analysis->primary_category);
        $this->assertSame(['shipping_delivery'], $analysis->secondary_categories);
        $this->assertSame(['产品制作时间', '配送目的地'], $analysis->required_facts);
        $this->assertSame(['没有说明条件'], $analysis->answer_assessment['gaps']);
        $this->assertSame('应归入制作周期。', $analysis->review_notes);
        $this->assertNotNull($analysis->reviewed_at);
    }

    public function test_review_cannot_mark_incomplete_medium_risk_analysis_as_auto_candidate(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $question = $this->question();
        Http::fake(['minimax.test/*' => Http::response($this->completion($this->validOutput()), 200)]);
        app(ProductQuestionAnalyzer::class)->analyze($question);
        $site = $question->site;

        $this->from("/admin/product-questions/{$question->external_id}?site_id={$site->id}")
            ->post("/admin/product-questions/{$question->external_id}/review-analysis", [
                'site_id' => $site->id,
                'primary_category' => 'shipping_delivery',
                'customer_intent' => '客户询问发货时间。',
                'question_scope' => 'product_specific',
                'answerability' => 'needs_business_facts',
                'risk_level' => 'medium',
                'required_facts' => '配送目的地',
                'missing_facts' => '配送目的地',
                'response_moves' => ['direct_conclusion'],
                'reply_pattern' => 'conditional_answer',
                'answer_summary' => '历史回答给出七天预期。',
                'analysis_summary' => '仍缺少事实。',
                'assessment_relevance' => 'good',
                'assessment_completeness' => 'partial',
                'assessment_clarity' => 'good',
                'assessment_consistency' => 'not_applicable',
                'assessment_policy_safety' => 'partial',
                'automation_recommendation' => 'auto_candidate',
                'review_status' => 'confirmed',
            ])->assertRedirect("/admin/product-questions/{$question->external_id}?site_id={$site->id}")
            ->assertSessionHasErrors('automation_recommendation');

        $this->assertSame('candidate', ProductQuestionAnalysis::firstOrFail()->review_status);
    }

    private function administrator(): Administrator
    {
        $administrator = Administrator::create([
            'username' => 'qa-analysis-admin',
            'password' => Hash::make('secret'),
            'name' => 'QA Analysis Admin',
        ]);
        $administrator->roles()->attach(Role::where('slug', 'administrator')->firstOrFail());

        return $administrator;
    }

    private function site(): CommerceSite
    {
        return CommerceSite::firstOrCreate(['site_key' => 'dressafford'], [
            'name' => 'DressAfford',
            'admin_api_url' => 'https://dressafford.test/api/admin',
            'api_token' => 'secret',
            'enabled' => true,
        ]);
    }

    private function question(array $overrides = []): ProductQuestion
    {
        $site = $this->site();

        return app(ProductQuestionSyncService::class)->storeQuestion($site, array_replace([
            'id' => 120,
            'sn' => 'QA100',
            'product' => ['name' => 'Blue Dress'],
            'name' => 'Customer Name',
            'email' => 'customer@example.com',
            'type' => 1,
            'type_text' => 'User question',
            'question' => 'When will this ship?',
            'answer' => '<p>Usually within 7 days.</p>',
            'reply_status' => 'answered',
            'needs_reply' => false,
            'visible' => true,
            'notice_status' => 1,
            'notice_status_text' => 'Sent',
            'images' => [],
            'helpful' => 1,
            'created_at' => '2026-10-14 12:00:00',
            'updated_at' => '2026-10-14 13:00:00',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function validOutput(array $overrides = []): array
    {
        return array_replace([
            'primary_category' => 'shipping_delivery',
            'secondary_categories' => ['production_time'],
            'intent_key' => 'ask_delivery_time',
            'customer_intent' => '客户询问发货时间',
            'question_scope' => 'product_specific',
            'answer_source' => 'product_and_policy',
            'work_queue' => 'policy_review',
            'answerability' => 'needs_business_facts',
            'risk_level' => 'medium',
            'required_facts' => ['配送目的地', '产品制作时间'],
            'missing_facts' => ['配送目的地'],
            'factory_capability_key' => null,
            'factory_question' => null,
            'response_moves' => ['direct_conclusion', 'set_expectation'],
            'reply_pattern' => 'conditional_answer',
            'answer_summary' => '历史回答称通常七天内发货。',
            'answer_assessment' => [
                'relevance' => 'good',
                'completeness' => 'partial',
                'clarity' => 'good',
                'consistency' => 'not_applicable',
                'policy_safety' => 'partial',
                'strengths' => ['给出了时间预期'],
                'gaps' => ['没有说明制作与运输时间边界'],
                'prohibited_or_risky_claims' => [],
            ],
            'automation_recommendation' => 'human_review',
            'confidence' => 0.91,
            'analysis_summary' => '回答模式为给出概括性时间预期，但需要实时交期事实后才能可靠复用。',
        ], $overrides);
    }

    private function routingOutput(array $overrides = []): array
    {
        return array_replace([
            'primary_category' => 'product_details',
            'secondary_categories' => [],
            'intent_key' => 'ask_product_details',
            'customer_intent' => '客户询问产品信息',
            'question_scope' => 'product_specific',
            'answer_source' => 'product_catalog',
            'work_queue' => 'catalog_data_missing',
            'risk_level' => 'low',
            'required_facts' => ['产品信息'],
            'missing_facts' => ['产品信息'],
            'factory_capability_key' => null,
            'factory_question' => null,
            'confidence' => 0.9,
        ], $overrides);
    }

    /** @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    private function completion(array $output): array
    {
        return [
            'id' => 'completion-test',
            'choices' => [['message' => ['content' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
        ];
    }
}
