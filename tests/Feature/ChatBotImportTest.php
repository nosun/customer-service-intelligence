<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\ChatBotChat;
use App\Models\ChatBotChatEvent;
use App\Models\ChatBotStorySnapshot;
use App\Services\ChatBot\ChatBotImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatBotImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_story_versions_and_chats_idempotently(): void
    {
        $payload = $this->fixture();
        $importer = app(ChatBotImportService::class);

        $first = $importer->import($payload['stories'], $payload['chats'], 'primary', 'dressafford.com');
        $second = $importer->import($payload['stories'], $payload['chats'], 'primary', 'dressafford.com');

        $this->assertSame(1, $first['story_snapshots']);
        $this->assertSame(0, $second['story_snapshots']);
        $this->assertDatabaseCount('channel_accounts', 1);
        $this->assertDatabaseCount('chatbot_stories', 1);
        $this->assertDatabaseCount('chatbot_story_snapshots', 1);
        $this->assertDatabaseCount('chatbot_chats', 1);
        $this->assertDatabaseCount('chatbot_chat_events', 2);
        $this->assertSame('chatbot', ChannelAccount::firstOrFail()->channel);
        $this->assertSame('unreviewed', ChatBotChat::firstOrFail()->retention_status);
        $this->assertSame(
            ['user', 'bot'],
            ChatBotChatEvent::query()->orderBy('occurred_at')->pluck('author_type')->all(),
        );
        $this->assertSame(
            'Can this dress be made in red?',
            ChatBotChatEvent::query()->orderBy('occurred_at')->firstOrFail()->body_text,
        );
    }

    public function test_story_change_creates_a_new_snapshot(): void
    {
        $payload = $this->fixture();
        $importer = app(ChatBotImportService::class);
        $importer->import($payload['stories'], [], 'primary');
        $payload['stories'][0]['diagram']['children'][0]['name'] = 'Updated welcome';

        $result = $importer->import($payload['stories'], [], 'primary');

        $this->assertSame(1, $result['story_snapshots']);
        $this->assertDatabaseCount('chatbot_story_snapshots', 2);
        $this->assertSame(
            ['Welcome', 'Updated welcome'],
            ChatBotStorySnapshot::query()->orderBy('id')->get()
                ->map(fn (ChatBotStorySnapshot $snapshot): string => $snapshot->diagram['children'][0]['name'])
                ->all(),
        );
    }

    public function test_sync_command_imports_fixture_and_downloads_story_json(): void
    {
        $path = storage_path('app/private/chatbot/stories/story-1.json');

        $this->artisan('chatbot:sync', [
            '--account' => 'primary',
            '--site-key' => 'dressafford.com',
            '--chat-limit' => 1,
            '--fixture' => base_path('tests/fixtures/chatbot-api-sample.json'),
        ])->assertSuccessful();

        $this->assertFileExists($path);
        $story = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('start', $story['diagram']['id']);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

        unlink($path);
    }

    /** @return array{stories: list<array<string, mixed>>, chats: list<array<string, mixed>>} */
    private function fixture(): array
    {
        $contents = file_get_contents(base_path('tests/fixtures/chatbot-api-sample.json'));
        $this->assertIsString($contents);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
