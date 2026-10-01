<?php

namespace App\Services\ChatBot;

use App\Models\ChannelAccount;
use App\Models\ChannelSyncRun;
use App\Models\ChatBotChat;
use App\Models\ChatBotChatEvent;
use App\Models\ChatBotStory;
use App\Models\ChatBotStorySnapshot;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ChatBotImportService
{
    /**
     * @param  list<array<string, mixed>>  $stories
     * @param  list<array<string, mixed>>  $chats
     * @return array{channel_account_id: int, stories: int, story_snapshots: int, chats: int, events: int}
     */
    public function import(array $stories, array $chats, string $accountId, ?string $siteKey = null): array
    {
        $accountId = trim($accountId);
        if ($accountId === '') {
            throw new RuntimeException('ChatBot account ID is missing.');
        }

        $account = ChannelAccount::updateOrCreate(
            ['channel' => 'chatbot', 'external_account_id' => $accountId],
            [
                'site_key' => $siteKey,
                'display_name' => 'ChatBot',
                'credential_reference' => 'env:CHATBOT_DEVELOPER_ACCESS_TOKEN',
                'sync_status' => 'running',
                'sync_error' => null,
            ],
        );
        $run = ChannelSyncRun::create([
            'channel_account_id' => $account->id,
            'source_resource' => 'stories,chats',
            'filter_snapshot' => ['site_key' => $siteKey],
            'status' => 'running',
            'started_at' => now(),
        ]);
        $counts = ['stories' => 0, 'story_snapshots' => 0, 'chats' => 0, 'events' => 0];

        try {
            foreach ($stories as $story) {
                $created = DB::transaction(fn (): bool => $this->importStory($account, $story));
                $counts['stories']++;
                $counts['story_snapshots'] += (int) $created;
            }
            foreach ($chats as $chat) {
                $events = DB::transaction(fn (): int => $this->importChat($account, $chat));
                $counts['chats']++;
                $counts['events'] += $events;
            }

            $run->update([
                'status' => 'completed',
                'records_seen' => count($stories) + count($chats),
                'records_saved' => $counts['stories'] + $counts['chats'],
                'finished_at' => now(),
            ]);
            $account->update(['sync_status' => 'completed', 'last_synced_at' => now()]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'records_seen' => count($stories) + count($chats),
                'records_saved' => $counts['stories'] + $counts['chats'],
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
            $account->update(['sync_status' => 'failed', 'sync_error' => $exception->getMessage()]);

            throw $exception;
        }

        return ['channel_account_id' => $account->id, ...$counts];
    }

    /** @param array<string, mixed> $payload */
    private function importStory(ChannelAccount $account, array $payload): bool
    {
        $externalId = trim((string) ($payload['id'] ?? ''));
        if ($externalId === '') {
            throw new RuntimeException('ChatBot story is missing id.');
        }

        $story = ChatBotStory::updateOrCreate(
            ['channel_account_id' => $account->id, 'external_id' => $externalId],
            [
                'name' => $payload['name'] ?? null,
                'description' => $payload['description'] ?? null,
                'current_version' => isset($payload['version']) ? (int) $payload['version'] : null,
                'published' => (bool) ($payload['published'] ?? false),
                'integrations' => is_array($payload['integrations'] ?? null) ? $payload['integrations'] : [],
                'fetched_at' => now(),
            ],
        );
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $snapshot = ChatBotStorySnapshot::firstOrCreate(
            ['chatbot_story_id' => $story->id, 'content_hash' => $hash],
            [
                'version' => isset($payload['version']) ? (int) $payload['version'] : null,
                'published' => (bool) ($payload['published'] ?? false),
                'settings' => $this->arrayOrNull($payload['settings'] ?? null),
                'state' => $this->arrayOrNull($payload['state'] ?? null),
                'history' => $this->arrayOrNull($payload['history'] ?? null),
                'diagram' => $this->arrayOrNull($payload['diagram'] ?? null),
                'raw_payload' => $payload,
                'captured_at' => now(),
            ],
        );

        return $snapshot->wasRecentlyCreated;
    }

    /** @param array<string, mixed> $payload */
    private function importChat(ChannelAccount $account, array $payload): int
    {
        $externalId = trim((string) ($payload['id'] ?? ''));
        if ($externalId === '') {
            throw new RuntimeException('ChatBot chat is missing id.');
        }
        $storyId = isset($payload['storyId']) ? (string) $payload['storyId'] : null;
        $story = $storyId === null ? null : ChatBotStory::query()
            ->where('channel_account_id', $account->id)
            ->where('external_id', $storyId)
            ->first();
        $user = $this->arrayOrNull($payload['user'] ?? null);
        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
        $chatPayload = $payload;
        unset($chatPayload['messages']);
        $chat = ChatBotChat::updateOrCreate(
            ['channel_account_id' => $account->id, 'external_id' => $externalId],
            [
                'chatbot_story_id' => $story?->id,
                'external_story_id' => $storyId,
                'external_user_id' => isset($user['id']) ? (string) $user['id'] : null,
                'user_snapshot' => $user,
                'source' => $payload['source'] ?? null,
                'attributes' => $this->arrayOrNull($payload['attributes'] ?? null),
                'started_at' => $payload['createdAt'] ?? null,
                'updated_at_external' => $payload['updatedAt'] ?? null,
                'message_count' => count($messages),
                'raw_payload' => $chatPayload,
                'synced_at' => now(),
            ],
        );

        $eventIds = [];
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            foreach ($this->eventsForMessage($message) as $event) {
                $eventIds[] = $event['external_id'];
                ChatBotChatEvent::updateOrCreate(
                    ['chatbot_chat_id' => $chat->id, 'external_id' => $event['external_id']],
                    [
                        'request_id' => $message['requestId'] ?? null,
                        'occurred_at' => $message['date'] ?? null,
                        'author_type' => $event['author_type'],
                        'event_type' => $event['event_type'],
                        'body_text' => $this->bodyText($event['raw_payload']),
                        'score' => isset($message['score']) && is_numeric($message['score']) ? $message['score'] : null,
                        'content_hash' => hash('sha256', json_encode($event['raw_payload'], JSON_THROW_ON_ERROR)),
                        'raw_payload' => $event['raw_payload'],
                    ],
                );
            }
        }
        ChatBotChatEvent::query()
            ->where('chatbot_chat_id', $chat->id)
            ->when($eventIds !== [], fn ($query) => $query->whereNotIn('external_id', $eventIds))
            ->when($eventIds === [], fn ($query) => $query)
            ->delete();

        return count($eventIds);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array{external_id: string, author_type: string, event_type: string, raw_payload: array<string, mixed>}>
     */
    private function eventsForMessage(array $message): array
    {
        $messageId = (string) ($message['id'] ?? hash('sha256', json_encode($message, JSON_THROW_ON_ERROR)));
        $responses = is_array($message['responses'] ?? null) ? $message['responses'] : [];
        $events = [];
        foreach ($responses as $index => $response) {
            if (! is_array($response)) {
                continue;
            }
            $type = is_string($response['type'] ?? null) ? $response['type'] : 'UNKNOWN';
            $responseId = (string) ($response['id'] ?? $index);
            $events[] = [
                'external_id' => "{$messageId}:{$responseId}",
                'author_type' => match (true) {
                    str_starts_with($type, 'INPUT_') => 'user',
                    $type === 'BOT_RESPONSE' => 'bot',
                    default => 'system',
                },
                'event_type' => $type,
                'raw_payload' => $response,
            ];
        }
        if ($events === []) {
            $from = in_array($message['from'] ?? null, ['user', 'bot'], true) ? $message['from'] : 'system';
            $events[] = [
                'external_id' => $messageId,
                'author_type' => $from,
                'event_type' => $from,
                'raw_payload' => $message,
            ];
        }

        return $events;
    }

    /** @param array<string, mixed> $message */
    private function bodyText(array $message): ?string
    {
        if (is_string($message['message'] ?? null)) {
            return $message['message'];
        }
        $parts = [];
        $collect = function (mixed $value) use (&$collect, &$parts): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $item) {
                if (in_array($key, ['message', 'value'], true) && is_string($item) && trim($item) !== '') {
                    $parts[] = trim($item);
                } elseif (is_array($item)) {
                    $collect($item);
                }
            }
        };
        $collect($message);
        $parts = array_values(array_unique($parts));

        return $parts === [] ? null : implode("\n", $parts);
    }

    /** @return array<string|int, mixed>|null */
    private function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
