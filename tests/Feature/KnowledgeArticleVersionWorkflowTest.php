<?php

namespace Tests\Feature;

use App\Models\KnowledgeArticle;
use App\Models\KnowledgeArticleVersion;
use App\Services\ProductQuestions\KnowledgeArticleVersionPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class KnowledgeArticleVersionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_draft_is_immutable_until_human_publication(): void
    {
        $article = $this->article();
        $publisher = app(KnowledgeArticleVersionPublisher::class);
        $draft = $publisher->createDraft($article, [
            'policy_rules' => ['New reviewed rule.'],
            'reply_templates' => [['conditions' => ['safe'], 'body' => 'New reply.']],
        ], 'Clarify the customer reply.', 12);

        $this->assertSame('draft', $draft->status);
        $this->assertSame(2, $draft->version);
        $this->assertSame(['Old rule.'], $article->fresh()->policy_rules);
        $this->assertSame(1, $article->fresh()->version);

        $published = $publisher->publish($draft, 15);

        $this->assertSame(['New reviewed rule.'], $published->policy_rules);
        $this->assertSame(2, $published->version);
        $this->assertSame('verified', $published->status);
        $this->assertSame('published', $draft->fresh()->status);
        $this->assertSame(15, $draft->fresh()->reviewed_by);
    }

    public function test_only_one_unpublished_policy_draft_is_allowed(): void
    {
        $this->expectException(RuntimeException::class);
        $article = $this->article();
        $publisher = app(KnowledgeArticleVersionPublisher::class);
        $publisher->createDraft($article, [], 'First', 1);
        $publisher->createDraft($article, [], 'Second', 1);
    }

    public function test_rejected_version_never_changes_the_published_policy(): void
    {
        $article = $this->article();
        $publisher = app(KnowledgeArticleVersionPublisher::class);
        $draft = $publisher->createDraft($article, ['policy_rules' => ['Rejected rule.']], 'Unsafe', 1);

        $publisher->reject($draft, 2);

        $this->assertSame('rejected', KnowledgeArticleVersion::findOrFail($draft->id)->status);
        $this->assertSame(['Old rule.'], $article->fresh()->policy_rules);
        $this->assertSame(1, $article->fresh()->version);
    }

    private function article(): KnowledgeArticle
    {
        return KnowledgeArticle::create([
            'key' => 'qa_global_version_test', 'title' => 'Version Test', 'knowledge_type' => 'policy',
            'status' => 'verified', 'automation_tier' => 'draft_low_risk', 'site_key' => null,
            'applicability' => 'Test only.', 'required_facts' => [], 'policy_rules' => ['Old rule.'],
            'decision_tree' => [], 'reply_strategy' => [], 'reply_templates' => [], 'action_steps' => [],
            'prohibited_claims' => [], 'escalation_rules' => [], 'source_notes' => 'Evidence.', 'version' => 1,
        ]);
    }
}
