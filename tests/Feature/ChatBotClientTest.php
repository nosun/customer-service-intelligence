<?php

namespace Tests\Feature;

use App\Services\ChatBot\ChatBotClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ChatBotClientTest extends TestCase
{
    public function test_it_reads_stories_chats_and_training_with_a_bearer_token(): void
    {
        Http::fake([
            'https://api.chatbot.test/v2/stories' => Http::response([
                ['id' => 'story-1', 'name' => 'Sales'],
            ]),
            'https://api.chatbot.test/v2/stories/story-1' => Http::response([
                'id' => 'story-1',
                'diagram' => ['id' => 'start', 'type' => 'START_POINT', 'children' => []],
            ]),
            'https://api.chatbot.test/v2/chats?after=123' => Http::response([
                ['id' => 'chat-1', 'createdAt' => '2026-09-30T00:00:00Z'],
            ]),
            'https://api.chatbot.test/v2/chats/chat-1' => Http::response([
                'id' => 'chat-1', 'storyId' => 'story-1', 'messages' => [],
            ]),
            'https://api.chatbot.test/training' => Http::response([
                'items' => [['id' => 'phrase-1', 'text' => 'custom sizing']],
            ]),
        ]);

        $client = new ChatBotClient('secret', 'https://api.chatbot.test');

        $this->assertSame('story-1', $client->listStories()[0]['id']);
        $this->assertSame('START_POINT', $client->getStory('story-1')['diagram']['type']);
        $this->assertSame('chat-1', $client->listChats(123)[0]['id']);
        $this->assertSame('story-1', $client->getChat('chat-1')['storyId']);
        $this->assertSame('phrase-1', $client->listTrainingPhrases()[0]['id']);

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer secret'));
        Http::assertSentCount(5);
    }

    public function test_it_rejects_missing_credentials_before_sending_a_request(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ChatBot credentials are missing');

        try {
            (new ChatBotClient('', 'https://api.chatbot.test'))->listStories();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_it_rejects_an_invalid_training_response(): void
    {
        Http::fake(['*' => Http::response(['unexpected' => []])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid training phrase list');

        (new ChatBotClient('secret', 'https://api.chatbot.test'))->listTrainingPhrases();
    }

    public function test_it_paginates_chat_archives_using_created_at_milliseconds(): void
    {
        $firstPage = [];
        for ($index = 0; $index < 40; $index++) {
            $firstPage[] = [
                'id' => "chat-{$index}",
                'createdAt' => '2026-09-30T08:00:00.123Z',
            ];
        }
        Http::fakeSequence()
            ->push($firstPage)
            ->push([['id' => 'chat-40', 'createdAt' => '2026-09-29T08:00:00.000Z']]);

        $pages = iterator_to_array(
            (new ChatBotClient('secret', 'https://api.chatbot.test'))->chatPages(41),
        );

        $this->assertCount(40, $pages[0]);
        $this->assertSame('chat-40', $pages[1][0]['id']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'after=1790755200123'));
    }

    public function test_it_can_resume_chat_pagination_from_a_saved_cursor(): void
    {
        Http::fake([
            'https://api.chatbot.test/v2/chats?after=1790755200123' => Http::response([
                ['id' => 'chat-older', 'createdAt' => '2026-09-29T08:00:00.000Z'],
            ]),
        ]);

        $pages = iterator_to_array(
            (new ChatBotClient('secret', 'https://api.chatbot.test'))->chatPages(40, 1790755200123),
        );

        $this->assertSame('chat-older', $pages[0][0]['id']);
        Http::assertSentCount(1);
    }

    public function test_it_fetches_chat_details_in_a_bounded_pool(): void
    {
        Http::fake([
            'https://api.chatbot.test/v2/chats/chat-1' => Http::response([
                'id' => 'chat-1', 'messages' => [],
            ]),
            'https://api.chatbot.test/v2/chats/chat-2' => Http::response([
                'id' => 'chat-2', 'messages' => [],
            ]),
        ]);

        $chats = (new ChatBotClient('secret', 'https://api.chatbot.test'))
            ->getChats(['chat-1', 'chat-2'], 2);

        $this->assertSame(['chat-1', 'chat-2'], array_column($chats, 'id'));
        Http::assertSentCount(2);
    }
}
