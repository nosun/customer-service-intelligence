<?php

namespace Tests\Unit;

use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionDraft;
use App\Models\ProductQuestionDraftFeedback;
use App\Services\ProductQuestions\ProductQuestionDraftFeedbackReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductQuestionDraftFeedbackReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_summarizes_acceptance_edits_and_review_reasons(): void
    {
        $site = CommerceSite::create([
            'name' => 'Feedback', 'site_key' => 'feedback', 'admin_api_url' => 'https://feedback.test',
            'api_token' => 'secret', 'enabled' => true,
        ]);
        $question = ProductQuestion::create([
            'commerce_site_id' => $site->id, 'external_id' => 1, 'sn' => 'F-1', 'question' => 'Question',
            'reply_status' => 'unanswered', 'raw_payload' => [], 'remote_present' => true, 'synced_at' => now(),
        ]);
        $draft = ProductQuestionDraft::create([
            'product_question_id' => $question->id, 'status' => 'generated', 'model' => 'test',
            'prompt_version' => 'test', 'intents' => [], 'knowledge_article_ids' => [], 'facts' => [],
            'fact_sources' => [], 'missing_facts' => [], 'safety_flags' => [], 'recommended_visible' => true,
            'requires_human_review' => true, 'input_hash' => str_repeat('a', 64),
        ]);
        foreach ([
            ['kind' => 'unchanged'],
            ['kind' => 'edited', 'removed' => 'Too much detail', 'added' => 'Short answer'],
        ] as $index => $diff) {
            ProductQuestionDraftFeedback::create([
                'product_question_id' => $question->id, 'product_question_draft_id' => $draft->id,
                'status' => $index === 0 ? 'confirmed' : 'previewed', 'final_answer' => 'Answer',
                'final_visible' => true, 'text_diff' => $diff, 'similarity_percent' => $index === 0 ? 100 : 50,
                'review_reason' => 'Keep it concise', 'previewed_at' => now(),
            ]);
        }

        $report = app(ProductQuestionDraftFeedbackReport::class)->summarize();

        $this->assertSame(0.5, $report['accept_as_is_rate']);
        $this->assertSame(0.5, $report['edited_rate']);
        $this->assertSame(75.0, $report['average_similarity_percent']);
        $this->assertSame(1, $report['frequent_removed_text']['Too much detail']);
        $this->assertSame(2, $report['review_reasons']['Keep it concise']);
    }
}
