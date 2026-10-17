<?php

namespace App\Services\ProductQuestions;

use App\Models\ProductQuestionDraftFeedback;

class ProductQuestionDraftFeedbackReport
{
    /** @return array<string,mixed> */
    public function summarize(int $days = 30): array
    {
        $since = now()->subDays(max(1, $days));
        $feedback = ProductQuestionDraftFeedback::query()
            ->where('previewed_at', '>=', $since)
            ->get();
        $withDraft = $feedback->whereNotNull('product_question_draft_id');
        $unchanged = $withDraft->filter(fn ($item): bool => data_get($item->text_diff, 'kind') === 'unchanged')->count();
        $edited = $withDraft->filter(fn ($item): bool => data_get($item->text_diff, 'kind') === 'edited')->count();

        return [
            'window_days' => max(1, $days),
            'generated_at' => now()->toIso8601String(),
            'previewed' => $feedback->count(),
            'confirmed' => $feedback->where('status', 'confirmed')->count(),
            'with_ai_draft' => $withDraft->count(),
            'accept_as_is_rate' => $this->rate($unchanged, $withDraft->count()),
            'edited_rate' => $this->rate($edited, $withDraft->count()),
            'average_similarity_percent' => round((float) $withDraft->whereNotNull('similarity_percent')->avg('similarity_percent'), 2),
            'frequent_removed_text' => $this->frequent($withDraft, 'removed'),
            'frequent_added_text' => $this->frequent($withDraft, 'added'),
            'review_reasons' => $feedback->filter(fn ($item): bool => filled($item->review_reason))
                ->countBy(fn ($item): string => mb_substr(trim($item->review_reason), 0, 200))
                ->sortDesc()->take(10)->all(),
        ];
    }

    private function rate(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : round($count / $total, 4);
    }

    /** @return array<string,int> */
    private function frequent($feedback, string $field): array
    {
        return $feedback->map(fn ($item): string => mb_substr(trim((string) data_get($item->text_diff, $field)), 0, 300))
            ->filter()->countBy()->sortDesc()->take(10)->all();
    }
}
