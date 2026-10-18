<?php

namespace Tests\Feature;

use App\Models\CommerceSite;
use App\Models\KnowledgeArticle;
use App\Models\ProductQuestionAnalysis;
use App\Models\ProductQuestionFactoryCapability;
use App\Models\ProductQuestionPolicyCandidate;
use App\Models\ProductQuestionPolicyRun;
use App\Services\ProductQuestions\ProductQuestionFactoryCapabilityAggregator;
use App\Services\ProductQuestions\ProductQuestionFactoryCapabilityPolicyImporter;
use App\Services\TradeManager\ProductQuestionSyncService;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductQuestionFactoryCapabilityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregator_creates_one_review_card_for_many_questions(): void
    {
        $site = $this->site();
        foreach ([101, 102] as $externalId) {
            $question = app(ProductQuestionSyncService::class)->storeQuestion($site, [
                'id' => $externalId,
                'sn' => 'DW'.$externalId,
                'question' => 'Can you add pockets?',
                'answer' => 'Please leave an order note.',
                'reply_status' => 'answered',
                'visible' => true,
                'images' => [],
                'created_at' => now()->toDateTimeString(),
            ]);
            ProductQuestionAnalysis::create([
                'product_question_id' => $question->id,
                'primary_category' => 'customization',
                'secondary_categories' => [],
                'intent_key' => 'request_add_pockets',
                'customer_intent' => '客户要求增加口袋',
                'question_scope' => 'mixed',
                'answer_source' => 'factory_capability',
                'work_queue' => 'factory_review',
                'answerability' => 'needs_business_facts',
                'risk_level' => 'medium',
                'required_facts' => ['适用款式'],
                'missing_facts' => ['例外'],
                'factory_capability_key' => 'add_pockets',
                'factory_question' => '请确认增加口袋的范围、例外、费用和交期。',
                'response_moves' => [],
                'reply_pattern' => 'incomplete',
                'answer_summary' => '历史回答要求备注。',
                'answer_assessment' => $this->assessment(),
                'automation_recommendation' => 'human_review',
                'confidence' => 0.9,
                'analysis_summary' => '需要工厂确认。',
                'review_status' => 'candidate',
                'analyzer_version' => 'test',
                'input_hash' => hash('sha256', (string) $externalId),
            ]);
        }

        $result = app(ProductQuestionFactoryCapabilityAggregator::class)->aggregate($site, 150);

        $this->assertSame(['capabilities' => 1, 'questions' => 2], $result);
        $card = ProductQuestionFactoryCapability::firstOrFail();
        $this->assertSame('add_pockets', $card->capability_key);
        $this->assertSame(2, $card->question_count);
        $this->assertSame(2, $card->product_count);
        $this->assertSame(0, ProductQuestionPolicyRun::count());

        app(ProductQuestionFactoryCapabilityPolicyImporter::class)->import($site);
        $run = ProductQuestionPolicyRun::where('batch_key', 'factory-capability-policy-review-dorriswedding')->firstOrFail();
        $candidate = ProductQuestionPolicyCandidate::where('product_question_policy_run_id', $run->id)->firstOrFail();
        $this->assertSame('request_add_pockets', $candidate->scenario_key);
        $this->assertSame($card->evidence_question_ids, $candidate->evidence_question_ids);
        $this->assertStringContainsString('尚未由工厂确认', $candidate->policy_rules[0]);
        $this->assertCount(5, $candidate->open_questions);

        $this->actingAs($this->administrator(), 'admin');
        $this->get("/admin/product-question-policies/{$candidate->id}")
            ->assertOk()
            ->assertSee('Can you add pockets?')
            ->assertSee('历史回答：')
            ->assertSee('Please leave an order note.');
    }

    public function test_legacy_factory_capability_routes_are_removed(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $card = ProductQuestionFactoryCapability::create([
            'commerce_site_id' => $this->site()->id,
            'capability_key' => 'add_pockets',
            'title' => '增加口袋',
            'question_count' => 5,
            'product_count' => 5,
            'confidence' => 0.9,
            'evidence_question_ids' => [],
            'product_sns' => [],
            'representative_questions' => [],
            'factory_question' => '是否支持增加口袋？',
            'review_status' => 'pending',
        ]);

        $this->get('/admin/product-question-factory-capabilities')->assertNotFound();
        $this->get("/admin/product-question-factory-capabilities/{$card->id}")->assertNotFound();
    }

    public function test_custom_size_candidate_uses_confirmed_cross_site_rules(): void
    {
        $site = $this->site();
        ProductQuestionFactoryCapability::create([
            'commerce_site_id' => $site->id,
            'capability_key' => 'custom_size',
            'title' => '定制尺码',
            'question_count' => 49,
            'product_count' => 47,
            'confidence' => 0.8314,
            'evidence_question_ids' => [],
            'product_sns' => [],
            'representative_questions' => [],
            'factory_question' => '请确认定制尺码规则。',
            'review_status' => 'pending',
        ]);

        $run = app(ProductQuestionFactoryCapabilityPolicyImporter::class)->import($site);
        $candidate = $run->candidates()->where('scenario_key', 'request_custom_size')->firstOrFail();

        $this->assertSame('global', $candidate->scope);
        $this->assertSame([], $candidate->open_questions);
        $this->assertSame([], $candidate->conflicts);
        $rules = implode(' ', $candidate->policy_rules);
        $this->assertStringContainsString('customizable=1', $rules);
        $this->assertStringContainsString('DressAfford 和 EllenBridals', $rules);
        $this->assertStringContainsString('DorrisWedding 和 DorrisBridal', $rules);
        $this->assertStringContainsString('超过 56 英寸', $rules);
        $this->assertStringContainsString('不增加正常制作周期', $rules);
        $this->assertStringContainsString('不支持退货或换货', $rules);
    }

    public function test_add_pockets_factory_candidate_merges_into_confirmed_pockets_policy(): void
    {
        $site = $this->site();
        ProductQuestionFactoryCapability::create([
            'commerce_site_id' => $site->id,
            'capability_key' => 'add_pockets',
            'title' => '增加口袋',
            'question_count' => 6,
            'product_count' => 6,
            'confidence' => 0.82,
            'evidence_question_ids' => [],
            'product_sns' => [],
            'representative_questions' => [],
            'factory_question' => '是否支持增加口袋？',
            'review_status' => 'pending',
        ]);
        $run = app(ProductQuestionFactoryCapabilityPolicyImporter::class)->import($site);
        $duplicate = $run->candidates()->where('scenario_key', 'request_add_pockets')->firstOrFail();
        $article = KnowledgeArticle::create([
            'key' => 'qa_global_ask_pockets',
            'title' => '口袋规则',
            'knowledge_type' => 'policy',
            'status' => 'verified',
            'automation_tier' => 'draft_low_risk',
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
        $target = ProductQuestionPolicyCandidate::create([
            'product_question_policy_run_id' => $run->id,
            'commerce_site_id' => $site->id,
            'scenario_key' => 'ask_pockets',
            'category' => 'product_details',
            'title' => '合并后的口袋规则',
            'definition' => '默认口袋和增加口袋使用同一业务策略。',
            'applicability' => '适用于口袋相关问题。',
            'required_facts' => [],
            'policy_rules' => [],
            'decision_tree' => [],
            'reply_strategy' => [],
            'reply_templates' => [],
            'action_steps' => [],
            'prohibited_claims' => [],
            'escalation_rules' => [],
            'evidence_question_ids' => [],
            'conflicts' => [],
            'open_questions' => [],
            'confidence' => 1,
            'review_status' => 'confirmed',
            'knowledge_article_id' => $article->id,
            'scope' => 'global',
        ]);

        app(ProductQuestionFactoryCapabilityPolicyImporter::class)->import($site);

        $this->assertSame('rejected', $duplicate->fresh()->review_status);
        $this->assertSame('merged_into_policy_candidate:'.$target->id, $duplicate->fresh()->review_notes);
        $this->actingAs($this->administrator(), 'admin');
        $this->get('/admin/product-question-policies/'.$duplicate->id)
            ->assertRedirect('/admin/product-question-policies/'.$target->id);
        $this->get('/admin/product-question-policies?source=factory_capability')
            ->assertOk()
            ->assertDontSee('增加口袋能力与回复规则');
    }

    private function site(): CommerceSite
    {
        return CommerceSite::firstOrCreate(['site_key' => 'dorriswedding'], [
            'name' => 'Dorriswedding',
            'admin_api_url' => 'https://dorris.test/api/admin',
            'api_token' => 'secret',
            'enabled' => true,
        ]);
    }

    private function administrator(): Administrator
    {
        $administrator = Administrator::create([
            'username' => 'factory-policy-admin',
            'password' => Hash::make('secret'),
            'name' => 'Factory Policy Admin',
        ]);
        $administrator->roles()->attach(Role::where('slug', 'administrator')->firstOrFail());

        return $administrator;
    }

    private function assessment(): array
    {
        return [
            'relevance' => 'good', 'completeness' => 'partial', 'clarity' => 'good',
            'consistency' => 'not_applicable', 'policy_safety' => 'partial',
            'strengths' => [], 'gaps' => [], 'prohibited_or_risky_claims' => [],
        ];
    }
}
