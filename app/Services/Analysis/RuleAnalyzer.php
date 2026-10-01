<?php

namespace App\Services\Analysis;

use App\Models\CandidateReplyPair;
use App\Models\GmailThread;
use App\Models\Message;
use App\Models\MessageAnalysis;

class RuleAnalyzer
{
    private const VERSION = 'rules-v2';

    /** @var array<string, list<string>> */
    private const NOISE_RULES = [
        'system_notification' => [
            'security alert',
            'new sign-in on',
            'google cloud organization is now available',
            'cloud organization allows google cloud platform admins',
        ],
        'marketing' => [
            'tiktok shop live',
            'how is livechat working for the team',
            'annual kids & family guide',
            'early bird discount ending soon',
            'potential seller',
            'in live gmv',
        ],
        'spam' => [
            'claim your prize',
            'lottery winner',
            'verify your crypto wallet',
            'guaranteed seo ranking',
            'guest post opportunity',
        ],
    ];

    /**
     * Rules are ordered so an equally strong, more specific customer intent wins.
     *
     * @var array<string, array{category: string, keywords: list<string>}>
     */
    private const ISSUE_RULES = [
        'after_sales_dispute' => [
            'category' => 'after_sales',
            'keywords' => ['chargeback', 'refute these charges', 'report as fraud', 'refund request', 'outstanding refund'],
        ],
        'after_sales_return_refund' => [
            'category' => 'after_sales',
            'keywords' => ['return', 'refund', 'return address', 'return form', 'account credit'],
        ],
        'after_sales_product_issue' => [
            'category' => 'after_sales',
            'keywords' => [
                'damaged', 'defect', 'poor quality', 'incorrect dress', 'wrong item', 'does not match',
                "doesn't match", 'material is wrong', 'colors wrong', 'does not fit', "doesn't fit",
                'too small', 'too large', 'received my dress but no', 'missing item',
            ],
        ],
        'production_cancellation' => [
            'category' => 'production',
            'keywords' => ['cancel my order', 'cancel one of my orders', 'please cancel', 'order was cancelled', 'why my order was cancelled'],
        ],
        'production_order_change' => [
            'category' => 'production',
            'keywords' => [
                'change my size', 'change size', 'size change', 'corrected measurements', 'measurements are shown',
                'need to be amended', 'change color', 'change address', 'add to my order', 'double ordered',
            ],
        ],
        'presales_checkout_payment' => [
            'category' => 'presales',
            'keywords' => [
                "payment can't be processed", 'payment can’t be processed', "won't process", 'won’t process',
                'card in a couple times', "didn't authorize payment", 'no payments processed', 'other way to place an order',
            ],
        ],
        'production_rush_request' => [
            'category' => 'production',
            'keywords' => ['rush order', 'rush or expedite', 'expedite this', 'expedited shipping', 'offer an expedited', 'express delivery option'],
        ],
        'logistics_tracking_delivery' => [
            'category' => 'logistics',
            'keywords' => [
                'tracking number', 'tracking process', 'where is my package', 'not received', 'not delivered',
                "hasn't arrived", 'hasn’t arrived', 'delivery date', 'expected delivery', 'when i can expect',
                'when we would be getting', 'when this order will arrive', 'arrive in time', 'stuck in transit',
            ],
        ],
        'production_order_status' => [
            'category' => 'production',
            'keywords' => [
                'order status', 'status of this order', 'status on order', 'status of my order', 'update on this order',
                'update with an estimated ship date', 'when this will be shipping', 'when will ship',
                'when it will be shipped', 'when this dress will ship', 'when this is expected to ship',
                'when to expect it has shipped', 'when to expect ot has shipped', 'current status and expected shipping',
                'started the tailoring process',
                "haven't been any updates", 'hasn’t been any updates',
            ],
        ],
        'presales_product_information' => [
            'category' => 'presales',
            'keywords' => [
                'item code:', 'interested in ordering', 'before i place my order', 'actual, unedited photos',
                'photos or videos', 'be made with', 'do you sell the', 'available in', 'availability',
                'custom', 'made to measure', 'what color', 'production time',
            ],
        ],
        'presales_sizing' => [
            'category' => 'presales',
            'keywords' => ['what size', 'which size', 'size chart', 'between size', 'would a 22 or 24 be best', 'concerned about the sizing'],
        ],
    ];

    /** @var list<string> */
    private const CRITICAL_URGENCY_RULES = [
        'chargeback', 'refute these charges', 'report as fraud', 'immediate full refund',
        'third email', 'multiple emails', 'please reply', 'outstanding refund',
    ];

    /** @var list<string> */
    private const HIGH_URGENCY_RULES = [
        'urgent', 'as soon as possible', 'wedding', 'event is on', 'needed before',
        'arrive in time', 'rush', 'expedite', 'please cancel', 'cancel my order',
    ];

    /**
     * @return array{
     *     messages_analyzed: int,
     *     messages_excluded: int,
     *     reply_pairs: int,
     *     categories: array<string, int>,
     *     urgency: array<string, int>
     * }
     */
    public function analyze(): array
    {
        $messages = Message::where('direction', 'inbound')->orderBy('sent_at')->orderBy('id')->get();
        $categories = [];
        $urgency = [];
        $excluded = 0;

        foreach ($messages as $message) {
            $result = $this->classifyMessage($message);
            MessageAnalysis::updateOrCreate(
                ['message_id' => $message->id],
                [
                    'category' => $result['category'],
                    'problem_type' => $result['problem_type'],
                    'rule_score' => $result['score'],
                    'urgency' => $result['urgency'],
                    'is_noise' => $result['is_noise'],
                    'noise_type' => $result['noise_type'],
                    'matched_rules' => $result['matched'],
                    'order_candidates' => $result['orders'],
                    'analyzer_version' => self::VERSION,
                    'analyzed_at' => now(),
                ],
            );

            $categories[$result['category']] = ($categories[$result['category']] ?? 0) + 1;
            $urgency[$result['urgency']] = ($urgency[$result['urgency']] ?? 0) + 1;
            $excluded += $result['is_noise'] ? 1 : 0;
        }

        ksort($categories);
        ksort($urgency);

        return [
            'messages_analyzed' => $messages->count(),
            'messages_excluded' => $excluded,
            'reply_pairs' => $this->buildReplyPairs(),
            'categories' => $categories,
            'urgency' => $urgency,
        ];
    }

    /** @return array{category: string, score: int, matched: list<string>} */
    public function classify(string $text): array
    {
        $result = $this->analyzeText($text);

        return [
            'category' => $result['category'],
            'score' => $result['score'],
            'matched' => $result['matched'],
        ];
    }

    /**
     * @return array{category: string, problem_type: ?string, score: int, matched: list<string>, orders: list<string>, urgency: string, is_noise: bool, noise_type: ?string}
     */
    public function analyzeText(string $text): array
    {
        $result = $this->classifyText($this->stripQuotedHistory($text));
        $result['orders'] = $this->extractOrderNumbers($text);

        return $result;
    }

    /**
     * @return array{
     *     category: string,
     *     problem_type: ?string,
     *     score: int,
     *     matched: list<string>,
     *     orders: list<string>,
     *     urgency: string,
     *     is_noise: bool,
     *     noise_type: ?string
     * }
     */
    private function classifyMessage(Message $message): array
    {
        $body = $this->analysisBody($message);
        $currentText = trim(($message->subject ?? '')."\n".$this->stripQuotedHistory($body));
        $result = $this->classifyText($currentText);
        $result['orders'] = $this->extractOrderNumbers(($message->subject ?? '')."\n".$body);

        return $result;
    }

    /**
     * @return array{
     *     category: string,
     *     problem_type: ?string,
     *     score: int,
     *     matched: list<string>,
     *     orders: list<string>,
     *     urgency: string,
     *     is_noise: bool,
     *     noise_type: ?string
     * }
     */
    private function classifyText(string $text): array
    {
        $normalized = strtolower(html_entity_decode($text));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        foreach (self::NOISE_RULES as $noiseType => $keywords) {
            $matched = $this->matchedKeywords($normalized, $keywords);
            if ($matched !== []) {
                return [
                    'category' => 'noise',
                    'problem_type' => null,
                    'score' => count($matched),
                    'matched' => $matched,
                    'orders' => [],
                    'urgency' => 'normal',
                    'is_noise' => true,
                    'noise_type' => $noiseType,
                ];
            }
        }

        $best = null;
        foreach (self::ISSUE_RULES as $problemType => $rule) {
            $matched = $this->matchedKeywords($normalized, $rule['keywords']);
            if ($matched !== [] && ($best === null || count($matched) > $best['score'])) {
                $best = [
                    'category' => $rule['category'],
                    'problem_type' => $problemType,
                    'score' => count($matched),
                    'matched' => $matched,
                ];
            }
        }

        $urgency = $this->urgency($normalized);

        return [
            'category' => $best['category'] ?? 'unknown',
            'problem_type' => $best['problem_type'] ?? null,
            'score' => $best['score'] ?? 0,
            'matched' => $best['matched'] ?? [],
            'orders' => [],
            'urgency' => $urgency,
            'is_noise' => false,
            'noise_type' => null,
        ];
    }

    /** @param list<string> $keywords
     * @return list<string>
     */
    private function matchedKeywords(string $text, array $keywords): array
    {
        return array_values(array_filter(
            $keywords,
            fn (string $keyword): bool => str_contains($text, $keyword),
        ));
    }

    private function urgency(string $text): string
    {
        if ($this->matchedKeywords($text, self::CRITICAL_URGENCY_RULES) !== []) {
            return 'critical';
        }

        if ($this->matchedKeywords($text, self::HIGH_URGENCY_RULES) !== []) {
            return 'high';
        }

        return 'normal';
    }

    /** @return list<string> */
    private function extractOrderNumbers(string $text): array
    {
        preg_match_all('/\b[a-z]{2,5}\d{8}-\d{3,}\b/i', $text, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[0] ?? [])));
    }

    private function stripQuotedHistory(string $body): string
    {
        $offsets = [];
        foreach ([
            '/\ROn .{0,300}wrote:\s*/is',
            '/\R-{2,}\s*Forwarded (?:message|Message)\s*-{2,}/',
            '/\RFrom:\s.+\R(?:Sent|Date):/is',
            '/\R_{5,}\R/',
        ] as $pattern) {
            if (preg_match($pattern, $body, $match, PREG_OFFSET_CAPTURE) === 1) {
                $offsets[] = $match[0][1];
            }
        }

        return $offsets === [] ? $body : substr($body, 0, min($offsets));
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
                ->get(['messages.*', 'message_analyses.category', 'message_analyses.is_noise']);
            $lastInbound = null;

            foreach ($messages as $message) {
                if ($message->direction === 'inbound') {
                    $lastInbound = $message->is_noise ? null : $message;

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
                        'customer_excerpt' => $this->excerpt($this->stripQuotedHistory($this->analysisBody($lastInbound))),
                        'agent_reply_excerpt' => $this->excerpt($this->stripQuotedHistory($this->analysisBody($message))),
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
