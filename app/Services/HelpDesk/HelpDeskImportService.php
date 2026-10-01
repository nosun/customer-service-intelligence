<?php

namespace App\Services\HelpDesk;

use App\Models\ChannelAccount;
use App\Models\ChannelSyncRun;
use App\Models\Conversation;
use App\Models\ConversationAttachment;
use App\Models\ConversationEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class HelpDeskImportService
{
    /**
     * @param  list<array<string, mixed>>  $tickets
     * @param  array<string, mixed>  $filters
     * @return array{channel_account_id: int, tickets: int, events: int, attachments: int}
     */
    public function import(array $tickets, string $fallbackAccountId, ?string $siteKey, array $filters): array
    {
        $licenseIds = array_values(array_unique(array_map(
            static fn (array $ticket): string => (string) ($ticket['licenseID'] ?? ''),
            $tickets,
        )));
        $licenseIds = array_values(array_filter($licenseIds, static fn (string $id): bool => $id !== ''));
        if (count($licenseIds) > 1) {
            throw new RuntimeException('HelpDesk response contains tickets from multiple licenses.');
        }

        $existingAccount = $tickets === []
            ? ChannelAccount::query()
                ->where('channel', 'helpdesk')
                ->when($siteKey !== null, fn ($query) => $query->where('site_key', $siteKey))
                ->first()
            : null;
        $externalAccountId = $licenseIds[0] ?? $existingAccount?->external_account_id ?? trim($fallbackAccountId);
        if ($externalAccountId === '') {
            throw new RuntimeException('HelpDesk account ID is missing.');
        }
        $account = ChannelAccount::updateOrCreate(
            ['channel' => 'helpdesk', 'external_account_id' => $externalAccountId],
            [
                'site_key' => $siteKey,
                'display_name' => 'HelpDesk',
                'credential_reference' => 'env:HELPDESK_PERSONAL_ACCESS_TOKEN',
                'sync_status' => 'running',
                'sync_error' => null,
            ],
        );
        $run = ChannelSyncRun::create([
            'channel_account_id' => $account->id,
            'source_resource' => 'tickets',
            'filter_snapshot' => $filters,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $counts = ['tickets' => 0, 'events' => 0, 'attachments' => 0];

        try {
            foreach ($tickets as $ticket) {
                $result = DB::transaction(fn (): array => $this->importTicket($account, $ticket, $siteKey, $filters));
                foreach ($counts as $key => $value) {
                    $counts[$key] += $result[$key];
                }
            }

            $run->update([
                'status' => 'completed',
                'records_seen' => count($tickets),
                'records_saved' => $counts['tickets'],
                'finished_at' => now(),
            ]);
            $account->update(['sync_status' => 'completed', 'last_synced_at' => now()]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'records_seen' => count($tickets),
                'records_saved' => $counts['tickets'],
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
            $account->update(['sync_status' => 'failed', 'sync_error' => $exception->getMessage()]);

            throw $exception;
        }

        return ['channel_account_id' => $account->id, ...$counts];
    }

    /**
     * @param  array<string, mixed>  $ticket
     * @param  array<string, mixed>  $filters
     * @return array{tickets: int, events: int, attachments: int}
     */
    private function importTicket(ChannelAccount $account, array $ticket, ?string $siteKey, array $filters): array
    {
        $ticketId = (string) ($ticket['ID'] ?? '');
        if ($ticketId === '') {
            throw new RuntimeException('HelpDesk ticket is missing ID.');
        }

        $requester = is_array($ticket['requester'] ?? null) ? $ticket['requester'] : null;
        $assignment = is_array($ticket['assignment'] ?? null) ? $ticket['assignment'] : null;
        $source = is_array($ticket['source'] ?? null) ? $ticket['source'] : [];
        $ticketPayload = $ticket;
        unset($ticketPayload['events']);
        $conversation = Conversation::updateOrCreate(
            ['channel_account_id' => $account->id, 'external_id' => $ticketId],
            [
                'external_short_id' => $ticket['shortID'] ?? null,
                'subject' => $ticket['subject'] ?? null,
                'status' => $ticket['status'] ?? null,
                'priority' => isset($ticket['priority']) ? (int) $ticket['priority'] : null,
                'silo' => $ticket['silo'] ?? ($filters['silo'] ?? null),
                'site_key' => $this->siteKey($ticket, $siteKey),
                'source_type' => $source['type'] ?? null,
                'source_detail' => $source['detailedSource'] ?? null,
                'requester_email' => $requester['email'] ?? null,
                'requester_snapshot' => $requester,
                'assignment_snapshot' => $assignment,
                'created_at_external' => $ticket['createdAt'] ?? null,
                'updated_at_external' => $ticket['updatedAt'] ?? null,
                'last_message_at' => $ticket['lastMessageAt'] ?? null,
                'raw_payload' => $ticketPayload,
            ],
        );

        $events = 0;
        $attachments = 0;
        foreach ($ticket['events'] ?? [] as $event) {
            if (! is_array($event)) {
                continue;
            }

            $eventId = (string) ($event['ID'] ?? hash('sha256', json_encode($event, JSON_THROW_ON_ERROR)));
            $author = is_array($event['author'] ?? null) ? $event['author'] : [];
            $message = is_array($event['message'] ?? null) ? $event['message'] : [];
            $eventAttachments = is_array($event['attachments'] ?? null) ? $event['attachments'] : [];
            $eventAssignment = is_array($event['assignment'] ?? null) ? $event['assignment'] : null;
            $eventSource = is_array($event['source'] ?? null) ? $event['source'] : null;
            $eventStatus = $event['status'] ?? null;
            $statusValue = is_array($eventStatus) ? ($eventStatus['new'] ?? null) : $eventStatus;
            $conversationEvent = ConversationEvent::updateOrCreate(
                ['conversation_id' => $conversation->id, 'external_id' => $eventId],
                [
                    'event_type' => (string) ($event['type'] ?? 'unknown'),
                    'occurred_at' => $event['date'] ?? null,
                    'author_type' => $author['type'] ?? null,
                    'external_author_id' => $author['ID'] ?? null,
                    'author_name' => $author['name'] ?? null,
                    'author_email' => $author['email'] ?? null,
                    'is_private' => (bool) ($message['isPrivate'] ?? ($eventAttachments['isPrivate'] ?? false)),
                    'body_text' => $message['text'] ?? null,
                    'rich_text_html' => $message['richTextHtml'] ?? null,
                    'rich_text_payload' => is_array($message['richTextObj'] ?? null) ? $message['richTextObj'] : null,
                    'status_value' => is_scalar($statusValue) ? (string) $statusValue : null,
                    'assignment_snapshot' => $eventAssignment,
                    'source_snapshot' => $eventSource,
                    'content_hash' => hash('sha256', json_encode($event, JSON_THROW_ON_ERROR)),
                    'raw_payload' => $event,
                ],
            );
            $events++;

            $files = is_array($eventAttachments['files'] ?? null) ? $eventAttachments['files'] : [];
            foreach ($files as $index => $file) {
                if (! is_array($file)) {
                    continue;
                }
                $externalKey = (string) ($file['token'] ?? $file['sha256'] ?? "{$eventId}:{$index}");
                ConversationAttachment::updateOrCreate(
                    ['conversation_event_id' => $conversationEvent->id, 'external_key' => $externalKey],
                    [
                        'external_token' => $file['token'] ?? null,
                        'filename' => $file['name'] ?? null,
                        'mime_type' => $file['type'] ?? null,
                        'size_bytes' => isset($file['size']) ? (int) $file['size'] : null,
                        'content_id' => $file['cid'] ?? null,
                        'sha256' => $file['sha256'] ?? null,
                        'remote_url' => $file['url'] ?? null,
                        'raw_payload' => $file,
                    ],
                );
                $attachments++;
            }
        }

        return ['tickets' => 1, 'events' => $events, 'attachments' => $attachments];
    }

    /** @param array<string, mixed> $ticket */
    private function siteKey(array $ticket, ?string $fallback): ?string
    {
        $teamName = strtolower((string) data_get($ticket, 'assignment.team.name', ''));
        if (str_contains($teamName, 'ellen')) {
            return 'ellenbridals.com';
        }
        if (str_contains($teamName, 'dressafford')) {
            return 'dressafford.com';
        }

        $payload = strtolower((string) json_encode($ticket, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $hasEllen = str_contains($payload, 'ellenbridals') || str_contains($payload, 'ellen bridals');
        $hasDressafford = str_contains($payload, 'dressafford');
        if ($hasEllen !== $hasDressafford) {
            return $hasEllen ? 'ellenbridals.com' : 'dressafford.com';
        }

        $fallback = trim((string) $fallback);

        return $fallback !== '' ? $fallback : null;
    }
}
