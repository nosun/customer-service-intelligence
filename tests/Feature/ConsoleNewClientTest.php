<?php

namespace Tests\Feature;

use App\Services\ConsoleNew\ConsoleNewClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConsoleNewClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.console_new.base_url' => 'https://console.test/api/admin',
            'services.console_new.token' => 'read-only-token',
            'services.console_new.timeout' => 15,
        ]);
    }

    public function test_it_finds_recent_paid_orders_by_exact_customer_email(): void
    {
        Http::fake(['*' => Http::response($this->listResponse([[
            'order_no' => 'drw20261016-10001',
            'site' => ['id' => 2, 'name' => 'Dorriswedding'],
            'status' => ['order_status_text' => '处理中', 'shipping_status_text' => '未发货'],
        ]]), 200)]);

        $result = app(ConsoleNewClient::class)->findOrdersByEmail('customer@example.com');

        $this->assertSame('drw20261016-10001', $result['items'][0]['order_no']);
        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame('request-list', $result['request_id']);
        $this->assertNotEmpty($result['response_hash']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://console.test/api/admin/orders?customer_email=customer%40example.com&page=1&page_size=10'
            && $request->hasHeader('Authorization', 'Bearer read-only-token'));
    }

    public function test_it_reads_work_order_production_status_by_order_number(): void
    {
        Http::fake(['*' => Http::response($this->listResponse([[
            'work_order_no' => 'WO-1001',
            'order_no' => 'drw20261016-10001',
            'work_process_text' => '生产中',
            'product_status_text' => '正常',
        ]]), 200)]);

        $result = app(ConsoleNewClient::class)->workOrders('drw20261016-10001');

        $this->assertSame('生产中', $result['items'][0]['work_process_text']);
        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://console.test/api/admin/work-orders?order_no=drw20261016-10001&page=1&page_size=100');
    }

    /** @param list<array<string, mixed>> $items */
    private function listResponse(array $items): array
    {
        return [
            'success' => true,
            'request_id' => 'request-list',
            'data' => [
                'items' => $items,
                'pagination' => [
                    'current_page' => 1,
                    'page_size' => 10,
                    'total' => count($items),
                    'last_page' => 1,
                ],
            ],
            'errors' => [],
        ];
    }
}
