<?php

namespace App\Services\Analysis;

use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\ConversationEvent;
use App\Models\ConversationEventAnalysis;
use App\Models\ConversationLabel;
use App\Models\ConversationReplyCandidate;

class HelpDeskAnalyzer
{
    private const VERSION = 'helpdesk-rules-v1';

    public function __construct(private readonly RuleAnalyzer $rules) {}

    /**
     * @return array{conversations: int, messages_analyzed: int, messages_excluded: int, reply_candidates: int, tickets_needing_review: int, tickets_without_reply_candidate: int, categories: array<string, int>, ticket_categories: array<string, int>, primary_problem_types: array<string, int>}
     */
    public function analyze(): array
    {
        $counts = [
            'conversations' => 0,
            'messages_analyzed' => 0,
            'messages_excluded' => 0,
            'reply_candidates' => 0,
            'tickets_needing_review' => 0,
            'tickets_without_reply_candidate' => 0,
        ];
        $categories = [];

        Conversation::query()
            ->whereIn('silo', ['tickets', 'archive'])
            ->orderBy('id')
            ->chunkById(100, function ($conversations) use (&$counts, &$categories): void {
                foreach ($conversations as $conversation) {
                    $result = $this->analyzeConversation($conversation);
                    foreach ($counts as $key => $value) {
                        $counts[$key] += $result[$key];
                    }
                    foreach ($result['categories'] as $category => $count) {
                        $categories[$category] = ($categories[$category] ?? 0) + $count;
                    }
                }
            });

        ksort($categories);

        $ticketCategories = ConversationAnalysis::query()
            ->selectRaw('primary_category, count(*) as aggregate')
            ->groupBy('primary_category')
            ->pluck('aggregate', 'primary_category')
            ->map(fn ($count): int => (int) $count)
            ->all();
        $problemTypes = ConversationAnalysis::query()
            ->whereNotNull('primary_problem_type')
            ->selectRaw('primary_problem_type, count(*) as aggregate')
            ->groupBy('primary_problem_type')
            ->pluck('aggregate', 'primary_problem_type')
            ->map(fn ($count): int => (int) $count)
            ->all();
        ksort($ticketCategories);
        ksort($problemTypes);

        return [
            ...$counts,
            'categories' => $categories,
            'ticket_categories' => $ticketCategories,
            'primary_problem_types' => $problemTypes,
        ];
    }

    /**
     * @return array{conversations: int, messages_analyzed: int, messages_excluded: int, reply_candidates: int, tickets_needing_review: int, tickets_without_reply_candidate: int, categories: array<string, int>}
     */
    private function analyzeConversation(Conversation $conversation): array
    {
        $events = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('event_type', 'message')
            ->where('is_private', false)
            ->whereIn('author_type', ['client', 'agent'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        $lastCustomer = null;
        $seenCandidateIds = [];
        $analyzed = 0;
        $excluded = 0;
        $candidateCount = 0;
        $categories = [];
        $problemTypes = [];
        $urgency = 'normal';
        $agentMessages = 0;

        foreach ($events as $event) {
            if ($event->author_type === 'client') {
                $result = $this->rules->analyzeText($this->body($event));
                ConversationEventAnalysis::updateOrCreate(
                    ['conversation_event_id' => $event->id],
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
                $analyzed++;
                $excluded += $result['is_noise'] ? 1 : 0;
                $categories[$result['category']] = ($categories[$result['category']] ?? 0) + 1;
                if ($result['problem_type'] !== null) {
                    $problemTypes[$result['problem_type']] = ($problemTypes[$result['problem_type']] ?? 0) + 1;
                }
                if ($this->urgencyRank($result['urgency']) > $this->urgencyRank($urgency)) {
                    $urgency = $result['urgency'];
                }
                $lastCustomer = $result['is_noise'] ? null : [$event, $result['category']];

                continue;
            }

            if ($lastCustomer === null) {
                $agentMessages++;

                continue;
            }

            $agentMessages++;

            [$customerEvent, $category] = $lastCustomer;
            $candidate = ConversationReplyCandidate::updateOrCreate(
                [
                    'customer_event_id' => $customerEvent->id,
                    'agent_event_id' => $event->id,
                ],
                [
                    'conversation_id' => $conversation->id,
                    'category' => $category,
                    'customer_excerpt' => $this->excerpt($this->body($customerEvent)),
                    'agent_reply_excerpt' => $this->excerpt($this->body($event)),
                ],
            );
            $seenCandidateIds[] = $candidate->id;
            $candidateCount++;
            $lastCustomer = null;
        }

        $staleCandidates = ConversationReplyCandidate::where('conversation_id', $conversation->id)
            ->where('review_status', 'candidate');
        if ($seenCandidateIds === []) {
            $staleCandidates->delete();
        } else {
            $staleCandidates->whereNotIn('id', $seenCandidateIds)->delete();
        }

        $primaryCategory = $this->primaryLabel($categories) ?? 'unknown';
        $primaryProblemType = $this->primaryLabel($problemTypes);
        $unknownMessages = $categories['unknown'] ?? 0;
        $needsReview = $primaryCategory === 'unknown'
            || ($analyzed > 0 && $unknownMessages / $analyzed >= 0.5)
            || $conversation->site_key === null;
        ConversationAnalysis::updateOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'primary_category' => $primaryCategory,
                'primary_problem_type' => $primaryProblemType,
                'urgency' => $urgency,
                'customer_message_count' => $analyzed,
                'agent_message_count' => $agentMessages,
                'analyzed_message_count' => $analyzed,
                'unknown_message_count' => $unknownMessages,
                'reply_candidate_count' => $candidateCount,
                'category_counts' => $categories,
                'problem_type_counts' => $problemTypes,
                'needs_review' => $needsReview,
                'analyzer_version' => self::VERSION,
                'analyzed_at' => now(),
            ],
        );
        $this->replaceLabels(
            $conversation,
            $categories,
            $problemTypes,
            $urgency,
            $needsReview,
            $candidateCount === 0 && $analyzed > 0,
        );

        return [
            'conversations' => 1,
            'messages_analyzed' => $analyzed,
            'messages_excluded' => $excluded,
            'reply_candidates' => $candidateCount,
            'tickets_needing_review' => $needsReview ? 1 : 0,
            'tickets_without_reply_candidate' => $candidateCount === 0 && $analyzed > 0 ? 1 : 0,
            'categories' => $categories,
        ];
    }

    /** @param array<string, int> $counts */
    private function primaryLabel(array $counts): ?string
    {
        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return array_key_first($counts);
    }

    /**
     * @param  array<string, int>  $categories
     * @param  array<string, int>  $problemTypes
     */
    private function replaceLabels(
        Conversation $conversation,
        array $categories,
        array $problemTypes,
        string $urgency,
        bool $needsReview,
        bool $withoutReplyCandidate,
    ): void {
        ConversationLabel::where('conversation_id', $conversation->id)->where('source', 'rule')->delete();
        $labels = [];
        foreach ($categories as $label => $count) {
            $labels[] = ['category', $label, $count];
        }
        foreach ($problemTypes as $label => $count) {
            $labels[] = ['problem_type', $label, $count];
        }
        if ($urgency !== 'normal') {
            $labels[] = ['urgency', $urgency, 1];
        }
        if (count($problemTypes) > 1) {
            $labels[] = ['workflow', 'multi_issue', count($problemTypes)];
        }
        if ($needsReview) {
            $labels[] = ['workflow', 'needs_review', 1];
        }
        if ($withoutReplyCandidate) {
            $labels[] = ['workflow', 'no_reply_candidate', 1];
        }

        foreach ($labels as [$type, $label, $count]) {
            ConversationLabel::create([
                'conversation_id' => $conversation->id,
                'label_type' => $type,
                'label' => $label,
                'evidence_count' => $count,
                'source' => 'rule',
                'analyzer_version' => self::VERSION,
            ]);
        }
    }

    private function urgencyRank(string $urgency): int
    {
        return match ($urgency) {
            'critical' => 2,
            'high' => 1,
            default => 0,
        };
    }

    private function body(ConversationEvent $event): string
    {
        return $event->body_text ?: html_entity_decode(strip_tags((string) $event->rich_text_html));
    }

    private function excerpt(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_substr($value, 0, 500);
    }
}
