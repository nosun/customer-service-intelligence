<?php

namespace App\Console\Commands;

use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Services\ProductQuestions\ProductQuestionDraftGenerator;
use Illuminate\Console\Command;

class DraftProductQuestionAnswers extends Command
{
    protected $signature = 'product-questions:draft
                            {--site= : Required site key or numeric ID}
                            {--question=* : Remote question ID; repeat for an explicit review set}
                            {--limit=5 : Maximum recent unanswered questions when --question is omitted}';

    protected $description = 'Generate local AI drafts from verified policies and current facts without previewing or sending replies';

    public function handle(ProductQuestionDraftGenerator $generator): int
    {
        $selected = trim((string) $this->option('site'));
        if ($selected === '') {
            $this->error('--site is required.');

            return self::INVALID;
        }
        $site = CommerceSite::query()->where('enabled', true)
            ->where(function ($query) use ($selected): void {
                $query->where('site_key', $selected);
                if (ctype_digit($selected)) {
                    $query->orWhere('id', (int) $selected);
                }
            })->first();
        if ($site === null) {
            $this->error("未找到已启用站点：{$selected}");

            return self::FAILURE;
        }
        if (! $generator->isConfigured()) {
            $this->error('MiniMax 尚未配置。');

            return self::FAILURE;
        }

        $query = ProductQuestion::query()
            ->where('commerce_site_id', $site->id)
            ->where('remote_present', true)
            ->with('analysis')
            ->orderByDesc('remote_created_at');
        $questionIds = array_values(array_filter(array_map('intval', $this->option('question'))));
        if ($questionIds !== []) {
            $query->whereIn('external_id', $questionIds);
        } else {
            $limit = max(1, min(20, (int) $this->option('limit')));
            $query->where('reply_status', 'unanswered')->limit($limit);
        }
        $questions = $query->get();
        if ($questions->isEmpty()) {
            $this->warn('没有匹配的线上问答。');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($questions as $question) {
            $draft = $generator->generate($question);
            $this->line(sprintf(
                '#%d draft=%d status=%s intents=%s visible=%s missing=%d flags=%d',
                $question->external_id,
                $draft->id,
                $draft->status,
                implode(',', $draft->intents ?: []),
                $draft->recommended_visible ? 'public' : 'private',
                count($draft->missing_facts ?: []),
                count($draft->safety_flags ?: []),
            ));
            $failed = $failed || $draft->status === 'failed';
        }

        $this->info('仅生成本地草稿；未调用 Preview，未向站点发送任何回复。');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
