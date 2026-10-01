<?php

namespace Tests\Feature;

use App\Services\AI\MiniMaxClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MiniMaxClientTest extends TestCase
{
    public function test_it_calls_the_openai_compatible_api_and_decodes_json(): void
    {
        Http::fake([
            'https://api.minimax.test/v1/chat/completions' => Http::response([
                'id' => 'request-1',
                'choices' => [[
                    'message' => [
                        'content' => "```json\n{\"case_summary\":\"测试\",\"nodes\":[]}\n```",
                    ],
                ]],
                'usage' => ['total_tokens' => 123],
            ]),
        ]);

        $result = (new MiniMaxClient('secret', 'https://api.minimax.test/v1', 'MiniMax-M3'))
            ->structuredCompletion('system', 'user');

        $this->assertSame('测试', $result['output']['case_summary']);
        $this->assertSame(123, $result['usage']['total_tokens']);
        $this->assertSame('request-1', $result['request_id']);
        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer secret')
                && $request['model'] === 'MiniMax-M3'
                && $request['thinking'] === ['type' => 'disabled']
                && $request['max_completion_tokens'] === 12000;
        });
    }

    public function test_it_rejects_missing_configuration_before_sending_a_request(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MiniMax is not configured');

        (new MiniMaxClient('', '', ''))->structuredCompletion('system', 'user');
    }
}
