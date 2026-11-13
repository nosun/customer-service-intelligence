<?php

namespace App\Services\CustomerContacts;

use App\Models\CustomerContactEffect;
use App\Services\Gmail\GwsClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\Email;

class GmailFirstRoundTransport
{
    public function verifyAccount(string $siteKey): array
    {
        $profile = $this->client($siteKey)->profile();
        if (strcasecmp($profile['emailAddress'] ?? '', config("gmail.accounts.$siteKey.email", '')) !== 0) {
            throw new RuntimeException('Gmail site/account mismatch.');
        }

        return $profile;
    }

    public function send(array $mail): array
    {
        $this->verifyAccount($mail['site_key']);
        if ($mail['from'] !== config('gmail.accounts.'.$mail['site_key'].'.email')
            || ! filter_var($mail['to'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid frozen sender/recipient.');
        }
        $mime = (new Email)->from($mail['from'])->to($mail['to'])->subject($mail['subject'])->text($mail['body']);
        $mime->getHeaders()->addTextHeader('X-CSI-Round', $mail['marker']);
        $mime->getHeaders()->addTextHeader('X-CSI-Content-SHA256', $mail['content_hash']);
        $path = 'customer-contacts/mime/'.Str::uuid().'.eml';
        Storage::disk('local')->put($path, $mime->toString());
        try {
            $client = $this->client($mail['site_key']);
            $sent = $client->sendMimeFile(Storage::disk('local')->path($path));
            if (empty($sent['id'])) {
                throw new RuntimeException('No Gmail receipt; reconciliation required.');
            }

            return $this->matchMessage($client->getMessage($sent['id']), $mail);
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    public function reconcile(CustomerContactEffect $effect): array
    {
        $mail = $effect->payload;
        $this->verifyAccount($mail['site_key']);
        $client = $this->client($mail['site_key']);
        $after = max(0, $effect->attempt_started_at->timestamp - 300);
        $ids = $client->listMessageIds('in:sent to:'.$mail['to'].' after:'.$after.' subject:"'.$mail['subject'].'"', 100);
        $matches = [];
        foreach ($ids as $id) {
            $r = $this->matchMessage($client->getMessage($id), $mail);
            if ($r['state'] === 'matched') {
                $matches[] = $r;
            }
        }

        return count($matches) === 1 ? $matches[0] : ['state' => count($matches) > 1 ? 'ambiguous' : 'unknown'];
    }

    private function client(string $key): GwsClient
    {
        $account = config('gmail.accounts.'.$key);
        if (! is_array($account)) {
            throw new RuntimeException('Unknown Gmail site.');
        }

        return new GwsClient($account['config_dir'], maxAttempts: 1);
    }

    private function matchMessage(array $message, array $mail): array
    {
        $h = [];
        foreach ($message['payload']['headers'] ?? [] as $header) {
            $h[strtolower($header['name'])] = $header['value'];
        }
        $body = $message['payload']['body']['data'] ?? '';
        $body = base64_decode(strtr($body, '-_', '+/'), true);
        $normalize = fn ($s) => rtrim(str_replace("\r\n", "\n", $s), "\n")."\n";
        $addresses = fn ($v) => strtolower(trim(preg_replace('/.*<([^>]+)>\s*$/', '$1', $v)));
        if (! in_array('SENT', $message['labelIds'] ?? [], true) || ($h['x-csi-round'] ?? '') !== $mail['marker']
            || ($h['x-csi-content-sha256'] ?? '') !== $mail['content_hash']
            || $addresses($h['to'] ?? '') !== strtolower($mail['to']) || $addresses($h['from'] ?? '') !== strtolower($mail['from'])
            || ($h['subject'] ?? '') !== $mail['subject'] || ! empty($h['cc']) || ! empty($h['bcc'])
            || ($message['payload']['mimeType'] ?? '') !== 'text/plain' || $body === false
            || $normalize($body) !== $normalize($mail['body']) || empty($message['internalDate']) || empty($h['message-id'])) {
            return ['state' => 'unknown'];
        }

        return ['state' => 'matched', 'gmail_id' => $message['id'], 'thread_id' => $message['threadId'] ?? null,
            'rfc822_id' => $h['message-id'], 'sent_at' => CarbonImmutable::createFromTimestampMs($message['internalDate'])->toIso8601String()];
    }
}
