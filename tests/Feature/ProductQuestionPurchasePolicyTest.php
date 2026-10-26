<?php

namespace Tests\Feature;

use App\Models\CommerceSite;
use App\Models\KnowledgeArticle;
use App\Models\ProductQuestionDraftRequest;
use App\Services\ProductQuestions\KnowledgeArticleVersionPublisher;
use App\Services\ProductQuestions\N8nProductQuestionDraftService;
use App\Services\ProductQuestions\ProductQuestionDraftInput;
use App\Services\TradeManager\ProductQuestionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductQuestionPurchasePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_requirements_use_a_versioned_process_without_length_or_photo_timing_defaults(): void
    {
        $article = KnowledgeArticle::where('key', 'qa_process_confirmed_order_requirements')->firstOrFail();

        $this->assertSame('verified', $article->status);
        $this->assertSame('process', $article->knowledge_type);
        $this->assertSame(
            app(KnowledgeArticleVersionPublisher::class)->snapshot($article),
            $article->versions()->where('status', 'published')->firstOrFail()->snapshot,
        );
        $this->assertDatabaseHas('knowledge_article_bindings', [
            'knowledge_article_id' => $article->id, 'consumer' => 'product_question',
            'intent_key' => '*', 'usage' => 'draft', 'enabled' => true,
        ]);
        $rules = implode("\n", $article->policy_rules);
        $this->assertStringContainsString('Order Notes', $rules);
        $this->assertStringContainsString('仅当本次要求涉及数值', $rules);
        $this->assertStringContainsString('不得自行添加拍照时点', $rules);
        $this->assertStringNotContainsString('茶长', $rules);
    }

    public function test_order_process_reaches_non_length_requests_and_binding_changes_invalidate_input(): void
    {
        $site = CommerceSite::create([
            'name' => 'Test', 'site_key' => 'purchase-test', 'admin_api_url' => 'https://purchase.test',
            'api_token' => 'secret', 'enabled' => true,
        ]);
        $question = app(ProductQuestionSyncService::class)->storeQuestion($site, [
            'id' => 123, 'sn' => 'test-dress', 'question' => 'Can you add cap sleeves?', 'reply_status' => 'unanswered',
        ]);
        $request = ProductQuestionDraftRequest::create([
            'request_id' => (string) Str::uuid(), 'product_question_id' => $question->id,
            'workflow_key' => N8nProductQuestionDraftService::WORKFLOW_KEY,
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION, 'status' => 'queued',
        ]);
        $service = app(N8nProductQuestionDraftService::class);
        $article = KnowledgeArticle::where('key', 'qa_process_confirmed_order_requirements')->firstOrFail();
        $input = app(ProductQuestionDraftInput::class)->capture($question);
        $policies = $service->policies($request, ['request_add_sleeves'])['data'];
        $this->assertContains($article->id, array_column($policies, 'id'));
        $this->assertSame($article->policy_rules, collect($policies)->firstWhere('id', $article->id)['policy_rules']);

        $article->bindings()->update(['enabled' => false]);
        $this->assertNotContains($article->id, array_column($service->policies($request, ['request_add_sleeves'])['data'], 'id'));
        $this->assertNotSame($input['hash'], app(ProductQuestionDraftInput::class)->capture($question)['hash']);
    }

    public function test_shipping_update_preserves_old_version_and_only_relaxes_missing_estimate(): void
    {
        $process = KnowledgeArticle::where('key', 'qa_process_confirmed_order_requirements')->firstOrFail();
        $shipping = $process->replicate();
        $shipping->fill([
            'key' => 'qa_global_ask_shipping_options', 'knowledge_type' => 'policy', 'version' => 7,
            'policy_rules' => ['Do not invent destination support.'],
            'required_facts' => ['supported, methods[].estimated_cost'],
            'decision_tree' => [['condition' => '查询失败、数据缺失或国家无法标准化', 'outcome' => '转人工']],
            'escalation_rules' => ['配送查询接口失败、超时、数据缺失或数据过期。'],
        ])->save();
        $before = app(KnowledgeArticleVersionPublisher::class)->snapshot($shipping);
        $migration = require database_path('migrations/2026_10_21_000059_govern_product_question_purchase_rules.php');
        $migration->up();
        $shipping->refresh();

        $this->assertSame(8, $shipping->version);
        $this->assertSame($before, $shipping->versions()->where('version', 7)->firstOrFail()->snapshot);
        $this->assertSame(app(KnowledgeArticleVersionPublisher::class)->snapshot($shipping), $shipping->versions()->where('version', 8)->firstOrFail()->snapshot);
        $this->assertContains('Do not invent destination support.', $shipping->policy_rules);
        $this->assertStringContainsString('仅未返回参考费用', $shipping->decision_tree[0]['condition']);
        $this->assertStringContainsString('配送支持或方式数据缺失', $shipping->escalation_rules[0]);
    }
}
