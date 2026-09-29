<?php

namespace App\Services\Analysis;

use App\Models\CandidateReplyPair;
use App\Models\GmailThread;
use App\Models\Message;
use App\Models\MessageAnalysis;

class RuleAnalyzer
{
    private const VERSION = 'rules-v1';

    /** @var array<string, list<string>> */
    private const RULES = [
        'presales' => [
            'available', 'availability', 'size chart', 'what size', 'which size', 'color',
            'custom', 'made to measure', 'before i order', 'price', 'discount', 'production time',
        ],
        'production' => [
            'order status', 'still processing', 'in production', 'change size', 'change color',
            'change address', 'cancel my order', 'rush order', 'expedite', 'wedding date',
        ],
        'logistics' => [
            'tracking', 'where is my order', 'where is my package', 'not received', 'not delivered',
            'delivery', 'shipment', 'shipping', 'customs', 'lost package', 'stuck in transit',
        ],
        'after_sales' => [
            'return', 'refund', 'damaged', 'defect', 'poor quality', 'wrong item', 'does not fit',
            "doesn't fit", 'too small', 'too large', 'color difference', 'chargeback', 'complaint',
        ],
    ];

    /** @return array{messages_analyzed: int, reply_pairs: int, categories: array<string, int>} */
    public function analyze(): array
    {
        $messages = Message::where('direction', 'inbound')->orderBy('sent_at')->orderBy('id')->get();
        $categories = [];

        foreach ($messages as $message) {
            $result = $this->classify(($message->subject ?? '')."\n".$this->analysisBody($message));
            MessageAnalysis::updateOrCreate(
                ['message_id' => $message->id],
                [
                    'category' => $result['category'],
                    'rule_score' => $result['score'],
                    'matched_rules' => $result['matched'],
                    'order_candidates' => [],
                    'analyzer_version' => self::VERSION,
                    'analyzed_at' => now(),
                ],
            );
            $categories[$result['category']] = ($categories[$result['category']] ?? 0) + 1;
        }

        ksort($categories);

        return [
            'messages_analyzed' => $messages->count(),
            'reply_pairs' => $this->buildReplyPairs(),
            'categories' => $categories,
        ];
    }

    /** @return array{category: string, score: int, matched: list<string>} */
    public function classify(string $text): array
    {
        $text = strtolower($text);
        $scores = [];
        $matchedByCategory = [];

        foreach (self::RULES as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $scores[$category] = ($scores[$category] ?? 0) + 1;
                    $matchedByCategory[$category][] = $keyword;
                }
            }
        }

        if ($scores === []) {
            return ['category' => 'unknown', 'score' => 0, 'matched' => []];
        }

        arsort($scores);
        $category = (string) array_key_first($scores);

        return [
            'category' => $category,
            'score' => (int) $scores[$category],
            'matched' => array_values(array_unique($matchedByCategory[$category] ?? [])),
        ];
    }

    private function buildReplyPairs(): int
    {
        $count = 0;

        foreach (GmailThread::orderBy('id')->get() as $thread) {
            $messages = Message::query()
                ->leftJoin('message_analyses', 'message_analyses.message_id', '=', 'messages.id')
                ->where('messages.thread_id', $thread->id)
                ->orderBy('messages.sent_at')
                ->orderBy('messages.id')
                ->get(['messages.*', 'message_analyses.category']);
            $lastInbound = null;

            foreach ($messages as $message) {
                if ($message->direction === 'inbound') {
                    $lastInbound = $message;

                    continue;
                }

                if ($lastInbound === null) {
                    continue;
                }

                CandidateReplyPair::updateOrCreate(
                    [
                        'customer_message_id' => $lastInbound->id,
                        'agent_message_id' => $message->id,
                    ],
                    [
                        'thread_id' => $thread->id,
                        'category' => $lastInbound->category ?? 'unknown',
                        'customer_excerpt' => $this->excerpt($this->analysisBody($lastInbound)),
                        'agent_reply_excerpt' => $this->excerpt($this->analysisBody($message)),
                    ],
                );
                $count++;
                $lastInbound = null;
            }
        }

        return $count;
    }

    private function analysisBody(Message $message): string
    {
        if ($message->body_text) {
            return $message->body_text;
        }

        return html_entity_decode(strip_tags((string) $message->body_html));
    }

    private function excerpt(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_substr($value, 0, 500);
    }
}
