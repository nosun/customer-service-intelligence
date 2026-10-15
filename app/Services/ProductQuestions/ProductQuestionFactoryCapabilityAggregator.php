<?php

namespace App\Services\ProductQuestions;

use App\Models\CommerceSite;
use App\Models\ProductQuestionAnalysis;
use App\Models\ProductQuestionFactoryCapability;

class ProductQuestionFactoryCapabilityAggregator
{
    /** @return array{capabilities:int,questions:int} */
    public function aggregate(CommerceSite $site, int $days): array
    {
        $analyses = ProductQuestionAnalysis::query()
            ->with('question')
            ->where('work_queue', 'factory_review')
            ->whereNotNull('factory_capability_key')
            ->whereHas('question', fn ($questions) => $questions
                ->where('commerce_site_id', $site->id)
                ->where('remote_present', true)
                ->where('remote_created_at', '>=', now()->subDays($days)))
            ->get();

        foreach ($analyses->groupBy('factory_capability_key') as $key => $group) {
            $questions = $group->pluck('question')->filter();
            $existing = ProductQuestionFactoryCapability::where('commerce_site_id', $site->id)
                ->where('capability_key', $key)
                ->first();
            $factoryQuestions = $group->pluck('factory_question')->filter()->countBy()->sortDesc();
            ProductQuestionFactoryCapability::updateOrCreate(
                ['commerce_site_id' => $site->id, 'capability_key' => $key],
                [
                    'title' => $this->title($key),
                    'question_count' => $questions->count(),
                    'product_count' => $questions->pluck('sn')->filter()->unique()->count(),
                    'confidence' => round((float) $group->avg('confidence'), 4),
                    'evidence_question_ids' => $questions->pluck('id')->unique()->values()->all(),
                    'product_sns' => $questions->pluck('sn')->filter()->unique()->sort()->values()->all(),
                    'representative_questions' => $questions->sortByDesc('remote_created_at')->take(8)->map(fn ($question): array => [
                        'id' => $question->id,
                        'external_id' => $question->external_id,
                        'sn' => $question->sn,
                        'question' => $question->question,
                    ])->values()->all(),
                    'factory_question' => (string) ($factoryQuestions->keys()->first()
                        ?: '请确认该能力的适用范围、例外、费用以及对制作周期的影响。'),
                    'review_status' => $existing?->review_status ?? 'pending',
                    'applicability' => $existing?->applicability,
                    'exceptions' => $existing?->exceptions,
                    'fee_policy' => $existing?->fee_policy,
                    'lead_time_policy' => $existing?->lead_time_policy,
                    'factory_answer' => $existing?->factory_answer,
                    'review_notes' => $existing?->review_notes,
                    'reviewed_by' => $existing?->reviewed_by,
                    'reviewed_at' => $existing?->reviewed_at,
                ],
            );
        }

        return ['capabilities' => $analyses->groupBy('factory_capability_key')->count(), 'questions' => $analyses->count()];
    }

    private function title(string $key): string
    {
        return [
            'custom_size' => '定制尺码', 'custom_color' => '定制颜色', 'custom_length' => '定制长度',
            'add_pockets' => '增加口袋', 'add_sleeves' => '增加袖子', 'remove_sleeves' => '去除袖子',
            'change_neckline' => '修改领型', 'change_back' => '修改后背', 'remove_train' => '去除拖尾',
            'add_train' => '增加拖尾', 'mix_design_elements' => '组合不同设计元素', 'change_fabric' => '更换面料',
            'change_lining' => '修改内衬', 'change_embellishment' => '修改装饰', 'rush_production' => '加急制作',
            'other_customization' => '其他定制',
        ][$key] ?? $key;
    }
}
