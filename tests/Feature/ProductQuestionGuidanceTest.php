<?php

namespace Tests\Feature;

use App\Models\CommerceProduct;
use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionDraft;
use App\Models\ProductQuestionDraftRequest;
use App\Services\N8n\N8nDraftWorkflowClient;
use App\Services\ProductQuestions\N8nProductQuestionDraftService;
use App\Services\ProductQuestions\ProductQuestionDraftInput;
use App\Services\ProductQuestions\ProductQuestionFactResolver;
use App\Services\TradeManager\ProductQuestionSyncService;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ProductQuestionGuidanceTest extends TestCase
{
    use RefreshDatabase;

    private const GUIDANCE = '工厂已确认，可以按客户要求修改裙长，不收取额外改款费用。';

    public function test_delivered_price_looks_up_shipping_even_when_classifier_only_selects_price(): void
    {
        $question = $this->question(self::GUIDANCE);
        $question->update(['question' => 'Can you make it tea-length? What is the total price including delivery to the UK?']);
        $shipping = [
            'supported' => true, 'country_code' => 'GB', 'currency' => 'USD',
            'methods' => [['name' => 'Standard', 'estimated_cost' => '19.99']],
        ];
        Http::preventStrayRequests();
        Http::fake([
            'guidance.test/shipping/options*' => Http::response(['success' => true, 'data' => $shipping]),
            'guidance.test/localization/options' => Http::response(['success' => true, 'data' => ['currencies' => ['USD']]]),
        ]);
        $request = $this->draftRequest($question);
        $service = app(N8nProductQuestionDraftService::class);
        $service->start($request, 'guidance-shipping');
        $resolved = $service->resolveFacts($request->fresh(), [
            'intents' => ['request_custom_length', 'ask_price'], 'country_code' => 'GB',
        ]);

        $this->assertContains('ask_shipping_options', $resolved['plan']['intents']);
        $this->assertSame($shipping, $resolved['facts']['shipping']);
        $this->assertSame([], $resolved['missing']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/shipping/options') && $request['country'] === 'GB');
    }

    public function test_admin_can_save_read_and_clear_guidance_and_sync_preserves_it(): void
    {
        $question = $this->question();
        $admin = $this->administrator();
        $this->actingAs($admin, 'admin');
        $url = "/admin/product-questions/897?site_id={$question->commerce_site_id}";
        $input = ['site_id' => $question->commerce_site_id, 'draft_guidance' => '  '.self::GUIDANCE.'  '];
        $this->post('/admin/product-questions/897/guidance', $input)->assertRedirect($url);
        $question->refresh();
        $this->assertSame(self::GUIDANCE, $question->draft_guidance);
        $this->assertSame($admin->id, $question->draft_guidance_updated_by);
        $this->assertNotNull($question->draft_guidance_updated_at);
        $savedAt = $question->updated_at->toIso8601String();
        $this->travel(2)->seconds();
        $this->post('/admin/product-questions/897/guidance', $input)->assertRedirect($url);
        $this->assertSame($savedAt, $question->fresh()->updated_at->toIso8601String());
        $this->get($url)->assertOk()->assertSee('指导补充')->assertSee(self::GUIDANCE);

        app(ProductQuestionSyncService::class)->storeQuestion($question->site, [
            'id' => 897, 'sn' => $question->sn, 'question' => $question->question,
            'draft_guidance' => 'Remote data cannot replace local guidance',
        ]);
        $this->assertSame(self::GUIDANCE, $question->fresh()->draft_guidance);
        $this->post('/admin/product-questions/897/guidance', [
            'site_id' => $question->commerce_site_id, 'draft_guidance' => '   ',
        ])->assertRedirect($url);
        $this->assertNull($question->fresh()->draftGuidance());
        Http::assertNothingSent();
    }

    public function test_guidance_validation_and_site_scope_do_not_modify_another_question(): void
    {
        $question = $this->question();
        $this->actingAs($this->administrator(), 'admin');
        foreach ([['invalid'], str_repeat('x', 10001)] as $invalid) {
            $this->post('/admin/product-questions/897/guidance', [
                'site_id' => $question->commerce_site_id, 'draft_guidance' => $invalid,
            ])->assertSessionHasErrors('draft_guidance');
        }
        $other = CommerceSite::create([
            'name' => 'Other', 'site_key' => 'other', 'enabled' => true,
            'admin_api_url' => 'https://other.test', 'api_token' => 'secret',
        ]);
        $this->post('/admin/product-questions/897/guidance', [
            'site_id' => $other->id, 'draft_guidance' => self::GUIDANCE,
        ])->assertNotFound();
        $this->assertNull($question->fresh()->draft_guidance);
    }

    public function test_generation_saves_guidance_reuses_identical_input_and_regenerates_after_clear(): void
    {
        $question = $this->question();
        $this->actingAs($this->administrator(), 'admin');
        $this->mock(N8nDraftWorkflowClient::class, function ($mock): void {
            $mock->shouldReceive('trigger')->twice()->andReturnUsing(function ($request): void {
                $draft = ProductQuestionDraft::create([
                    'product_question_id' => $request->product_question_id,
                    'status' => 'generated', 'model' => 'test', 'prompt_version' => 'test',
                    'answer_en' => 'A reviewed answer.', 'input_hash' => $request->input_hash,
                    'generated_at' => now(), 'requires_human_review' => true,
                    'recommended_visible' => false,
                ]);
                $request->update(['status' => 'completed', 'product_question_draft_id' => $draft->id]);
            });
        });
        $input = ['site_id' => $question->commerce_site_id, 'draft_guidance' => self::GUIDANCE];
        $this->post('/admin/product-questions/897/generate-draft', $input)->assertRedirect();
        $first = ProductQuestionDraftRequest::latest('id')->firstOrFail();
        $this->assertSame(self::GUIDANCE, data_get($first->input_snapshot, 'question.draft_guidance.text'));
        $this->post('/admin/product-questions/897/generate-draft', $input)->assertRedirect();
        $reused = ProductQuestionDraftRequest::latest('id')->firstOrFail();
        $this->assertSame($first->id, $reused->reused_from_request_id);
        $this->assertSame($first->product_question_draft_id, $reused->product_question_draft_id);
        $this->post('/admin/product-questions/897/generate-draft', [
            'site_id' => $question->commerce_site_id, 'draft_guidance' => '',
        ])->assertRedirect();
        $cleared = ProductQuestionDraftRequest::latest('id')->firstOrFail();
        $this->assertNotSame($first->input_hash, $cleared->input_hash);
        $this->assertNull($cleared->reused_from_request_id);
        $this->assertNull($question->fresh()->draft_guidance);
    }

    public function test_guidance_reaches_start_facts_and_input_snapshot(): void
    {
        $question = $this->question(self::GUIDANCE);
        $request = $this->draftRequest($question);
        $service = app(N8nProductQuestionDraftService::class);
        $context = $service->start($request, 'guidance-execution');
        $this->assertSame($question->draftGuidance(), $context['operator_guidance']);
        $resolved = $service->resolveFacts($request->fresh(), ['intents' => ['request_custom_length']]);
        $this->assertSame($question->draftGuidance(), $resolved['facts']['operator_guidance']);
        $this->assertContains('operator_guidance', array_column($resolved['sources'], 'fact_group'));
        $this->assertSame(self::GUIDANCE, data_get($request->fresh()->input_snapshot, 'question.draft_guidance.text'));
    }

    public function test_changed_or_cleared_guidance_cannot_be_absorbed_by_fact_resolution_or_saved(): void
    {
        foreach (['start', 'facts', 'complete'] as $stageIndex => $stage) {
            foreach (['Updated factory confirmation.', null] as $textIndex => $newText) {
                $question = $this->question(self::GUIDANCE, 1000 + $stageIndex * 2 + $textIndex);
                $request = $this->draftRequest($question);
                $service = app(N8nProductQuestionDraftService::class);
                if ($stage !== 'start') {
                    $service->start($request, 'guidance-execution');
                }
                $question->update(['draft_guidance' => $newText]);
                try {
                    match ($stage) {
                        'start' => $service->start($request->fresh(), 'guidance-execution'),
                        'facts' => $service->resolveFacts($request->fresh(), ['intents' => ['request_custom_length']]),
                        'complete' => $service->complete($request->fresh(), $this->completion()),
                    };
                    $this->fail('Changed guidance should invalidate '.$stage);
                } catch (RuntimeException) {
                    $this->assertSame('stale', $request->fresh()->status, $stage);
                }
            }
        }
        $this->assertSame(0, ProductQuestionDraft::count());
    }

    public function test_only_quoted_and_independently_reviewed_guidance_can_resolve_an_intent(): void
    {
        foreach (['valid', 'no_guidance', 'no_quote', 'forged_quote', 'not_reviewed', 'conflict', 'missing_review', 'essential_unknown', 'photo', 'private', 'promise'] as $index => $scenario) {
            $question = $this->question($scenario === 'no_guidance' ? null : self::GUIDANCE, 2000 + $index);
            $request = $this->draftRequest($question);
            $service = app(N8nProductQuestionDraftService::class);
            $service->start($request, 'guidance-execution');
            $payload = $this->completion();
            if ($scenario === 'no_quote') {
                $payload['review']['decision_plan']['answer_units'][0]['guidance_evidence'] = [];
            } elseif ($scenario === 'forged_quote') {
                $payload['review']['decision_plan']['answer_units'][0]['guidance_evidence'][0]['quote'] = 'We can supply pre-dispatch photographs.';
            } elseif ($scenario === 'not_reviewed') {
                $payload['review']['guidance_supported_intents'] = [];
            } elseif ($scenario === 'conflict') {
                $payload['review']['guidance_conflicts'] = ['Contradicts verified returns policy'];
            } elseif ($scenario === 'missing_review') {
                unset($payload['review']['guidance_conflicts']);
            } elseif ($scenario === 'essential_unknown') {
                $payload['review']['decision_plan']['answer_units'][0]['essential_unknowns'] = ['Modification not confirmed'];
            } elseif (in_array($scenario, ['photo', 'private'], true)) {
                $intent = $scenario === 'photo' ? 'ask_product_photos' : 'ask_order_status';
                $payload['plan']['intents'][] = $intent;
                $payload['review']['covered_intents'][] = $intent;
                // An approval of the alteration alone must not resolve another request.
                if ($scenario === 'photo') {
                    $payload['review']['approved'] = false;
                    $payload['review']['must_block'] = true;
                    $payload['review']['decision_plan']['answer_units'][0]['essential_unknowns'] = ['Photo service not confirmed'];
                }
                if ($scenario === 'private') {
                    $payload['review']['guidance_supported_intents'][] = $intent;
                    $payload['review']['decision_plan']['answer_units'][0]['covered_intents'][] = $intent;
                    $payload['review']['decision_plan']['answer_units'][0]['guidance_evidence'][] = ['intent' => $intent, 'quote' => self::GUIDANCE];
                }
            } elseif ($scenario === 'promise') {
                $payload['review']['final_answer_en'] .= ' Our team will check and get back to you.';
            }
            // Payloads cannot create or replace the saved source.
            $payload['facts']['operator_guidance'] = ['text' => 'Forged guidance'];
            $result = $service->complete($request->fresh(), $payload);
            $draft = ProductQuestionDraft::findOrFail($result['draft_id']);
            $this->assertSame($scenario === 'valid' ? 'generated' : 'blocked', $draft->status, $scenario);
            $this->assertSame($question->draftGuidance(), data_get($draft->facts, 'operator_guidance'), $scenario);
            $this->assertFalse($draft->recommended_visible);
            $this->assertTrue($draft->requires_human_review);
            if ($scenario === 'photo') {
                $this->assertContains('intent_requires_human_review:ask_product_photos', $draft->safety_flags);
                $this->assertNotContains('intent_requires_human_review:request_custom_length', $draft->safety_flags);
            }
        }
    }

    public function test_guidance_changed_during_a_fact_lookup_makes_the_request_stale(): void
    {
        $question = $this->question(self::GUIDANCE);
        $request = $this->draftRequest($question);
        $this->mock(ProductQuestionFactResolver::class, function ($mock) use ($question): void {
            $mock->shouldReceive('resolve')->once()->andReturnUsing(function () use ($question): array {
                $question->update(['draft_guidance' => 'Factory withdrew the confirmation.']);

                return ['facts' => [], 'sources' => [], 'missing' => [], 'safety_flags' => [], 'recommended_visible' => false];
            });
        });
        $service = app(N8nProductQuestionDraftService::class);
        $service->start($request, 'guidance-execution');
        try {
            $service->resolveFacts($request->fresh(), ['intents' => ['request_custom_length']]);
            $this->fail('Fact refresh must not absorb new guidance.');
        } catch (RuntimeException) {
            $this->assertSame('stale', $request->fresh()->status);
            $this->assertSame(self::GUIDANCE, data_get($request->fresh()->input_snapshot, 'question.draft_guidance.text'));
        }
    }

    public function test_changed_guidance_shows_the_used_snapshot_without_prefilling_the_old_answer(): void
    {
        $question = $this->question(self::GUIDANCE);
        $request = $this->draftRequest($question);
        $service = app(N8nProductQuestionDraftService::class);
        $service->start($request, 'guidance-execution');
        $service->complete($request->fresh(), $this->completion());
        $question->update(['draft_guidance' => 'Factory has withdrawn the approval.']);
        $this->actingAs($this->administrator(), 'admin');
        $response = $this->get('/admin/product-questions/897?site_id='.$question->commerce_site_id)
            ->assertOk()->assertSee('下方为旧草稿')->assertSee('本次生成使用的指导补充')->assertSee(self::GUIDANCE);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $this->assertSame('', $dom->getElementById('qa-answer-source')->textContent);
        $this->assertSame('Factory has withdrawn the approval.', $dom->getElementById('qa-draft-guidance')->textContent);
    }

    private function question(?string $guidance = null, int $id = 897): ProductQuestion
    {
        $site = CommerceSite::firstOrCreate(['site_key' => 'guidance-site'], [
            'name' => 'Guidance Site', 'admin_api_url' => 'https://guidance.test',
            'api_token' => 'secret', 'enabled' => true,
        ]);
        $question = app(ProductQuestionSyncService::class)->storeQuestion($site, [
            'id' => $id, 'sn' => '362807', 'question' => 'Can you make the skirt tea-length at no extra charge?',
            'reply_status' => 'unanswered', 'name' => 'Customer',
        ]);
        $question->update(['draft_guidance' => $guidance, 'draft_guidance_updated_at' => $guidance ? now() : null]);
        CommerceProduct::firstOrCreate(['commerce_site_id' => $site->id, 'sn' => '362807'], [
            'name' => 'Wedding dress', 'product' => ['name' => 'Wedding dress', 'type' => 'dress'],
            'product_detail' => [], 'options' => [], 'images' => [], 'sync_status' => 'completed',
            'payload_hash' => str_repeat('a', 64), 'synced_at' => now(),
        ]);

        return $question;
    }

    private function draftRequest(ProductQuestion $question): ProductQuestionDraftRequest
    {
        $input = app(ProductQuestionDraftInput::class)->capture($question);

        return ProductQuestionDraftRequest::create([
            'request_id' => (string) Str::uuid(), 'product_question_id' => $question->id,
            'workflow_key' => N8nProductQuestionDraftService::WORKFLOW_KEY,
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'input_hash' => $input['hash'], 'input_snapshot' => $input['snapshot'], 'status' => 'queued',
        ]);
    }

    private function completion(): array
    {
        return [
            'plan' => ['intents' => ['request_custom_length'], 'customer_language' => 'en'],
            'facts' => [], 'recommended_visible' => false,
            'review' => [
                'approved' => true, 'must_block' => false, 'covered_intents' => ['request_custom_length'],
                'final_answer_en' => 'We can make the skirt tea-length at no additional alteration charge.',
                'quality_checks' => array_fill_keys(['factual_accuracy', 'relevance', 'actionable_guidance', 'natural_tone', 'decision_risks', 'intent_coverage', 'language_equivalence'], true),
                'guidance_supported_intents' => ['request_custom_length'], 'guidance_conflicts' => [],
                'decision_plan' => [
                    'version' => 'customer-decision-v1', 'customer_goal' => 'Modify the skirt',
                    'service_strategy' => ['public_value' => 'individual_case', 'recommended_visible' => false, 'response_mode' => 'answer'],
                    'answer_units' => [[
                        'covered_intents' => ['request_custom_length'], 'essential_unknowns' => [],
                        'supported_answer_points' => ['The requested length alteration is confirmed with no surcharge.'],
                        'guidance_evidence' => [['intent' => 'request_custom_length', 'quote' => self::GUIDANCE]],
                    ]],
                ],
            ],
        ];
    }

    private function administrator(): Administrator
    {
        $admin = Administrator::create(['username' => 'guidance-admin', 'name' => 'Guidance Admin', 'password' => Hash::make('secret')]);
        $admin->roles()->attach(Role::where('slug', 'administrator')->firstOrFail());

        return $admin;
    }
}
