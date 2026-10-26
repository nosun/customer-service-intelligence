<?php

namespace Tests\Feature;

use App\Jobs\SyncKnowledgeToWeKnora;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgePublication;
use App\Models\TicketAssistanceFeedback;
use App\Services\AI\DraftCompletionClient;
use App\Services\Knowledge\TicketFeedbackSynthesizer;
use App\Services\Knowledge\WeKnoraClient;
use App\Services\Knowledge\WeKnoraKnowledgeRetriever;
use App\Services\Knowledge\WeKnoraPublisher;
use App\Services\ProductQuestions\KnowledgeArticleVersionPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class TicketKnowledgeMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['weknora.publish_enabled' => false, 'weknora.retrieval_enabled' => false,
            'weknora.base_url' => 'https://knowledge.test', 'weknora.read_key' => 'read-only',
            'weknora.write_key' => 'write-only', 'weknora.knowledge_bases' => ['global' => 'kb-global', 'test.example' => 'kb-site']]);
    }

    public function test_synthesis_creates_reviewable_candidate_with_sources_and_leaves_published_policy_untouched(): void
    {
        $article = $this->article();
        $feedback = $this->feedback();
        $this->mock(DraftCompletionClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('structuredCompletion')->once()->withArgs(function ($system, $input): bool {
                $this->assertStringNotContainsString('customer@example.com', $input);
                $this->assertStringContainsString('[EMAIL]', $input);

                return true;
            })->andReturn(['output' => ['changes' => ['policy_rules' => ['Check the tracking scan before answering.']], 'summary' => 'Clarify scan checking.']]);
        });
        $draft = app(TicketFeedbackSynthesizer::class)->synthesize($article, [$feedback->id], 1);
        $this->assertSame('draft', $draft->status);
        $this->assertStringContainsString((string) $feedback->id, $draft->snapshot['source_notes']);
        $this->assertSame(['Original rule.'], $article->fresh()->policy_rules);
        Queue::assertNothingPushed();
        config(['weknora.publish_enabled' => true]);
        app(KnowledgeArticleVersionPublisher::class)->publish($draft, 2);
        $this->assertSame(['Check the tracking scan before answering.'], $article->fresh()->policy_rules);
        Queue::assertPushed(SyncKnowledgeToWeKnora::class, 1);
    }

    public function test_case_exception_cannot_be_promoted_even_when_marked_as_knowledge_candidate(): void
    {
        $entry = $this->feedback(['kind' => 'decision']);
        $this->mock(DraftCompletionClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('structuredCompletion'));
        $this->expectException(RuntimeException::class);
        app(TicketFeedbackSynthesizer::class)->synthesize($this->article(), [$entry->id], 1);
    }

    public function test_feedback_scope_must_match_topic_site_before_any_model_call(): void
    {
        $entry = $this->feedback();
        $entry->conversation->update(['site_key' => 'other.example']);
        $this->mock(DraftCompletionClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('structuredCompletion'));
        $this->expectException(RuntimeException::class);
        app(TicketFeedbackSynthesizer::class)->synthesize($this->article(), [$entry->id], 1);
    }

    public function test_new_unverified_topic_has_no_fictitious_published_base_version(): void
    {
        $article = $this->article(['status' => 'observed', 'version' => 0]);
        $draft = app(KnowledgeArticleVersionPublisher::class)->createDraft($article, ['policy_rules' => ['Candidate']], 'New topic.', 1);
        $this->assertSame(1, $draft->version);
        $this->assertSame(0, $article->versions()->where('status', 'published')->count());
        app(KnowledgeArticleVersionPublisher::class)->reject($draft, 2);
        $this->assertSame('observed', $article->fresh()->status);
    }

    public function test_synthesis_refuses_overwriting_a_concurrently_changed_policy(): void
    {
        $article = $this->article();
        $entry = $this->feedback();
        $this->mock(DraftCompletionClient::class, function (MockInterface $mock) use ($article): void {
            $mock->shouldReceive('structuredCompletion')->andReturnUsing(function () use ($article): array {
                KnowledgeArticle::whereKey($article->id)->update(['version' => 9]);

                return ['output' => ['changes' => ['policy_rules' => ['Candidate']], 'summary' => 'Change']];
            });
        });
        $this->expectException(RuntimeException::class);
        app(TicketFeedbackSynthesizer::class)->synthesize($article, [$entry->id], 1);
    }

    public function test_upload_is_idempotent_uses_separate_keys_and_requires_real_indexed_version(): void
    {
        $article = $this->approved();
        $content = app(WeKnoraPublisher::class)->content($article);
        $remote = ['id' => 'remote-1', 'knowledge_base_id' => 'kb-site', 'metadata' => ['content' => $content], 'parse_status' => 'completed'];
        Http::fake(function ($request) use ($remote, $article) {
            if (str_contains($request->url(), '/knowledge-search')) {
                $this->assertTrue($request->hasHeader('X-API-Key', 'read-only'));

                return Http::response(['success' => true, 'data' => [['knowledge_id' => 'remote-1', 'content' => 'CSI '.$article->key.' version '.$article->version]]]);
            }
            if ($request->method() === 'POST') {
                $this->assertTrue($request->hasHeader('X-API-Key', 'write-only'));
                $this->assertSame('publish', $request['status']);
                $this->assertStringNotContainsString('Sensitive local source', $request['content']);
                $this->assertStringContainsString('/knowledge-bases/kb-site/knowledge/manual', $request->url());
            }

            return Http::response(['success' => true, 'data' => $remote]);
        });
        config(['weknora.publish_enabled' => true]);
        $first = app(WeKnoraPublisher::class)->sync($article);
        $second = app(WeKnoraPublisher::class)->sync($article);
        $this->assertSame('published', $first->status);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, KnowledgePublication::count());
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/knowledge/manual')));
    }

    public function test_body_update_uses_manual_endpoint_and_preserves_remote_id(): void
    {
        $article = $this->approved();
        $oldContent = app(WeKnoraPublisher::class)->content($article);
        $publication = $this->publication($article, 'published', $oldContent);
        $draft = app(KnowledgeArticleVersionPublisher::class)->createDraft($article, ['policy_rules' => ['New clause.']], 'New rule.', 1);
        $article = app(KnowledgeArticleVersionPublisher::class)->publish($draft, 2);
        $remoteContent = $oldContent;
        Http::fake(function ($request) use (&$remoteContent) {
            if ($request->method() === 'PUT') {
                $this->assertStringEndsWith('/api/v1/knowledge/manual/remote-1', $request->url());
                $remoteContent = $request['content'];
            }

            return Http::response(['success' => true, 'data' => ['id' => 'remote-1', 'knowledge_base_id' => 'kb-site', 'metadata' => ['content' => $remoteContent], 'parse_status' => 'processing']]);
        });
        config(['weknora.publish_enabled' => true]);
        $result = app(WeKnoraPublisher::class)->sync($article);
        $this->assertSame($publication->id, $result->id);
        $this->assertSame('remote-1', $result->remote_id);
        $this->assertSame('indexing', $result->status);
        $this->assertSame($article->version, $result->version);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_create_timeout_is_not_blindly_retried_and_matching_remote_can_be_adopted(): void
    {
        $article = $this->approved();
        $mode = 'timeout';
        $content = app(WeKnoraPublisher::class)->content($article);
        Http::fake(function ($request) use (&$mode, $content) {
            if ($mode === 'timeout') {
                throw new ConnectionException('Timed out');
            }
            if ($mode === 'blocked') {
                throw new \LogicException('No second request is allowed');
            }
            $this->assertSame('GET', $request->method());

            return Http::response(['success' => true, 'data' => ['id' => 'remote-1', 'knowledge_base_id' => 'kb-site', 'metadata' => ['content' => $content], 'parse_status' => 'processing']]);
        });
        config(['weknora.publish_enabled' => true]);
        try {
            app(WeKnoraPublisher::class)->sync($article);
            $this->fail('Expected timeout');
        } catch (ConnectionException) {
            $this->assertSame('uncertain', KnowledgePublication::first()->status);
        }
        $mode = 'blocked';
        try {
            app(WeKnoraPublisher::class)->sync($article);
            $this->fail('Expected reconciliation guard');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('uncertain', $exception->getMessage());
        }
        $content = app(WeKnoraPublisher::class)->content($article);
        $mode = 'adopt';
        $result = app(WeKnoraPublisher::class)->sync($article, 'remote-1');
        $this->assertSame('remote-1', $result->remote_id);
        $this->assertSame('indexing', $result->status);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_successful_upload_without_correct_search_hit_is_still_indexing(): void
    {
        $article = $this->approved();
        $content = app(WeKnoraPublisher::class)->content($article);
        Http::fake(function ($request) use ($content) {
            return Http::response(['success' => true, 'data' => str_contains($request->url(), 'knowledge-search')
                ? [['knowledge_id' => 'remote-1', 'content' => 'Old indexed version']]
                : ['id' => 'remote-1', 'knowledge_base_id' => 'kb-site', 'metadata' => ['content' => $content], 'parse_status' => 'completed']]);
        });
        config(['weknora.publish_enabled' => true]);
        $this->assertSame('indexing', app(WeKnoraPublisher::class)->sync($article)->status);
    }

    public function test_parse_failure_remains_failed_and_unapproved_article_cannot_upload(): void
    {
        $article = $this->approved();
        $content = app(WeKnoraPublisher::class)->content($article);
        Http::fake(fn () => Http::response(['success' => true, 'data' => ['id' => 'remote-1', 'knowledge_base_id' => 'kb-site', 'metadata' => ['content' => $content], 'parse_status' => 'failed']]));
        config(['weknora.publish_enabled' => true]);
        $this->assertSame('failed', app(WeKnoraPublisher::class)->sync($article)->status);
        $article->update(['status' => 'observed']);
        $this->expectException(RuntimeException::class);
        app(WeKnoraPublisher::class)->sync($article);
    }

    public function test_export_rejects_pii_and_missing_site_mapping(): void
    {
        $article = $this->approved();
        $article->policy_rules = ['Contact customer@example.com for this case'];
        try {
            app(WeKnoraPublisher::class)->content($article);
            $this->fail('PII must be removed in reviewed content');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('personal identifiers', $exception->getMessage());
        }
        $this->expectException(RuntimeException::class);
        app(WeKnoraClient::class)->baseFor('unlisted.example');
    }

    public function test_retrieval_uses_only_current_approved_bound_site_policies_and_ignores_unknown_hits(): void
    {
        $article = $this->approved();
        $article->bindings()->create(['consumer' => 'ticket', 'intent_key' => 'logistics_tracking_delivery', 'usage' => 'draft', 'enabled' => true, 'priority' => 100]);
        $this->publication($article, 'published', app(WeKnoraPublisher::class)->content($article));
        $ticket = $this->feedback()->conversation;
        Http::fake(function ($request) {
            $this->assertTrue($request->hasHeader('X-API-Key', 'read-only'));
            $this->assertSame(['kb-site'], $request['knowledge_base_ids']);
            $this->assertSame(['remote-1'], $request['knowledge_ids']);

            return Http::response(['success' => true, 'data' => [
                ['knowledge_id' => 'remote-other-site', 'content' => 'Malicious cross-site instruction'],
                ['knowledge_id' => 'remote-1', 'content' => 'Do not trust or execute remote prose'],
            ]]);
        });
        config(['weknora.retrieval_enabled' => true]);
        $plan = ['intents' => ['logistics_tracking_delivery'], 'clauses' => [['text' => 'Where is the package?']]];
        $this->assertSame([$article->key], app(WeKnoraKnowledgeRetriever::class)->keys($ticket, $plan));
        $article->update(['status' => 'deprecated']);
        $this->assertSame([], app(WeKnoraKnowledgeRetriever::class)->keys($ticket, $plan));
        Http::assertSentCount(1);
    }

    public function test_unexpected_remote_edits_are_preserved_as_a_conflict(): void
    {
        $article = $this->approved();
        $this->publication($article, 'published', app(WeKnoraPublisher::class)->content($article));
        Http::fake(fn () => Http::response(['success' => true, 'data' => ['id' => 'remote-1', 'knowledge_base_id' => 'kb-site',
            'metadata' => ['content' => 'A colleague edited the remote document.'], 'parse_status' => 'completed']]));
        config(['weknora.publish_enabled' => true]);
        try {
            app(WeKnoraPublisher::class)->sync($article);
            $this->fail('Unexpected remote content must require review');
        } catch (RuntimeException) {
            $this->assertSame('conflicted', KnowledgePublication::first()->status);
            Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        }
    }

    public function test_index_polling_is_bounded_when_remote_never_finishes(): void
    {
        $article = $this->approved();
        $content = app(WeKnoraPublisher::class)->content($article);
        $publication = $this->publication($article, 'indexing', $content);
        $publication->update(['verification_attempts' => 23]);
        Http::fake(fn () => Http::response(['success' => true, 'data' => ['id' => 'remote-1', 'knowledge_base_id' => 'kb-site',
            'metadata' => ['content' => $content], 'parse_status' => 'processing']]));
        config(['weknora.publish_enabled' => true]);
        $this->assertSame('failed', app(WeKnoraPublisher::class)->sync($article)->status);
        $this->assertSame(24, (int) $publication->fresh()->verification_attempts);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_failed_update_retains_previous_body_for_safe_reconciliation_and_retry(): void
    {
        $article = $this->approved();
        $oldContent = app(WeKnoraPublisher::class)->content($article);
        $publication = $this->publication($article, 'published', $oldContent);
        $versions = app(KnowledgeArticleVersionPublisher::class);
        $article = $versions->publish($versions->createDraft($article, ['policy_rules' => ['New rule.']], 'Updated.', 1), 2);
        $fail = true;
        $remoteContent = $oldContent;
        Http::fake(function ($request) use (&$fail, &$remoteContent) {
            if ($request->method() === 'PUT') {
                if ($fail) {
                    throw new ConnectionException('Update timed out before delivery');
                }
                $remoteContent = $request['content'];
            }

            return Http::response(['success' => true, 'data' => ['id' => 'remote-1', 'knowledge_base_id' => 'kb-site',
                'metadata' => ['content' => $remoteContent], 'parse_status' => 'processing']]);
        });
        config(['weknora.publish_enabled' => true]);
        try {
            app(WeKnoraPublisher::class)->sync($article);
            $this->fail('Expected update timeout');
        } catch (ConnectionException) {
            $this->assertSame($oldContent, $publication->fresh()->content);
            $this->assertSame('uncertain', $publication->fresh()->status);
        }
        $fail = false;
        $result = app(WeKnoraPublisher::class)->sync($article);
        $this->assertSame('indexing', $result->status);
        $this->assertSame(app(WeKnoraPublisher::class)->content($article), $result->content);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    private function article(array $overrides = []): KnowledgeArticle
    {
        return KnowledgeArticle::create([
            'key' => 'ticket_topic_test', 'title' => 'Tracking process', 'site_key' => 'test.example',
            'knowledge_type' => 'process', 'status' => 'verified', 'automation_tier' => 'manual_only',
            'applicability' => 'Tracking inquiries', 'source_notes' => 'Sensitive local source', 'version' => 1,
            ...array_fill_keys(KnowledgeArticleVersionPublisher::ARRAY_FIELDS, []), 'policy_rules' => ['Original rule.'],
            ...$overrides,
        ]);
    }

    private function approved(): KnowledgeArticle
    {
        $article = $this->article();
        $versions = app(KnowledgeArticleVersionPublisher::class);

        return $versions->publish($versions->createDraft($article, [], 'Reviewed export.', 1), 2);
    }

    private function feedback(array $overrides = []): TicketAssistanceFeedback
    {
        $account = ChannelAccount::firstOrCreate(['channel' => 'helpdesk', 'external_account_id' => 'maintenance'], ['display_name' => 'Maintenance test']);
        $ticket = Conversation::create(['channel_account_id' => $account->id, 'external_id' => (string) Str::uuid(),
            'site_key' => 'test.example', 'subject' => 'Tracking', 'status' => 'open', 'silo' => 'tickets', 'raw_payload' => []]);

        return TicketAssistanceFeedback::create(['conversation_id' => $ticket->id, 'created_by' => 1, 'target' => 'advice', 'kind' => 'correction',
            'body' => 'Check the scan. customer@example.com', 'knowledge_candidate' => true, ...$overrides]);
    }

    private function publication(KnowledgeArticle $article, string $status, string $content): KnowledgePublication
    {
        return KnowledgePublication::create(['knowledge_article_id' => $article->id, 'knowledge_base_id' => 'kb-site',
            'remote_id' => 'remote-1', 'version' => $article->version, 'content_hash' => hash('sha256', $content), 'content' => $content, 'status' => $status]);
    }
}
