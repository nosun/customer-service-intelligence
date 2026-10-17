<?php

namespace Tests\Feature;

use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionDraft;
use App\Models\ProductQuestionDraftRequest;
use App\Services\ProductQuestions\ProductQuestionDraftWorkflowHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductQuestionDraftWorkflowHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_snapshot_reports_status_latency_blocking_and_stuck_work(): void
    {
        $site = CommerceSite::create([
            'name' => 'Health', 'site_key' => 'health', 'admin_api_url' => 'https://health.test',
            'api_token' => 'secret', 'enabled' => true,
        ]);
        $question = ProductQuestion::create([
            'commerce_site_id' => $site->id, 'external_id' => 1, 'sn' => 'H-1', 'question' => 'Question',
            'reply_status' => 'unanswered', 'raw_payload' => [], 'remote_present' => true, 'synced_at' => now(),
        ]);
        $draft = ProductQuestionDraft::create([
            'product_question_id' => $question->id, 'status' => 'blocked', 'model' => 'test',
            'prompt_version' => 'test', 'intents' => [], 'knowledge_article_ids' => [], 'facts' => [],
            'fact_sources' => [], 'missing_facts' => [], 'safety_flags' => [], 'recommended_visible' => false,
            'requires_human_review' => true, 'input_hash' => str_repeat('a', 64),
        ]);
        ProductQuestionDraftRequest::create([
            'request_id' => 'completed-health', 'product_question_id' => $question->id,
            'product_question_draft_id' => $draft->id, 'workflow_key' => 'work/test', 'workflow_version' => '1',
            'status' => 'completed', 'started_at' => now()->subSeconds(2), 'finished_at' => now(),
        ]);
        $stuck = ProductQuestionDraftRequest::create([
            'request_id' => 'stuck-health', 'product_question_id' => $question->id,
            'workflow_key' => 'work/test', 'workflow_version' => '1', 'status' => 'queued',
        ]);
        $stuck->timestamps = false;
        $stuck->update(['created_at' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(3)]);

        $snapshot = app(ProductQuestionDraftWorkflowHealth::class)->snapshot();

        $this->assertSame(2, $snapshot['total']);
        $this->assertSame(1, $snapshot['status_counts']['completed']);
        $this->assertSame(1, $snapshot['status_counts']['queued']);
        $this->assertSame(1.0, $snapshot['blocked_draft_rate']);
        $this->assertSame(1, $snapshot['stuck_queued']);
        $this->assertFalse($snapshot['healthy']);
        $this->assertGreaterThanOrEqual(1900, $snapshot['p50_duration_ms']);
    }
}
