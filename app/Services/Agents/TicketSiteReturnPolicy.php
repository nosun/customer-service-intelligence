<?php

namespace App\Services\Agents;

use Illuminate\Support\Facades\Http;
use Throwable;

class TicketSiteReturnPolicy
{
    public function read(?string $site): array
    {
        // Fixed public URLs: neither customer messages nor model arguments can select a host/path.
        $url = config('ticket-agent.return_policy_urls', [])[$site ?? ''] ?? null;
        if ($url === null) {
            return ['status' => 'unavailable', 'reason' => 'No trusted return-policy source is configured for this site.', 'evidence_refs' => []];
        }
        try {
            $response = Http::connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false])->get($url);
            $html = $response->body();
            if (! $response->successful() || strlen($html) > 2_000_000) {
                throw new \RuntimeException('Policy source unavailable.');
            }
            $body = null;
            if (preg_match('/\$d\.article\s*=\s*/', $html, $match, PREG_OFFSET_CAPTURE)) {
                $start = $match[0][1] + strlen($match[0][0]);
                // The actual site omits the semicolon. Decode the balanced JSON object, never JavaScript.
                $depth = 0;
                $quoted = false;
                $escaped = false;
                for ($end = $start; $end < strlen($html); $end++) {
                    $char = $html[$end];
                    if ($quoted) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($char === '\\') {
                            $escaped = true;
                        } elseif ($char === '"') {
                            $quoted = false;
                        }
                    } elseif ($char === '"') {
                        $quoted = true;
                    } elseif ($char === '{') {
                        $depth++;
                    } elseif ($char === '}' && --$depth === 0) {
                        $article = json_decode(substr($html, $start, $end - $start + 1), true);
                        $body = data_get($article, 'article.content_en');
                        break;
                    }
                }
            }
            if (! is_string($body) || strlen($body) < 100) {
                throw new \RuntimeException('Policy content format is unknown.');
            }
            $body = preg_replace('~<(?:script|style)\b[^>]*>.*?</(?:script|style)>~is', '', $body);
            $body = preg_replace('~</(?:p|div|li|h[1-6])>|<br\s*/?>~i', "\n", $body);
            $text = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $hash = hash('sha256', $text);

            return ['status' => 'read', 'url' => $url, 'content' => $text, 'content_hash' => $hash,
                'captured_at' => now()->toIso8601String(), 'fresh_until' => now()->addMinutes(10)->toIso8601String(),
                'limitations' => ['Current public text, not a historical policy version or an individual approval. Report conflicts with verified site guidance for human review.'],
                'evidence_refs' => ['site_policy:return@'.$hash]];
        } catch (Throwable) {
            return ['status' => 'unavailable', 'reason' => 'Current public return policy could not be read; do not invent its terms.', 'evidence_refs' => []];
        }
    }
}
