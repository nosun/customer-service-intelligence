<?php

namespace App\Services\ProductQuestions;

use App\Models\ProductQuestion;
use App\Models\ProductQuestionDraft;
use App\Models\ProductQuestionDraftFeedback;

class ProductQuestionDraftFeedbackRecorder
{
    public function recordPreview(
        ProductQuestion $question,
        ?ProductQuestionDraft $draft,
        string $finalAnswer,
        bool $visible,
        ?string $reason,
        int $reviewerId,
    ): ProductQuestionDraftFeedback {
        $original = $draft?->answer();
        [$similarity, $diff] = $this->diff($original, $finalAnswer);

        return ProductQuestionDraftFeedback::create([
            'product_question_id' => $question->id,
            'product_question_draft_id' => $draft?->id,
            'status' => 'previewed',
            'original_answer' => $original,
            'final_answer' => $finalAnswer,
            'final_visible' => $visible,
            'text_diff' => $diff,
            'similarity_percent' => $similarity,
            'review_reason' => trim((string) $reason) ?: null,
            'reviewed_by' => $reviewerId,
            'previewed_at' => now(),
        ]);
    }

    /** @return array{0:float|null,1:array<string,mixed>|null} */
    private function diff(?string $original, string $final): array
    {
        if ($original === null) {
            return [null, ['kind' => 'manual', 'final_length' => mb_strlen($final)]];
        }

        $percent = 0.0;
        similar_text($original, $final, $percent);
        $originalChars = preg_split('//u', $original, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $finalChars = preg_split('//u', $final, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $prefix = 0;
        $limit = min(count($originalChars), count($finalChars));
        while ($prefix < $limit && $originalChars[$prefix] === $finalChars[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < $limit - $prefix
            && $originalChars[count($originalChars) - 1 - $suffix] === $finalChars[count($finalChars) - 1 - $suffix]) {
            $suffix++;
        }

        return [round($percent, 2), [
            'kind' => $original === $final ? 'unchanged' : 'edited',
            'common_prefix_length' => $prefix,
            'common_suffix_length' => $suffix,
            'removed' => implode('', array_slice($originalChars, $prefix, count($originalChars) - $prefix - $suffix)),
            'added' => implode('', array_slice($finalChars, $prefix, count($finalChars) - $prefix - $suffix)),
        ]];
    }
}
