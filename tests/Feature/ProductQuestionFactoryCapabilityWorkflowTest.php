<?php

namespace Tests\Feature;

use App\Models\CommerceSite;
use App\Models\ProductQuestionAnalysis;
use App\Models\ProductQuestionFactoryCapability;
use App\Services\ProductQuestions\ProductQuestionFactoryCapabilityAggregator;
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
    }

    public function test_administrator_can_record_factory_conclusion(): void
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

        $this->get("/admin/product-question-factory-capabilities/{$card->id}")->assertOk()->assertSee('增加口袋');
        $this->put("/admin/product-question-factory-capabilities/{$card->id}", [
            'review_status' => 'confirmed',
            'applicability' => '适用于全部婚纱。',
            'exceptions' => '特殊结构逐款确认。',
            'fee_policy' => '免费',
            'lead_time_policy' => '不增加工期',
            'factory_answer' => '可以增加口袋。',
        ])->assertRedirect();

        $this->assertSame('confirmed', $card->fresh()->review_status);
        $this->assertSame('免费', $card->fresh()->fee_policy);
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
