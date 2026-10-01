<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchChatBotSampleTest extends TestCase
{
    public function test_it_saves_a_private_read_only_sample(): void
    {
        config([
            'services.chatbot.access_token' => 'secret',
            'services.chatbot.base_url' => 'https://api.chatbot.test',
        ]);
        Http::fake([
            'https://api.chatbot.test/v2/stories' => Http::response([
                ['id' => 'story-1', 'name' => 'Sales'],
            ]),
            'https://api.chatbot.test/v2/stories/story-1' => Http::response([
                'id' => 'story-1', 'diagram' => ['id' => 'start'],
            ]),
            'https://api.chatbot.test/v2/chats' => Http::response([
                ['id' => 'chat-1'],
            ]),
            'https://api.chatbot.test/v2/chats/chat-1' => Http::response([
                'id' => 'chat-1', 'messages' => [],
            ]),
            'https://api.chatbot.test/training' => Http::response(['items' => []]),
        ]);
        $relativePath = 'storage/app/private/chatbot/test-api-sample.json';
        $path = base_path($relativePath);

        $this->artisan('chatbot:sample', ['--chat-limit' => 1, '--output' => $relativePath])
            ->assertSuccessful();

        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('start', $payload['stories'][0]['diagram']['id']);
        $this->assertSame('chat-1', $payload['chats'][0]['id']);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

        unlink($path);
    }
}
