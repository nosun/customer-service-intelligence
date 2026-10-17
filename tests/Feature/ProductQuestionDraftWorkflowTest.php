<?php

namespace Tests\Feature;

use App\Models\CommerceProduct;
use App\Models\CommerceSite;
use App\Models\KnowledgeArticle;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionDraft;
use App\Models\ProductQuestionDraftRequest;
use App\Services\N8n\N8nDraftWorkflowClient;
use App\Services\ProductQuestions\N8nProductQuestionDraftService;
use App\Services\ProductQuestions\ProductQuestionDraftGenerator;
use App\Services\ProductQuestions\ProductQuestionDraftInput;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class ProductQuestionDraftWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_combines_verified_policies_fetches_live_facts_and_only_saves_a_local_draft(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'Can you ship this to the US and will it arrive by December 20?');
        $this->product($site);
        $shipping = $this->article('ask_shipping_options', 'Shipping options');
        $delivery = $this->article('ask_delivery_time', 'Delivery estimate');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'shipping/options')) {
                return Http::response(['success' => true, 'data' => [
                    'country' => ['code' => 'US', 'supported' => true],
                    'options' => [['name' => 'Express', 'transit_days' => [3, 5], 'estimated_cost' => 20]],
                ]]);
            }
            if (str_contains($request->url(), 'products/QA100/eta')) {
                return Http::response(['success' => true, 'data' => [
                    'country' => ['code' => 'US', 'supported' => true],
                    'options' => [['name' => 'Standard Order', 'available' => true, 'estimated_arrival' => ['earliest' => '2026-12-10', 'latest' => '2026-12-15']]],
                ]]);
            }

            static $completion = 0;
            $completion++;
            $output = $completion === 1 ? [
                'intents' => ['ask_shipping_options', 'ask_delivery_time'],
                'customer_language' => 'en',
                'country_code' => 'US',
                'event_date' => '2026-12-20',
                'requested_currency' => null,
                'requested_language' => null,
                'requires_private_reply' => false,
                'summary' => '配送支持与到货时间两个意图',
            ] : [
                'answer_en' => 'We currently ship to the US. The current estimated arrival range is December 10–15, but it is not guaranteed. Please confirm the latest option at checkout.',
                'answer_localized' => null,
                'used_fact_keys' => ['shipping.country.supported', 'eta.options.0.estimated_arrival'],
                'unresolved_facts' => [],
                'safety_flags' => [],
                'confidence' => 0.91,
            ];

            return Http::response([
                'id' => 'minimax-'.$completion,
                'choices' => [['message' => ['content' => json_encode($output)]]],
                'usage' => ['total_tokens' => 100],
            ]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('generated', $draft->status, $draft->error_message ?? '');
        $this->assertSame(['ask_shipping_options', 'ask_delivery_time'], $draft->intents);
        $this->assertEqualsCanonicalizing([$shipping->id, $delivery->id], $draft->knowledge_article_ids);
        $this->assertTrue(data_get($draft->facts, 'shipping.country.supported'));
        $this->assertSame('2026-12-15', data_get($draft->facts, 'eta.options.0.estimated_arrival.latest'));
        $this->assertTrue($draft->requires_human_review);
        $this->assertTrue($draft->recommended_visible);
        $this->assertStringContainsString('not guaranteed', $draft->answer());
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
    }

    public function test_private_draft_cannot_be_previewed_as_public(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $site = $this->site();
        $question = $this->question($site, 'Where is my order?');
        ProductQuestionDraft::create([
            'product_question_id' => $question->id,
            'status' => 'generated',
            'model' => 'MiniMax-M3',
            'prompt_version' => 'test',
            'intents' => ['ask_order_status'],
            'knowledge_article_ids' => [],
            'facts' => [],
            'fact_sources' => [],
            'missing_facts' => ['private_order_lookup'],
            'safety_flags' => [],
            'answer_en' => 'Please check your order through the private order-status page.',
            'recommended_visible' => false,
            'requires_human_review' => true,
            'input_hash' => str_repeat('a', 64),
        ]);
        Http::fake();

        $this->from("/admin/product-questions/120?site_id={$site->id}")
            ->post('/admin/product-questions/120/preview-reply', [
                'site_id' => $site->id,
                'answer' => 'Order details',
                'visible' => '1',
                'reason' => 'Draft review',
            ])
            ->assertRedirect("/admin/product-questions/120?site_id={$site->id}")
            ->assertSessionHasErrors('visible');
        Http::assertNothingSent();
    }

    public function test_admin_draft_button_queues_the_n8n_workflow_instead_of_generating_inline(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $site = $this->site();
        $question = $this->question($site, 'Does this dress have pockets?');
        $this->mock(N8nDraftWorkflowClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('trigger')->once()->withArgs(fn (ProductQuestionDraftRequest $request): bool => $request->status === 'queued');
        });

        $this->post('/admin/product-questions/120/generate-draft', ['site_id' => $site->id])
            ->assertRedirect("/admin/product-questions/120?site_id={$site->id}");

        $this->assertSame(0, $question->drafts()->count());
        $this->assertDatabaseHas('product_question_draft_requests', [
            'product_question_id' => $question->id,
            'workflow_key' => 'work/qa-draft-agent-v1',
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'status' => 'queued',
        ]);
    }

    public function test_admin_records_an_immediate_n8n_trigger_failure_for_retry(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $site = $this->site();
        $question = $this->question($site, 'Does this dress have pockets?');
        $this->mock(N8nDraftWorkflowClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('trigger')->once()->andThrow(new \RuntimeException('n8n unavailable'));
        });

        $this->post('/admin/product-questions/120/generate-draft', ['site_id' => $site->id])
            ->assertRedirect("/admin/product-questions/120?site_id={$site->id}");

        $request = $question->draftRequests()->firstOrFail();
        $this->assertSame('failed', $request->status);
        $this->assertSame('n8n unavailable', $request->error_message);
        $this->assertNotNull($request->finished_at);
    }

    public function test_admin_reuses_a_completed_draft_when_all_inputs_are_unchanged(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $site = $this->site();
        $question = $this->question($site, 'Does this dress have pockets?');
        $this->product($site);
        $this->article('ask_pockets', 'Pocket policy');
        $input = app(ProductQuestionDraftInput::class)->capture($question);
        $draft = ProductQuestionDraft::create([
            'product_question_id' => $question->id, 'status' => 'generated', 'model' => 'MiniMax-M3',
            'prompt_version' => N8nProductQuestionDraftService::PROMPT_VERSION, 'intents' => ['ask_pockets'],
            'knowledge_article_ids' => [], 'facts' => [], 'fact_sources' => [], 'missing_facts' => [],
            'safety_flags' => [], 'answer_en' => 'This dress does not come with pockets by default.',
            'recommended_visible' => true, 'requires_human_review' => true, 'input_hash' => $input['hash'],
        ]);
        $original = ProductQuestionDraftRequest::create([
            'request_id' => '019a-reusable-draft-request', 'product_question_id' => $question->id,
            'product_question_draft_id' => $draft->id, 'workflow_key' => N8nProductQuestionDraftService::WORKFLOW_KEY,
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION, 'input_hash' => $input['hash'],
            'input_snapshot' => $input['snapshot'], 'status' => 'completed', 'finished_at' => now(),
        ]);
        $this->mock(N8nDraftWorkflowClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('trigger'));

        $this->post('/admin/product-questions/120/generate-draft', ['site_id' => $site->id])
            ->assertRedirect("/admin/product-questions/120?site_id={$site->id}");

        $this->assertSame(2, $question->draftRequests()->count());
        $this->assertDatabaseHas('product_question_draft_requests', [
            'product_question_id' => $question->id,
            'product_question_draft_id' => $draft->id,
            'reused_from_request_id' => $original->id,
            'status' => 'completed',
        ]);
    }

    public function test_completion_is_marked_stale_when_the_question_changes_during_generation(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'Does this dress have pockets?');
        $this->article('ask_pockets', 'Pocket policy');
        $input = app(ProductQuestionDraftInput::class)->capture($question);
        $request = ProductQuestionDraftRequest::create([
            'request_id' => '019a-stale-draft-request', 'product_question_id' => $question->id,
            'workflow_key' => N8nProductQuestionDraftService::WORKFLOW_KEY,
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'input_hash' => $input['hash'], 'input_snapshot' => $input['snapshot'],
            'status' => 'running', 'n8n_execution_id' => 'stale-run', 'started_at' => now(),
        ]);
        $question->update(['question' => 'Has the product question changed?']);

        try {
            app(N8nProductQuestionDraftService::class)->complete($request, ['input_hash' => $input['hash']]);
            $this->fail('Expected stale Draft input to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('changed', $exception->getMessage());
        }

        $this->assertSame('stale', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->finished_at);

        app(N8nProductQuestionDraftService::class)->fail($request->fresh(), 'n8n received the stale response as an error');
        $this->assertSame('stale', $request->fresh()->status);
        $this->assertStringContainsString('changed', $request->fresh()->error_message);
    }

    public function test_reaper_fails_expired_running_requests_without_opening_the_admin_page(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'Does this dress have pockets?');
        $request = ProductQuestionDraftRequest::create([
            'request_id' => '019a-expired-draft-request', 'product_question_id' => $question->id,
            'workflow_key' => N8nProductQuestionDraftService::WORKFLOW_KEY,
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'status' => 'running', 'started_at' => now()->subMinutes(20),
        ]);
        ProductQuestionDraftRequest::whereKey($request->id)->update(['updated_at' => now()->subMinutes(20)]);

        $this->assertSame(0, Artisan::call('product-questions:reap-draft-requests', ['--running-minutes' => 10]));

        $this->assertSame('failed', $request->fresh()->status);
        $this->assertStringContainsString('exceeded 10 minutes', $request->fresh()->error_message);
    }

    public function test_unresolved_template_variable_blocks_the_draft(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'Where was this dress made?');
        $this->product($site);
        $this->article('ask_product_origin', 'Product origin');
        Http::fake(function () {
            static $completion = 0;
            $completion++;
            $output = $completion === 1 ? [
                'intents' => ['ask_product_origin'], 'customer_language' => 'en',
                'country_code' => null, 'event_date' => null, 'requested_currency' => null,
                'requested_language' => null, 'requires_private_reply' => false, 'summary' => '产地',
            ] : [
                'answer_en' => 'This dress is made in {{ country }}.', 'answer_localized' => null,
                'used_fact_keys' => [], 'unresolved_facts' => ['country'], 'safety_flags' => [], 'confidence' => 0.5,
            ];

            return Http::response(['id' => 'minimax', 'choices' => [['message' => ['content' => json_encode($output)]]]]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('blocked', $draft->status);
        $this->assertContains('回复中仍有未解析模板变量。', $draft->safety_flags);
    }

    public function test_site_capabilities_are_available_as_scoped_draft_facts(): void
    {
        $site = $this->site();
        $site->update([
            'operates_online_only' => true,
            'live_chat_enabled' => true,
            'public_phone' => null,
            'try_on_policy' => 'not_offered',
            'physical_store_info' => null,
        ]);
        $question = $this->question($site, 'Can I visit a store and try this dress on?');
        $this->product($site);
        $this->article('request_contact', 'Contact options');
        Http::fake(function () {
            static $completion = 0;
            $completion++;
            $output = $completion === 1 ? [
                'intents' => ['request_contact'], 'customer_language' => 'en',
                'country_code' => null, 'event_date' => null, 'requested_currency' => null,
                'requested_language' => null, 'requires_private_reply' => false, 'summary' => '门店与试穿',
            ] : [
                'answer_en' => 'We operate online only and do not currently offer try-on appointments.',
                'answer_localized' => null,
                'used_fact_keys' => ['site.operates_online_only', 'site.try_on_policy'],
                'unresolved_facts' => [], 'safety_flags' => [], 'confidence' => 0.9,
            ];

            return Http::response(['id' => 'minimax', 'choices' => [['message' => ['content' => json_encode($output)]]]]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('generated', $draft->status, $draft->error_message ?? '');
        $this->assertTrue(data_get($draft->facts, 'site.operates_online_only'));
        $this->assertTrue(data_get($draft->facts, 'site.live_chat_enabled'));
        $this->assertSame('not_offered', data_get($draft->facts, 'site.try_on_policy'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
    }

    public function test_live_fact_failure_blocks_the_draft_without_sending_a_reply(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'Can you ship this to the US?');
        $this->product($site);
        $this->article('ask_shipping_options', 'Shipping options');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'shipping/options')) {
                return Http::response(['message' => 'Unavailable'], 503);
            }

            static $completion = 0;
            $completion++;
            $output = $completion === 1 ? [
                'intents' => ['ask_shipping_options'], 'customer_language' => 'en',
                'country_code' => 'US', 'event_date' => null, 'requested_currency' => null,
                'requested_language' => null, 'requires_private_reply' => false, 'summary' => '配送国家',
            ] : [
                'answer_en' => 'We need to check the current shipping options before confirming this.',
                'answer_localized' => null, 'used_fact_keys' => [],
                'unresolved_facts' => ['shipping availability'], 'safety_flags' => [], 'confidence' => 0.2,
            ];

            return Http::response(['id' => 'minimax', 'choices' => [['message' => ['content' => json_encode($output)]]]]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('blocked', $draft->status, $draft->error_message ?? '');
        $this->assertContains('shipping_api_result', $draft->missing_facts);
        $this->assertContains('关键事实缺失或实时查询失败，草稿不得直接进入 Preview。', $draft->safety_flags);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
    }

    public function test_model_reported_unresolved_fact_blocks_the_draft(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'What is the exact fiber composition?');
        $this->product($site);
        $this->article('ask_guarantee', 'Product quality');
        Http::fake(function () {
            static $completion = 0;
            $completion++;
            $output = $completion === 1 ? [
                'intents' => ['ask_guarantee'], 'customer_language' => 'en',
                'country_code' => null, 'event_date' => null, 'requested_currency' => null,
                'requested_language' => null, 'requires_private_reply' => false, 'summary' => '材质成分',
            ] : [
                'answer_en' => 'The exact fiber composition needs to be verified.',
                'answer_localized' => null, 'used_fact_keys' => [],
                'unresolved_facts' => ['product.attributes.fiber_composition'],
                'safety_flags' => [], 'confidence' => 0.4,
            ];

            return Http::response(['id' => 'minimax', 'choices' => [['message' => ['content' => json_encode($output)]]]]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('blocked', $draft->status, $draft->error_message ?? '');
        $this->assertContains('product.attributes.fiber_composition', $draft->missing_facts);
    }

    public function test_partial_policy_match_with_unmatched_request_is_blocked(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'Can I return it, and can you send a real fabric photo?');
        $this->article('ask_return_policy', 'Return policy');
        Http::fake(function () {
            static $completion = 0;
            $completion++;
            $output = $completion === 1 ? [
                'intents' => ['ask_return_policy'], 'customer_language' => 'en',
                'country_code' => null, 'event_date' => null, 'requested_currency' => null,
                'requested_language' => null, 'requires_private_reply' => false,
                'unmatched_requests' => ['real fabric photo'], 'summary' => '退货与无策略图片请求',
            ] : [
                'answer_en' => 'Please review the return policy. The fabric photo request needs to be checked.',
                'answer_localized' => null, 'used_fact_keys' => [],
                'unresolved_facts' => [], 'safety_flags' => [], 'confidence' => 0.5,
            ];

            return Http::response(['id' => 'minimax', 'choices' => [['message' => ['content' => json_encode($output)]]]]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('blocked', $draft->status, $draft->error_message ?? '');
        $this->assertContains('unsupported_request: real fabric photo', $draft->missing_facts);
    }

    public function test_invalid_structured_output_is_retried_once(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'What is the return policy?');
        $this->article('ask_return_policy', 'Return policy');
        Http::fake(function () {
            static $completion = 0;
            $completion++;
            if ($completion === 1) {
                return Http::response(['id' => 'bad', 'choices' => [['message' => ['content' => '{invalid']]]]);
            }
            $output = $completion === 2 ? [
                'intents' => ['ask_return_policy'], 'customer_language' => 'en',
                'country_code' => null, 'event_date' => null, 'requested_currency' => null,
                'requested_language' => null, 'requires_private_reply' => false,
                'unmatched_requests' => [], 'summary' => '退货政策',
            ] : [
                'answer_en' => 'Please review the current return policy.', 'answer_localized' => null,
                'used_fact_keys' => [], 'unresolved_facts' => [], 'safety_flags' => [], 'confidence' => 0.9,
            ];

            return Http::response(['id' => 'minimax', 'choices' => [['message' => ['content' => json_encode($output)]]]]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('generated', $draft->status, $draft->error_message ?? '');
        Http::assertSentCount(3);
    }

    public function test_customer_email_phone_and_explicit_order_number_are_redacted_before_ai_calls(): void
    {
        $site = $this->site();
        $question = $this->question(
            $site,
            'Where is order #AB123456? Email customer@example.com or call +1 (555) 123-4567.',
        );
        $this->article('ask_order_status', 'Order status');
        Http::fake(function () {
            static $completion = 0;
            $completion++;
            $output = $completion === 1 ? [
                'intents' => ['ask_order_status'], 'customer_language' => 'en',
                'country_code' => null, 'event_date' => null, 'requested_currency' => null,
                'requested_language' => null, 'requires_private_reply' => true,
                'unmatched_requests' => [], 'summary' => '订单状态',
            ] : [
                'answer_en' => 'Please use the private order-status channel.', 'answer_localized' => null,
                'used_fact_keys' => [], 'unresolved_facts' => [], 'safety_flags' => [], 'confidence' => 0.8,
            ];

            return Http::response(['id' => 'minimax', 'choices' => [['message' => ['content' => json_encode($output)]]]]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('blocked', $draft->status, $draft->error_message ?? '');
        Http::assertNotSent(function (Request $request): bool {
            $body = json_encode($request->data());

            return str_contains($body, 'AB123456')
                || str_contains($body, 'customer@example.com')
                || str_contains($body, '555');
        });
    }

    public function test_custom_size_entry_and_yearless_event_date_use_verified_product_rules(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'Where do I enter my measurements for my wedding on November 28?');
        $this->article('ask_size_recommendation', 'Size recommendation');
        $this->article('ask_how_to_order', 'How to order');
        $this->article('ask_delivery_time', 'Delivery time');
        $this->article('request_custom_size', 'Custom Size entry');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'products/QA100/options')) {
                return Http::response(['success' => true, 'data' => [
                    'editable_fields' => ['color', 'size'],
                    'sizes' => ['4', '6'],
                    'size_chart' => ['4' => ['bust' => '33.5']],
                    'additional_services' => [],
                ]]);
            }
            if (str_contains($request->url(), 'products/QA100')) {
                return Http::response(['success' => true, 'data' => [
                    'product' => ['sn' => 'QA100', 'name' => 'Simple Ivory Jewel Neck Sleeveless Beaded Sheath Knee Length Formal Dress', 'type' => 'dress', 'customizable' => 1],
                    'product_detail' => ['attributes' => []], 'images' => [],
                ]]);
            }

            static $completion = 0;
            $completion++;
            $output = $completion === 1 ? [
                'intents' => ['ask_size_recommendation', 'ask_how_to_order', 'ask_delivery_time'], 'customer_language' => 'en',
                'country_code' => null, 'event_date' => '2024-11-28', 'requested_currency' => null,
                'requested_language' => null, 'requires_private_reply' => false,
                'unmatched_requests' => [], 'summary' => '填写定制尺码',
            ] : ($completion === 2 ? [
                'answer_en' => 'For the Simple Ivory Jewel Neck Sleeveless Beaded Sheath Knee Length Formal Dress, choose Custom Size under Size and complete the fields. Contact us through live chat if needed.',
                'answer_localized' => null, 'used_fact_keys' => ['product.custom_size_entry_method'],
                'unresolved_facts' => ['question.country_code (needed to compute the arrival ETA)'],
                'safety_flags' => [], 'confidence' => 0.7,
            ] : [
                'answer_en' => 'For item QA100, choose Custom Size under Size and complete the fields. To assess the November 28 event date, select the product options and proceed through checkout to review the current estimated arrival before submitting the order.',
                'answer_localized' => null, 'used_fact_keys' => ['product.custom_size_entry_method'],
                'unresolved_facts' => ['country_code_for_eta'],
                'safety_flags' => [], 'confidence' => 0.8,
            ]);

            return Http::response(['id' => 'minimax', 'choices' => [['message' => ['content' => json_encode($output)]]]]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('generated', $draft->status, $draft->error_message ?? '');
        $this->assertSame(['ask_delivery_time', 'request_custom_size'], $draft->intents);
        $this->assertSame(['color', 'size'], data_get($draft->facts, 'product.editable_fields'));
        $this->assertTrue(data_get($draft->facts, 'product.custom_size_input_available'));
        $this->assertSame('size_selector', data_get($draft->facts, 'product.custom_size_entry_method'));
        $this->assertSame('2026-11-28', data_get($draft->facts, 'question.event_date'));
        $this->assertSame(['country_code_for_eta'], $draft->missing_facts);
        $this->assertStringNotContainsString('live chat', $draft->answer());
        $this->assertStringNotContainsString('Simple Ivory Jewel Neck Sleeveless Beaded Sheath Knee Length Formal Dress', $draft->answer());
        $this->assertStringNotContainsString('Which country', $draft->answer());
        $this->assertStringContainsString('checkout', $draft->answer());
        $this->assertSame(1, $draft->usage['semantic_retries']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Public product Q&A is normally a single-turn answer')
            && str_contains($request->body(), 'proceed through checkout')
            && str_contains($request->body(), 'Do not repeat its full product title')
            && str_contains($request->body(), 'Do not append a generic customer-service referral'));
        $this->assertDatabaseHas('commerce_products', [
            'commerce_site_id' => $site->id,
            'sn' => 'QA100',
            'sync_status' => 'completed',
        ]);
    }

    public function test_draft_retries_when_it_introduces_unselected_rush_service(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'Will this arrive by November 28?');
        $this->product($site);
        $this->article('ask_delivery_time', 'Delivery time');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'products/QA100/eta')) {
                return Http::response(['success' => true, 'data' => [
                    'country' => ['code' => 'US', 'supported' => true],
                    'options' => [['name' => 'Standard Order', 'available' => true, 'estimated_arrival' => ['earliest' => '2026-11-10', 'latest' => '2026-11-15']]],
                ]]);
            }

            static $completion = 0;
            $completion++;
            $output = match ($completion) {
                1 => [
                    'intents' => ['ask_delivery_time'], 'customer_language' => 'en', 'country_code' => 'US',
                    'event_date' => '2026-11-28', 'requested_currency' => null, 'requested_language' => null,
                    'requires_private_reply' => false, 'unmatched_requests' => [], 'summary' => '交期',
                ],
                2 => [
                    'answer_en' => 'The current estimate is November 10–15. Rush is also available.', 'answer_localized' => null,
                    'used_fact_keys' => ['eta.options.0.estimated_arrival'], 'unresolved_facts' => [],
                    'safety_flags' => [], 'confidence' => 0.8,
                ],
                default => [
                    'answer_en' => 'The current estimated arrival range is November 10–15, which is before November 28, but it is not guaranteed.',
                    'answer_localized' => null, 'used_fact_keys' => ['eta.options.0.estimated_arrival'],
                    'unresolved_facts' => [], 'safety_flags' => [], 'confidence' => 0.9,
                ],
            };

            return Http::response(['id' => 'minimax-'.$completion, 'choices' => [['message' => ['content' => json_encode($output)]]]]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('generated', $draft->status, $draft->error_message ?? '');
        $this->assertStringNotContainsString('Rush', $draft->answer());
        $this->assertSame(1, $draft->usage['semantic_retries']);
    }

    public function test_product_attribute_question_uses_current_product_fact_not_customization_history(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'Does this dress have pockets?');
        CommerceProduct::create([
            'commerce_site_id' => $site->id, 'sn' => 'QA100', 'name' => 'Blue Dress',
            'product_url' => 'https://dressafford.test/blue-dress.html',
            'product' => ['sn' => 'QA100', 'name' => 'Blue Dress', 'type' => 'dress'],
            'product_detail' => ['attributes' => ['field_pockets' => 'Yes']], 'images' => [],
            'options' => ['colors' => ['Blue'], 'sizes' => ['US 4'], 'size_chart' => [], 'additional_services' => []],
            'payload_hash' => str_repeat('d', 64), 'sync_status' => 'completed', 'synced_at' => now(),
        ]);
        $this->article('ask_pockets', 'Pocket construction');
        Http::fake(function () {
            static $completion = 0;
            $completion++;
            $output = $completion === 1 ? [
                'intents' => ['request_add_pockets'], 'customer_language' => 'en',
                'country_code' => null, 'event_date' => null, 'requested_currency' => null,
                'requested_language' => null, 'requires_private_reply' => false,
                'unmatched_requests' => [], 'summary' => '询问商品是否有口袋',
            ] : [
                'answer_en' => 'Yes, item QA100 has pockets.', 'answer_localized' => null,
                'used_fact_keys' => ['product.attributes.field_pockets'], 'unresolved_facts' => [],
                'safety_flags' => [], 'confidence' => 0.95,
            ];

            return Http::response(['id' => 'minimax', 'choices' => [['message' => ['content' => json_encode($output)]]]]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('generated', $draft->status, $draft->error_message ?? '');
        $this->assertSame(['ask_pockets'], $draft->intents);
        $this->assertSame('Yes', data_get($draft->facts, 'product.attributes.field_pockets'));
        $this->assertStringNotContainsString('add pockets', strtolower($draft->answer()));
    }

    public function test_missing_default_pocket_fact_uses_verified_silhouette_fallback_instead_of_only_deferring(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'Does the dress have pockets?');
        $product = $this->product($site);
        $product->update(['product_detail' => ['attributes' => ['field_silhouette' => ['A-Line']]]]);
        $ask = $this->article('ask_pockets', 'Pocket construction');
        $add = $this->article('request_add_pockets', 'Add pockets by silhouette');
        Http::fake(function () {
            static $completion = 0;
            $completion++;
            $output = $completion === 1 ? [
                'intents' => ['ask_pockets'], 'customer_language' => 'en',
                'country_code' => null, 'event_date' => null, 'requested_currency' => null,
                'requested_language' => null, 'requires_private_reply' => false,
                'unmatched_requests' => [], 'summary' => '询问是否有口袋',
            ] : [
                'answer_en' => 'The current product details do not confirm whether item QA100 includes pockets by default. However, pockets can be added at no extra charge. Please write “Please add pockets” in the Order Notes section at checkout.',
                'answer_localized' => null,
                'used_fact_keys' => ['product.attributes.field_silhouette'],
                'unresolved_facts' => [], 'safety_flags' => [], 'confidence' => 0.92,
            ];

            return Http::response(['id' => 'minimax', 'choices' => [['message' => ['content' => json_encode($output)]]]]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('generated', $draft->status, $draft->error_message ?? '');
        $this->assertSame(['ask_pockets', 'request_add_pockets'], $draft->intents);
        $this->assertEqualsCanonicalizing([$ask->id, $add->id], $draft->knowledge_article_ids);
        $this->assertSame([], $draft->missing_facts);
        $this->assertStringContainsString('do not confirm', $draft->answer());
        $this->assertStringContainsString('no extra charge', $draft->answer());
        $this->assertStringContainsString('Order Notes', $draft->answer());
        $this->assertStringNotContainsString('does not come with pockets', strtolower($draft->answer()));
    }

    public function test_add_pockets_uses_the_verified_non_mermaid_template_and_current_silhouette(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'This dress does not have pockets. Can you add them?');
        $product = $this->product($site);
        $product->update(['product_detail' => ['attributes' => ['field_silhouette' => ['A-Line']]]]);
        $this->article('request_add_pockets', 'Add pockets by silhouette');
        Http::fake(function () {
            static $completion = 0;
            $completion++;
            $output = $completion === 1 ? [
                'intents' => ['ask_pockets'], 'customer_language' => 'en',
                'country_code' => null, 'event_date' => null, 'requested_currency' => null,
                'requested_language' => null, 'requires_private_reply' => false,
                'unmatched_requests' => [], 'summary' => '客户要求增加口袋',
            ] : [
                'answer_en' => 'This item does not come with pockets by default, but we would be happy to add them at no extra charge. Please write “Please add pockets” in the Order Notes section at checkout.',
                'answer_localized' => null,
                'used_fact_keys' => ['product.attributes.field_silhouette'],
                'unresolved_facts' => [], 'safety_flags' => [], 'confidence' => 0.95,
            ];

            return Http::response(['id' => 'minimax', 'choices' => [['message' => ['content' => json_encode($output)]]]]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('generated', $draft->status, $draft->error_message ?? '');
        $this->assertSame(['request_add_pockets'], $draft->intents);
        $this->assertSame(['A-Line'], data_get($draft->facts, 'product.attributes.field_silhouette'));
        $this->assertStringContainsString('no extra charge', $draft->answer());
        $this->assertStringContainsString('Order Notes', $draft->answer());
    }

    public function test_add_pockets_rejects_a_promise_for_mermaid_trumpet_and_retries_the_draft(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'Can pockets be added?');
        $product = $this->product($site);
        $product->update(['product_detail' => ['attributes' => ['field_silhouette' => ['Mermaid/Trumpet']]]]);
        $this->article('request_add_pockets', 'Add pockets by silhouette');
        Http::fake(function () {
            static $completion = 0;
            $completion++;
            $output = match ($completion) {
                1 => [
                    'intents' => ['request_add_pockets'], 'customer_language' => 'en',
                    'country_code' => null, 'event_date' => null, 'requested_currency' => null,
                    'requested_language' => null, 'requires_private_reply' => false,
                    'unmatched_requests' => [], 'summary' => '客户要求增加口袋',
                ],
                2 => [
                    'answer_en' => 'Yes, we can add pockets in the Order Notes at no extra charge.',
                    'answer_localized' => null, 'used_fact_keys' => ['product.attributes.field_silhouette'],
                    'unresolved_facts' => [], 'safety_flags' => [], 'confidence' => 0.9,
                ],
                default => [
                    'answer_en' => 'Pockets are not recommended for item QA100 because its fitted Mermaid/Trumpet silhouette is not suitable for adding them.',
                    'answer_localized' => null, 'used_fact_keys' => ['product.attributes.field_silhouette'],
                    'unresolved_facts' => [], 'safety_flags' => [], 'confidence' => 0.95,
                ],
            };

            return Http::response(['id' => 'minimax-'.$completion, 'choices' => [['message' => ['content' => json_encode($output)]]]]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('generated', $draft->status, $draft->error_message ?? '');
        $this->assertStringContainsString('not suitable', $draft->answer());
        $this->assertStringNotContainsString('no extra charge', $draft->answer());
        $this->assertSame(1, $draft->usage['semantic_retries']);
    }

    public function test_add_pockets_is_blocked_when_the_current_product_silhouette_is_missing(): void
    {
        $site = $this->site();
        $question = $this->question($site, 'Could you add pockets to this dress?');
        $this->product($site);
        $this->article('request_add_pockets', 'Add pockets by silhouette');
        Http::fake(function () {
            static $completion = 0;
            $completion++;
            $output = $completion === 1 ? [
                'intents' => ['request_add_pockets'], 'customer_language' => 'en',
                'country_code' => null, 'event_date' => null, 'requested_currency' => null,
                'requested_language' => null, 'requires_private_reply' => false,
                'unmatched_requests' => [], 'summary' => '客户要求增加口袋',
            ] : [
                'answer_en' => 'We need to check whether this silhouette is suitable for adding pockets.',
                'answer_localized' => null, 'used_fact_keys' => [],
                'unresolved_facts' => ['product.attributes.field_silhouette'],
                'safety_flags' => [], 'confidence' => 0.6,
            ];

            return Http::response(['id' => 'minimax', 'choices' => [['message' => ['content' => json_encode($output)]]]]);
        });

        $draft = app(ProductQuestionDraftGenerator::class)->generate($question);

        $this->assertSame('blocked', $draft->status, $draft->error_message ?? '');
        $this->assertContains('product.attributes.field_silhouette', $draft->missing_facts);
        $this->assertStringNotContainsString('can add pockets', strtolower($draft->answer()));
    }

    private function site(): CommerceSite
    {
        return CommerceSite::create([
            'name' => 'DressAfford',
            'brand_name' => 'Dress Afford',
            'site_key' => 'dressafford',
            'admin_api_url' => 'https://dressafford.test/api/admin',
            'public_base_url' => 'https://www.dressafford.test',
            'customer_service_email' => 'support@dressafford.test',
            'api_token' => 'secret',
            'enabled' => true,
        ]);
    }

    private function question(CommerceSite $site, string $text): ProductQuestion
    {
        return ProductQuestion::create([
            'commerce_site_id' => $site->id, 'external_id' => 120, 'sn' => 'QA100',
            'product' => ['name' => 'Blue Dress'], 'name' => 'Customer', 'email' => 'customer@example.com',
            'type' => 1, 'type_text' => 'User question', 'question' => $text, 'reply_status' => 'unanswered',
            'needs_reply' => true, 'visible' => false, 'notice_status' => 0, 'notice_status_text' => 'Waiting',
            'images' => [], 'weight' => 0, 'helpful' => 0, 'status' => 1, 'status_text' => 'Open',
            'raw_payload' => [], 'remote_present' => true, 'synced_at' => now(),
        ]);
    }

    private function product(CommerceSite $site): CommerceProduct
    {
        return CommerceProduct::create([
            'commerce_site_id' => $site->id, 'sn' => 'QA100', 'name' => 'Blue Dress',
            'product_url' => 'https://dressafford.test/blue-dress.html',
            'product' => ['sn' => 'QA100', 'name' => 'Blue Dress', 'sell_price' => '99.00', 'processing_days_min' => 10, 'processing_days_max' => 15],
            'product_detail' => ['attributes' => ['fabric' => 'Chiffon']], 'images' => [],
            'options' => ['colors' => ['Blue'], 'sizes' => ['US 4'], 'size_chart' => [], 'additional_services' => []],
            'payload_hash' => str_repeat('b', 64), 'sync_status' => 'completed', 'synced_at' => now(),
        ]);
    }

    private function article(string $intent, string $title): KnowledgeArticle
    {
        return KnowledgeArticle::create([
            'key' => 'qa_global_'.$intent, 'title' => $title, 'knowledge_type' => 'policy',
            'status' => 'verified', 'automation_tier' => 'draft_low_risk', 'applicability' => 'Test applicability',
            'required_facts' => [], 'policy_rules' => ['Use current facts only.'], 'decision_tree' => [],
            'reply_strategy' => [], 'reply_templates' => [], 'action_steps' => [],
            'prohibited_claims' => ['Do not guarantee.'], 'escalation_rules' => [], 'version' => 1,
        ]);
    }

    private function administrator(): Administrator
    {
        $administrator = Administrator::create([
            'username' => 'draft-admin-test', 'password' => Hash::make('secret'), 'name' => 'Draft Admin',
        ]);
        $administrator->roles()->attach(Role::where('slug', 'administrator')->firstOrFail());

        return $administrator;
    }
}
