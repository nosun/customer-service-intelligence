<?php

namespace Tests\Feature;

use App\Models\CommerceProduct;
use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionSyncRun;
use App\Services\TradeManager\ProductCatalogSyncService;
use App\Services\TradeManager\ProductQuestionClient;
use App\Services\TradeManager\ProductQuestionSyncService;
use App\Services\TradeManager\TradeManagerApiException;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductQuestionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_configuration_encrypts_token_and_never_renders_it(): void
    {
        $this->actingAs($this->administrator(), 'admin');

        $this->post('/admin/commerce-sites', [
            'name' => 'DressAfford',
            'site_key' => 'dressafford',
            'admin_api_url' => 'https://dressafford.test/api/admin',
            'public_base_url' => 'https://www.dressafford.test',
            'api_token' => 'adm_super_secret',
            'enabled' => '1',
        ])->assertRedirect('/admin/commerce-sites');

        $site = CommerceSite::firstOrFail();
        $this->assertSame('adm_super_secret', $site->api_token);
        $this->assertSame('https://www.dressafford.test', $site->public_base_url);
        $this->assertNotSame('adm_super_secret', DB::table('commerce_sites')->value('api_token'));
        $this->get("/admin/commerce-sites/{$site->id}/edit")
            ->assertOk()
            ->assertDontSee('adm_super_secret');

        $operationInputs = DB::table(config('admin.database.operation_log_table'))->pluck('input')->implode('\n');
        $this->assertStringNotContainsString('adm_super_secret', $operationInputs);
    }

    public function test_administrator_can_read_questions_from_the_selected_site(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $site = $this->site();
        app(ProductQuestionSyncService::class)->storeQuestion($site, $this->question());

        $this->get("/admin/product-questions?site_id={$site->id}&reply_status=unanswered&sn=QA100")
            ->assertOk()
            ->assertSee('When will this ship?')
            ->assertSee('customer@example.com')
            ->assertSee('待回复');
        Http::assertNothingSent();
    }

    public function test_reply_requires_preview_and_confirm_reuses_the_saved_payload_and_idempotency_key(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $site = $this->site();
        app(ProductQuestionSyncService::class)->storeQuestion($site, $this->question());
        $previewCalls = 0;
        Http::fake(function (Request $request) use (&$previewCalls) {
            if ($request->method() === 'PATCH' && ($request->data()['preview'] ?? false)) {
                $previewCalls++;

                return Http::response([
                    'success' => true,
                    'code' => 'OK',
                    'data' => [
                        'operation' => [
                            'preview' => true,
                            'changes' => ['answer' => ['before' => null, 'after' => '<p>Ships in 48 hours.</p>']],
                            'confirm_token' => 'confirm-token',
                            'confirm_expires_at' => '2026-10-14 13:00:00',
                        ],
                        'notification' => ['status' => 'will_queue'],
                    ],
                    'request_id' => 'req-preview',
                ]);
            }

            return Http::response([
                'success' => true,
                'code' => 'OK',
                'data' => [
                    'question' => $this->question(['answer' => '<p>Ships in 48 hours.</p>', 'reply_status' => 'answered']),
                    'notification' => ['status' => 'queued'],
                ],
                'request_id' => 'req-confirm',
            ]);
        });

        $this->post('/admin/product-questions/120/confirm-reply', ['site_id' => $site->id])
            ->assertStatus(422);

        $this->post('/admin/product-questions/120/preview-reply', [
            'site_id' => $site->id,
            'answer' => '<p>Ships in 48 hours.</p>',
            'visible' => '1',
            'reason' => 'Ticket CS-300 reviewed',
        ])->assertRedirect("/admin/product-questions/120?site_id={$site->id}");
        $this->assertSame(1, $previewCalls);

        $saved = session("product_question_preview.{$site->id}.120");
        $this->assertSame('confirm-token', $saved['confirm_token']);
        $this->assertNotEmpty($saved['idempotency_key']);
        $this->get("/admin/product-questions/120?site_id={$site->id}")
            ->assertOk()
            ->assertSee('确认回复差异')
            ->assertSee('Ships in 48 hours.')
            ->assertSee('will_queue');

        $this->post('/admin/product-questions/120/confirm-reply', ['site_id' => $site->id])
            ->assertRedirect("/admin/product-questions/120?site_id={$site->id}");
        $this->assertNull(session("product_question_preview.{$site->id}.120"));
        $this->assertDatabaseHas('product_questions', [
            'commerce_site_id' => $site->id,
            'external_id' => 120,
            'reply_status' => 'answered',
        ]);

        Http::assertSent(function (Request $request) use ($saved): bool {
            return $request->method() === 'PATCH'
                && ($request->data()['confirm_token'] ?? null) === 'confirm-token'
                && ($request->data()['answer'] ?? null) === '<p>Ships in 48 hours.</p>'
                && ($request->data()['visible'] ?? null) === 1
                && ($request->data()['reason'] ?? null) === 'Ticket CS-300 reviewed'
                && $request->hasHeader('Idempotency-Key', $saved['idempotency_key']);
        });
    }

    public function test_failed_confirm_keeps_preview_and_same_idempotency_key_for_retry(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $site = $this->site();
        $sessionKey = "product_question_preview.{$site->id}.120";
        $preview = [
            'payload' => ['answer' => 'Answer', 'visible' => 1, 'reason' => 'CS-301'],
            'confirm_token' => 'confirm-token',
            'idempotency_key' => 'fixed-idempotency-key',
        ];
        session()->put($sessionKey, $preview);
        Http::fake(['*' => Http::response([
            'success' => false,
            'code' => 'IDEMPOTENCY_IN_PROGRESS',
            'message' => 'Still processing.',
            'data' => null,
            'request_id' => 'req-progress',
        ], 409)]);

        $this->from("/admin/product-questions/120?site_id={$site->id}")
            ->post('/admin/product-questions/120/confirm-reply', ['site_id' => $site->id])
            ->assertRedirect("/admin/product-questions/120?site_id={$site->id}");

        $this->assertSame($preview, session($sessionKey));
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'fixed-idempotency-key'));
    }

    public function test_client_preserves_admin_api_error_context(): void
    {
        $site = $this->site();
        Http::fake(['*' => Http::response([
            'success' => false,
            'code' => 'FORBIDDEN',
            'message' => 'Missing permission.',
            'data' => null,
            'request_id' => 'req-forbidden',
        ], 403)]);

        try {
            app(ProductQuestionClient::class)->questions($site);
            $this->fail('Expected TradeManagerApiException.');
        } catch (TradeManagerApiException $exception) {
            $this->assertSame(403, $exception->statusCode);
            $this->assertSame('FORBIDDEN', $exception->errorCode);
            $this->assertSame('req-forbidden', $exception->requestId);
        }
    }

    public function test_full_sync_saves_every_page_preserves_analysis_and_marks_missing_records(): void
    {
        $site = $this->site();
        $reviewed = app(ProductQuestionSyncService::class)->storeQuestion($site, $this->question());
        $reviewed->update(['review_status' => 'reviewed', 'analysis_notes' => 'Keep this review.', 'tags' => ['shipping']]);
        app(ProductQuestionSyncService::class)->storeQuestion($site, $this->question([
            'id' => 999,
            'question' => 'Removed remotely',
        ]));
        Http::fake(function (Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
            $page = (int) ($query['page'] ?? 1);
            $items = $page === 1
                ? [
                    $this->question(['question' => 'Updated question']),
                    $this->question(['id' => 121, 'question' => 'Second question']),
                ]
                : [$this->question(['id' => 122, 'question' => 'Third question'])];

            return Http::response([
                'success' => true,
                'code' => 'OK',
                'data' => [
                    'items' => $items,
                    'pagination' => ['current_page' => $page, 'per_page' => 2, 'total' => 3, 'last_page' => 2],
                ],
                'request_id' => 'req-page-'.$page,
            ]);
        });

        $progress = [];
        $result = app(ProductQuestionSyncService::class)->sync(
            $site,
            2,
            function (int $page, int $lastPage, int $seen) use (&$progress): void {
                $progress[] = [$page, $lastPage, $seen];
            },
        );

        $this->assertSame(3, $result['records_seen']);
        $this->assertSame(1, $result['missing_marked']);
        $this->assertSame([[1, 2, 2], [2, 2, 3]], $progress);
        $this->assertSame(3, ProductQuestion::where('remote_present', true)->count());
        $this->assertFalse(ProductQuestion::where('external_id', 999)->firstOrFail()->remote_present);
        $reviewed->refresh();
        $this->assertSame('Updated question', $reviewed->question);
        $this->assertSame('reviewed', $reviewed->review_status);
        $this->assertSame('Keep this review.', $reviewed->analysis_notes);
        $this->assertSame(['shipping'], $reviewed->tags);
        $this->assertSame('completed', $site->refresh()->product_question_sync_status);
        $this->assertNotNull($site->product_questions_last_synced_at);
        $this->assertDatabaseHas('product_question_sync_runs', [
            'commerce_site_id' => $site->id,
            'status' => 'completed',
            'reported_total' => 3,
            'records_seen' => 3,
            'missing_marked' => 1,
        ]);
    }

    public function test_sync_persists_new_question_status_and_reply_provenance_fields(): void
    {
        $site = $this->site();
        $question = app(ProductQuestionSyncService::class)->storeQuestion($site, $this->question([
            'status' => 0,
            'status_text' => 'Closed',
            'reply_operator' => [
                'admin_user_id' => 8,
                'name' => 'AI Agent',
                'source' => 'api',
                'replied_at' => '2026-10-15 10:00:00',
            ],
            'close_reason' => 'Duplicate question',
            'close_operator' => [
                'admin_user_id' => 9,
                'name' => 'Reviewer',
                'source' => 'admin',
                'closed_at' => '2026-10-15 11:00:00',
            ],
        ]));

        $this->assertSame(0, $question->status);
        $this->assertSame('api', $question->reply_source);
        $this->assertSame('AI Agent', $question->reply_operator['name']);
        $this->assertNotNull($question->replied_at);
        $this->assertSame('Duplicate question', $question->close_reason);
        $this->assertSame('admin', $question->close_operator['source']);
    }

    public function test_product_catalog_sync_saves_product_attributes_images_and_options(): void
    {
        $site = $this->site();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/products/QA100/options')) {
                return Http::response([
                    'success' => true,
                    'data' => [
                        'product' => ['sn' => 'QA100', 'name' => 'Blue Dress'],
                        'colors' => ['Blue', 'Red'],
                        'sizes' => ['US 2', 'US 4'],
                        'stock_specs' => [],
                        'additional_services' => [['name' => 'Custom size']],
                    ],
                    'request_id' => 'product-options',
                ]);
            }

            return Http::response([
                'success' => true,
                'data' => [
                    'product' => [
                        'sn' => 'QA100', 'name' => 'Blue Dress', 'status' => 1, 'visible' => 1,
                        'status_text' => 'Published', 'product_url' => 'https://dressafford.test/products/blue-dress.html',
                    ],
                    'product_detail' => ['attributes' => ['field_fabric' => 'Chiffon']],
                    'images' => [['url' => 'https://cdn.test/qa100.jpg', 'is_main' => true]],
                ],
                'request_id' => 'product-show',
            ]);
        });

        $result = app(ProductCatalogSyncService::class)->sync($site, 'QA100');

        $this->assertSame('completed', $result['status']);
        $product = CommerceProduct::firstOrFail();
        $this->assertSame('Blue Dress', $product->name);
        $this->assertSame('Chiffon', $product->product_detail['attributes']['field_fabric']);
        $this->assertSame('https://cdn.test/qa100.jpg', $product->images[0]['url']);
        $this->assertSame(['Blue', 'Red'], $product->options['colors']);
        Http::assertSentCount(2);
    }

    public function test_failed_snapshot_does_not_mark_existing_questions_missing(): void
    {
        $site = $this->site();
        $existing = app(ProductQuestionSyncService::class)->storeQuestion($site, $this->question(['id' => 999]));
        Http::fake(function (Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
            $page = (int) ($query['page'] ?? 1);

            return Http::response([
                'success' => true,
                'code' => 'OK',
                'data' => [
                    'items' => [$this->question(['id' => $page])],
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => 1,
                        'total' => $page === 1 ? 2 : 3,
                        'last_page' => 2,
                    ],
                ],
                'request_id' => 'req-'.$page,
            ]);
        });

        try {
            app(ProductQuestionSyncService::class)->sync($site, 1);
            $this->fail('Expected an inconsistent snapshot failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('total changed', $exception->getMessage());
        }

        $this->assertTrue($existing->refresh()->remote_present);
        $this->assertSame('failed', $site->refresh()->product_question_sync_status);
        $this->assertSame('failed', ProductQuestionSyncRun::latest('id')->firstOrFail()->status);
    }

    public function test_administrator_can_save_local_question_analysis(): void
    {
        $this->actingAs($this->administrator(), 'admin');
        $site = $this->site();
        app(ProductQuestionSyncService::class)->storeQuestion($site, $this->question());

        $this->from("/admin/product-questions/120?site_id={$site->id}")
            ->post('/admin/product-questions/120/review', [
                'site_id' => $site->id,
                'review_status' => 'reviewed',
                'tags' => 'shipping, delivery，shipping',
                'analysis_notes' => 'Clear presales shipping question.',
            ])
            ->assertRedirect("/admin/product-questions/120?site_id={$site->id}");

        $question = ProductQuestion::firstOrFail();
        $this->assertSame('reviewed', $question->review_status);
        $this->assertSame(['shipping', 'delivery'], $question->tags);
        $this->assertSame('Clear presales shipping question.', $question->analysis_notes);
        $this->assertNotNull($question->reviewed_at);
    }

    public function test_qa_menus_are_created_by_migration(): void
    {
        $this->assertDatabaseHas(config('admin.database.menu_table'), ['title' => '售前问答', 'uri' => 'product-questions']);
        $this->assertDatabaseHas(config('admin.database.menu_table'), ['title' => '站点配置', 'uri' => 'commerce-sites']);
    }

    private function administrator(): Administrator
    {
        $administrator = Administrator::create([
            'username' => 'qa-admin-test',
            'password' => Hash::make('secret'),
            'name' => 'QA Admin Test',
        ]);
        $administrator->roles()->attach(Role::where('slug', 'administrator')->firstOrFail());

        return $administrator;
    }

    private function site(): CommerceSite
    {
        return CommerceSite::create([
            'name' => 'DressAfford',
            'site_key' => 'dressafford',
            'admin_api_url' => 'https://dressafford.test/api/admin',
            'api_token' => 'adm_site_token',
            'enabled' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function question(array $overrides = []): array
    {
        return array_replace([
            'id' => 120,
            'sn' => 'QA100',
            'product' => ['name' => 'Blue Dress'],
            'name' => 'Customer',
            'email' => 'customer@example.com',
            'type' => 1,
            'type_text' => 'User question',
            'question' => 'When will this ship?',
            'answer' => null,
            'reply_status' => 'unanswered',
            'needs_reply' => true,
            'visible' => false,
            'notice_status' => 0,
            'notice_status_text' => 'Waiting',
            'images' => [],
            'helpful' => 0,
            'created_at' => '2026-10-14 12:00:00',
        ], $overrides);
    }
}
