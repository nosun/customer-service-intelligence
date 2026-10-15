<?php

namespace Tests\Feature;

use App\Models\BusinessContextSnapshot;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\ConversationEvent;
use App\Services\Cancellations\CancellationCaseVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CancellationCaseVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.console_new.base_url' => 'https://console.test/api/admin',
            'services.console_new.token' => 'read-only-token',
            'services.console_new.timeout' => 15,
        ]);
    }

    public function test_it_extracts_order_number_and_saves_a_redacted_cancellation_snapshot(): void
    {
        $conversation = $this->conversation('Cancellation Request');
        $this->event($conversation, 'Please cancel order dad20240528-33469.');
        Http::fake([
            'https://console.test/api/admin/orders/dad20240528-33469' => Http::response([
                'success' => true,
                'request_id' => 'request-1',
                'data' => [
                    'order_no' => 'dad20240528-33469',
                    'site' => ['name' => 'DressAfford', 'url' => 'https://www.dressafford.com'],
                    'customer' => ['email' => 'customer@example.com', 'phone' => 'private-phone'],
                    'shipping_address' => ['address' => 'private-address'],
                    'status' => [
                        'order_status' => -1,
                        'order_status_text' => '已取消',
                        'payment_status' => 1,
                        'payment_status_text' => '已支付',
                        'shipping_status' => 0,
                        'shipping_status_text' => '未发货',
                        'refund_status' => 1,
                        'cancellation_stage' => 'in_production',
                        'cancellation_reason' => 'customer request',
                    ],
                    'amounts' => ['total_amount' => 200, 'pay_amount' => 200, 'refund' => 160],
                    'time' => [
                        'created_at' => '2024-05-28 10:00:00',
                        'payment_time' => '2024-05-28 10:05:00',
                        'finished_at' => null,
                        'customer_canceled_at' => '2024-05-29 03:30:00',
                    ],
                    'shipping' => ['carrier' => null, 'tracking_no' => null, 'logistics_status' => 'NOT_SHIPPED'],
                    'items' => [[
                        'id' => 1,
                        'product_sn' => 'DA1',
                        'status' => -1,
                        'status_text' => '已取消',
                        'quantity' => 1,
                        'total_amount' => 200,
                    ]],
                    'refunds' => [[
                        'id' => 9,
                        'amount' => 160,
                        'currency' => 'USD',
                        'refund_type' => 'partial',
                        'reason' => 'Cancellation',
                        'refund_status' => 1,
                        'refund_status_text' => '已退',
                        'created_at' => '2024-05-29 03:00:00',
                        'refund_at' => '2024-05-29 03:30:00',
                    ]],
                ],
                'errors' => [],
            ]),
        ]);

        $result = app(CancellationCaseVerifier::class)->verify($conversation);

        $this->assertSame('found', $result['status']);
        $this->assertSame('cancelled_refund_recorded', $result['outcome']);
        $snapshot = $conversation->businessContextSnapshots()->firstOrFail();
        $this->assertTrue(data_get($snapshot->summary, 'identity_check.email_match'));
        $this->assertSame('manual_review_required', data_get($snapshot->summary, 'assessment.policy_review'));
        $encoded = json_encode($snapshot->summary);
        $this->assertStringNotContainsString('private-phone', $encoded);
        $this->assertStringNotContainsString('private-address', $encoded);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://console.test/api/admin/orders/dad20240528-33469'
                && $request->hasHeader('Authorization', 'Bearer read-only-token');
        });
    }

    public function test_it_does_not_guess_when_a_ticket_contains_multiple_order_numbers(): void
    {
        $conversation = $this->conversation('Cancel dad20240528-33469 and dad20240529-33470');
        Http::fake();

        $result = app(CancellationCaseVerifier::class)->verify($conversation);

        $this->assertSame('failed', $result['status']);
        $this->assertDatabaseHas('business_context_snapshots', [
            'conversation_id' => $conversation->id,
            'error_code' => 'ambiguous_order_number',
        ]);
        Http::assertNothingSent();
    }

    public function test_command_records_order_not_found_without_treating_it_as_an_api_failure(): void
    {
        $conversation = $this->conversation('Cancel order dad20240528-33469');
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'request_id' => 'request-404',
                'data' => null,
                'errors' => [['code' => 'ORDER_NOT_FOUND', 'message' => 'Order not found.', 'field' => 'order_no']],
            ], 404),
        ]);

        $this->artisan('helpdesk:cancellations:verify', ['--conversation-id' => $conversation->id])
            ->expectsOutputToContain('not_found | order_not_found')
            ->assertSuccessful();
        $this->assertDatabaseHas('business_context_snapshots', [
            'conversation_id' => $conversation->id,
            'lookup_status' => 'not_found',
            'outcome' => 'order_not_found',
        ]);
    }

    public function test_command_skips_successful_snapshots_before_applying_limit_and_force_rechecks_them(): void
    {
        $verified = $this->conversation('Cancel order dad20240528-33469');
        BusinessContextSnapshot::create([
            'conversation_id' => $verified->id,
            'source' => 'console_new',
            'context_type' => 'cancellation',
            'lookup_key' => 'dad20240528-33469',
            'lookup_status' => 'found',
            'outcome' => 'cancelled_refund_recorded',
            'summary' => [],
            'queried_at' => now(),
        ]);
        $pending = $this->conversation('Cancel order dad20240529-33470');
        Http::fake(['*' => $this->orderResponse('dad20240529-33470')]);

        $this->artisan('helpdesk:cancellations:verify', ['--limit' => 1])
            ->expectsOutputToContain("Conversation {$pending->id}")
            ->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/orders/dad20240529-33470'));

        Http::fake(['*' => $this->orderResponse('dad20240528-33469')]);
        $this->artisan('helpdesk:cancellations:verify', [
            '--conversation-id' => $verified->id,
            '--force' => true,
        ])->assertSuccessful();
        $this->assertSame(2, $verified->businessContextSnapshots()->count());
    }

    public function test_command_reports_when_a_selected_ticket_is_already_verified(): void
    {
        $conversation = $this->conversation('Cancel order dad20240528-33469');
        BusinessContextSnapshot::create([
            'conversation_id' => $conversation->id,
            'source' => 'console_new',
            'context_type' => 'cancellation',
            'lookup_key' => 'dad20240528-33469',
            'lookup_status' => 'found',
            'outcome' => 'cancelled_refund_recorded',
            'queried_at' => now(),
        ]);
        Http::fake();

        $this->artisan('helpdesk:cancellations:verify', ['--conversation-id' => $conversation->id])
            ->expectsOutput('没有待核验的取消 Ticket；已成功核验的案例默认跳过。')
            ->assertSuccessful();
        Http::assertNothingSent();
    }

    private function orderResponse(string $orderNumber)
    {
        return Http::response([
            'success' => true,
            'request_id' => 'request-'.$orderNumber,
            'data' => [
                'order_no' => $orderNumber,
                'site' => [],
                'customer' => ['email' => 'customer@example.com'],
                'status' => [
                    'order_status' => -1,
                    'order_status_text' => '已取消',
                    'shipping_status_text' => '未发货',
                    'refund_status' => 0,
                ],
                'amounts' => ['total_amount' => 100, 'pay_amount' => 100, 'refund' => 0],
                'time' => [],
                'shipping' => [],
                'items' => [],
                'refunds' => [],
            ],
            'errors' => [],
        ]);
    }

    private function conversation(string $subject): Conversation
    {
        $account = ChannelAccount::create([
            'channel' => 'helpdesk',
            'external_account_id' => uniqid('account-', true),
        ]);
        $conversation = Conversation::create([
            'channel_account_id' => $account->id,
            'external_id' => uniqid('ticket-', true),
            'external_short_id' => 'TEST-1',
            'site_key' => 'dressafford.com',
            'requester_email' => 'customer@example.com',
            'silo' => 'archive',
            'status' => 'solved',
            'subject' => $subject,
            'raw_payload' => [],
        ]);
        ConversationAnalysis::create([
            'conversation_id' => $conversation->id,
            'primary_category' => 'production',
            'primary_problem_type' => 'production_cancellation',
            'urgency' => 'high',
            'category_counts' => ['production' => 1],
            'problem_type_counts' => ['production_cancellation' => 1],
            'analyzer_version' => 'test',
            'analyzed_at' => now(),
        ]);

        return $conversation;
    }

    private function event(Conversation $conversation, string $body): void
    {
        ConversationEvent::create([
            'conversation_id' => $conversation->id,
            'external_id' => uniqid('event-', true),
            'event_type' => 'message',
            'occurred_at' => now(),
            'author_type' => 'client',
            'is_private' => false,
            'body_text' => $body,
            'content_hash' => hash('sha256', $body),
            'raw_payload' => [],
        ]);
    }
}
