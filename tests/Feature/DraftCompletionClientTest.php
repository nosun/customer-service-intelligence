<?php

namespace Tests\Feature;

use App\Services\AI\DraftCompletionClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DraftCompletionClientTest extends TestCase
{
    public function test_omniroute_uses_configured_model_json_output_and_no_minimax_parameters(): void
    {
        config(['services.draft_ai.provider' => 'omniroute', 'services.omniroute.api_key' => 'test-key', 'services.omniroute.base_url' => 'https://proxy.test/v1', 'services.omniroute.model' => 'codex/gpt-6.1-sol']);
        Http::preventStrayRequests();
        Http::fake(['proxy.test/v1/chat/completions' => Http::response(['id' => 'response-1', 'choices' => [['message' => ['content' => '{"answer_en":"Hello"}']]]])]);
        $result = app(DraftCompletionClient::class)->structuredCompletion('Follow the requested output schema.', 'Question');
        $this->assertSame('omniroute', $result['provider']);
        $this->assertSame('codex/gpt-6.1-sol', $result['model']);
        $this->assertSame('Hello', $result['output']['answer_en']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-key')
            && str_contains($request['messages'][0]['content'], 'JSON object')
            && str_contains($request['messages'][1]['content'], 'JSON object')
            && $request['model'] === 'codex/gpt-6.1-sol'
            && $request['response_format']['type'] === 'json_object'
            && ! isset($request['thinking']) && ! isset($request['temperature']));
    }

    public function test_execution_budget_is_bounded_and_covers_the_provider_timeout(): void
    {
        config(['services.draft_ai.provider' => 'omniroute']);
        foreach ([10 => 50, 90 => 110, 180 => 200, 900 => 200] as $configured => $expected) {
            config(['services.omniroute.timeout' => $configured]);
            $this->assertSame($expected, app(DraftCompletionClient::class)->executionTimeout());
        }
    }

    public function test_invalid_json_retries_once_within_the_completion_budget(): void
    {
        config(['services.draft_ai.provider' => 'omniroute', 'services.omniroute.api_key' => 'test-key', 'services.omniroute.base_url' => 'https://proxy.test/v1']);
        Http::preventStrayRequests();
        Http::fake(['proxy.test/v1/chat/completions' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => 'invalid']]]])
            ->push(['choices' => [['message' => ['content' => '{"answer_en":"Hello"}']]]])]);
        $this->assertSame('Hello', app(DraftCompletionClient::class)->structuredCompletion('JSON', 'Question')['output']['answer_en']);
        Http::assertSentCount(2);
    }

    public function test_provider_failure_is_not_silently_replaced_with_another_model(): void
    {
        config(['services.draft_ai.provider' => 'omniroute', 'services.omniroute.api_key' => 'test-key', 'services.omniroute.base_url' => 'https://proxy.test/v1']);
        Http::preventStrayRequests();
        Http::fake(['proxy.test/v1/chat/completions' => Http::response(['error' => 'Unauthorized'], 401)]);
        $this->expectException(RequestException::class);
        app(DraftCompletionClient::class)->structuredCompletion('Return JSON.', 'Question');
    }
}
