<?php

namespace App\Services\Gmail;

use DateTimeImmutable;
use Throwable;

class GmailMessageParser
{
    /** @return array<string, mixed> */
    public function parse(array $message, string $mailboxEmail): array
    {
        $headers = [];
        foreach ($message['payload']['headers'] ?? [] as $header) {
            if (isset($header['name'], $header['value'])) {
                $headers[strtolower((string) $header['name'])] = (string) $header['value'];
            }
        }

        $bodies = ['text' => [], 'html' => []];
        $attachments = [];
        $this->walkPart($message['payload'] ?? [], $bodies, $attachments);

        $from = $headers['from'] ?? '';
        $internalDate = isset($message['internalDate']) ? (string) $message['internalDate'] : null;

        return [
            'gmail_message_id' => (string) ($message['id'] ?? ''),
            'history_id' => isset($message['historyId']) ? (string) $message['historyId'] : null,
            'internal_date_ms' => $internalDate,
            'sent_at' => $this->sentAt($headers['date'] ?? null, $internalDate),
            'direction' => stripos($from, $mailboxEmail) !== false ? 'outbound' : 'inbound',
            'subject' => $headers['subject'] ?? null,
            'from_address' => $from ?: null,
            'to_addresses' => $headers['to'] ?? null,
            'cc_addresses' => $headers['cc'] ?? null,
            'message_id_header' => $headers['message-id'] ?? null,
            'in_reply_to' => $headers['in-reply-to'] ?? null,
            'references_header' => $headers['references'] ?? null,
            'snippet' => isset($message['snippet']) ? (string) $message['snippet'] : null,
            'body_text' => trim(implode("\n", array_filter($bodies['text']))) ?: null,
            'body_html' => trim(implode("\n", array_filter($bodies['html']))) ?: null,
            'label_ids' => $message['labelIds'] ?? [],
            'raw_headers' => $headers,
            'size_estimate' => isset($message['sizeEstimate']) ? (int) $message['sizeEstimate'] : null,
            'attachments' => $attachments,
        ];
    }

    /**
     * @param  array{text: list<string>, html: list<string>}  $bodies
     * @param  list<array<string, mixed>>  $attachments
     */
    private function walkPart(array $part, array &$bodies, array &$attachments): void
    {
        $mimeType = strtolower((string) ($part['mimeType'] ?? ''));
        $filename = (string) ($part['filename'] ?? '');
        $body = $part['body'] ?? [];

        if ($filename !== '' || isset($body['attachmentId'])) {
            $attachments[] = [
                'gmail_attachment_id' => isset($body['attachmentId']) ? (string) $body['attachmentId'] : null,
                'filename' => $filename ?: null,
                'mime_type' => $mimeType ?: null,
                'size' => isset($body['size']) ? (int) $body['size'] : null,
            ];
        } elseif (isset($body['data'])) {
            $decoded = $this->decodeBase64Url((string) $body['data']);
            if ($mimeType === 'text/html') {
                $bodies['html'][] = $decoded;
            } elseif ($mimeType === 'text/plain' || $mimeType === '') {
                $bodies['text'][] = $decoded;
            }
        }

        foreach ($part['parts'] ?? [] as $child) {
            $this->walkPart($child, $bodies, $attachments);
        }
    }

    private function decodeBase64Url(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($value, true) ?: '';
    }

    private function sentAt(?string $dateHeader, ?string $internalDate): ?string
    {
        if ($dateHeader !== null) {
            try {
                return (new DateTimeImmutable($dateHeader))->format(DATE_ATOM);
            } catch (Throwable) {
            }
        }

        if ($internalDate !== null && ctype_digit($internalDate)) {
            return (new DateTimeImmutable('@'.intdiv((int) $internalDate, 1000)))->format(DATE_ATOM);
        }

        return null;
    }
}
