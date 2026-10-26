<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeArticle;
use App\Models\KnowledgePublication;
use App\Services\ProductQuestions\KnowledgeArticleVersionPublisher;
use App\Services\TicketWork\TicketRuntimeContext;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class WeKnoraPublisher
{
    public function __construct(private readonly WeKnoraClient $client) {}

    public function sync(KnowledgeArticle $article, ?string $adoptId = null): KnowledgePublication
    {
        if (! config('weknora.publish_enabled')) {
            throw new RuntimeException('WeKnora publishing is disabled.');
        }
        $lock = Cache::lock('weknora:publish:'.$article->id, 60);
        if (! $lock->get()) {
            throw new RuntimeException('This article is already being synchronized.');
        }
        try {
            $article = $article->fresh();
            $version = $article->versions()->where('version', $article->version)->where('status', 'published')->first();
            if ($article->status !== 'verified' || $version === null || $version->reviewed_by === null
                || $version->snapshot != app(KnowledgeArticleVersionPublisher::class)->snapshot($article)) {
                throw new RuntimeException('Only the exact current human-approved version can be exported.');
            }
            $base = $this->client->baseFor($article->site_key);
            $content = $this->content($article);
            $hash = hash('sha256', $content);
            $publication = KnowledgePublication::firstOrCreate(['knowledge_article_id' => $article->id, 'knowledge_base_id' => $base], [
                'version' => $article->version, 'content_hash' => $hash, 'content' => $content, 'status' => 'pending',
            ]);
            if ($publication->remote_id === null && in_array($publication->status, ['writing', 'uncertain'], true) && $adoptId === null) {
                throw new RuntimeException('Create outcome is uncertain. Reconcile the remote document ID before retrying.');
            }
            if ($adoptId !== null) {
                $remote = $this->client->get($adoptId);
                $this->assertRemote($remote, $base, $content);
                $publication->update(['remote_id' => $adoptId]);
            }
            $needsWrite = $publication->remote_id === null;
            if (! $needsWrite) {
                $remote = $this->client->get($publication->remote_id);
                if (($remote['knowledge_base_id'] ?? null) !== $base) {
                    throw new RuntimeException('Remote document belongs to a different knowledge base.');
                }
                $remoteContent = data_get($remote, 'metadata.content');
                if ($remoteContent !== $content && $remoteContent !== $publication->content) {
                    $publication->update(['status' => 'conflicted', 'error_message' => 'Remote content changed outside the approved publication flow.']);
                    throw new RuntimeException('Review remote edits before overwriting this document.');
                }
                $needsWrite = $remoteContent !== $content;
            }
            $revision = ['version' => $article->version, 'content_hash' => $hash, 'content' => $content,
                'verification_attempts' => $needsWrite || (int) $publication->version !== (int) $article->version ? 0 : $publication->verification_attempts];
            if ($needsWrite) {
                $publication->update(['status' => 'writing', 'error_message' => null]);
                try {
                    $saved = $this->client->save($base, $publication->remote_id, $article->title, $content);
                    if (! is_string($saved['id'] ?? null) || $saved['id'] === '') {
                        throw new RuntimeException('Missing remote knowledge ID.');
                    }
                    $publication->update([...$revision, 'remote_id' => $saved['id'], 'status' => 'indexing']);
                } catch (Throwable $exception) {
                    // Never blindly repeat a create after timeout, crash or malformed acknowledgement.
                    $publication->update(['status' => 'uncertain', 'error_message' => 'Remote write requires reconciliation.']);
                    throw $exception;
                }
            } else {
                $publication->update($revision);
            }
            $remote = $this->client->get($publication->remote_id);
            $this->assertRemote($remote, $base, $content);
            if (in_array($remote['parse_status'] ?? '', ['failed', 'cancelled', 'deleting'], true)) {
                $publication->update(['status' => 'failed', 'error_message' => 'Remote parsing failed or document is unavailable.']);

                return $publication->fresh();
            }
            $status = 'indexing';
            if (($remote['parse_status'] ?? null) === 'completed') {
                $hits = $this->client->search($base, $article->key.' version '.$article->version, [$publication->remote_id]);
                // Exact document + version marker in an actual indexed chunk, not merely HTTP success.
                if (collect($hits)->contains(fn ($hit) => ($hit['knowledge_id'] ?? null) === $publication->remote_id
                    && str_contains((string) ($hit['content'] ?? ''), $this->marker($article)))) {
                    $status = 'published';
                }
            }
            if ($article->fresh()->version !== $article->version || $article->fresh()->status !== 'verified') {
                $status = 'stale';
            }
            $attempts = $status === 'indexing' ? $publication->verification_attempts + 1 : 0;
            $timedOut = $status === 'indexing' && $attempts >= 24;
            $publication->update(['status' => $timedOut ? 'failed' : $status, 'verification_attempts' => $attempts,
                'error_message' => $timedOut ? 'Index verification exhausted 24 checks; inspect the remote parser and search results.' : null]);

            return $publication->fresh();
        } finally {
            $lock->release();
        }
    }

    public function content(KnowledgeArticle $article): string
    {
        // Source notes and original Ticket snapshots stay in the local review system.
        $body = '# '.$article->title."\n\n".$this->marker($article)."\n\n";
        foreach (['applicability', ...KnowledgeArticleVersionPublisher::ARRAY_FIELDS] as $field) {
            $body .= '## '.$field."\n\n".json_encode($article->{$field}, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n\n";
        }
        if (app(TicketRuntimeContext::class)->redact($body) !== trim($body)) {
            throw new RuntimeException('Remove personal identifiers in a reviewed knowledge revision before export.');
        }

        return $body;
    }

    public function marker(KnowledgeArticle $article): string
    {
        return 'CSI '.$article->key.' version '.$article->version;
    }

    private function assertRemote(array $remote, string $base, string $content): void
    {
        if (($remote['knowledge_base_id'] ?? null) !== $base || data_get($remote, 'metadata.content') !== $content) {
            throw new RuntimeException('Remote scope/content does not match the approved version.');
        }
    }
}
