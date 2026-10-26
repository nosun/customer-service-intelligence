<?php

namespace App\Services\ProductQuestions;

use App\Jobs\SyncKnowledgeToWeKnora;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeArticleVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KnowledgeArticleVersionPublisher
{
    public const FIELDS = [
        'title', 'knowledge_type', 'site_key', 'applicability', 'automation_tier',
        'required_facts', 'policy_rules', 'decision_tree', 'reply_strategy', 'reply_templates',
        'action_steps', 'prohibited_claims', 'escalation_rules', 'source_notes',
    ];

    public const ARRAY_FIELDS = [
        'required_facts', 'policy_rules', 'decision_tree', 'reply_strategy', 'reply_templates',
        'action_steps', 'prohibited_claims', 'escalation_rules',
    ];

    /** @param array<string,mixed> $changes */
    public function createDraft(KnowledgeArticle $article, array $changes, ?string $summary, int $userId): KnowledgeArticleVersion
    {
        return DB::transaction(function () use ($article, $changes, $summary, $userId): KnowledgeArticleVersion {
            $locked = KnowledgeArticle::query()->lockForUpdate()->findOrFail($article->id);
            if ($locked->status === 'verified') {
                $locked->versions()->firstOrCreate(
                    ['version' => $locked->version],
                    [
                        'status' => 'published',
                        'snapshot' => $this->snapshot($locked),
                        'change_summary' => 'Snapshot of the current published Policy before versioned editing.',
                        'reviewed_by' => $locked->reviewed_by,
                        'reviewed_at' => $locked->reviewed_at,
                        'published_at' => $locked->reviewed_at ?? $locked->updated_at,
                    ],
                );
            }
            if ($locked->versions()->where('status', 'draft')->exists()) {
                throw new RuntimeException('This Policy already has an unpublished Draft version.');
            }
            $snapshot = $this->snapshot($locked);
            foreach (self::FIELDS as $field) {
                if (array_key_exists($field, $changes)) {
                    $snapshot[$field] = $changes[$field];
                }
            }
            $this->validate($snapshot);
            $version = max(
                (int) $locked->version,
                (int) $locked->versions()->max('version'),
            ) + 1;

            return $locked->versions()->create([
                'version' => $version,
                'status' => 'draft',
                'snapshot' => $snapshot,
                'change_summary' => trim((string) $summary) ?: null,
                'created_by' => $userId,
            ]);
        });
    }

    public function publish(KnowledgeArticleVersion $version, int $userId): KnowledgeArticle
    {
        return DB::transaction(function () use ($version, $userId): KnowledgeArticle {
            $version = KnowledgeArticleVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($version->status !== 'draft') {
                throw new RuntimeException('Only a Draft Policy version can be published.');
            }
            $article = KnowledgeArticle::query()->lockForUpdate()->findOrFail($version->knowledge_article_id);
            if ($version->version <= $article->version) {
                throw new RuntimeException('Policy version is older than the currently published version.');
            }
            $this->validate($version->snapshot);
            $article->update([
                ...array_intersect_key($version->snapshot, array_flip(self::FIELDS)),
                'status' => 'verified',
                'version' => $version->version,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
            ]);
            $version->update([
                'status' => 'published',
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'published_at' => now(),
            ]);

            if (config('weknora.publish_enabled')) {
                SyncKnowledgeToWeKnora::dispatch($article->id)->afterCommit();
            }

            return $article->fresh();
        });
    }

    public function reject(KnowledgeArticleVersion $version, int $userId): void
    {
        if ($version->status !== 'draft') {
            throw new RuntimeException('Only a Draft Policy version can be rejected.');
        }
        $version->update(['status' => 'rejected', 'reviewed_by' => $userId, 'reviewed_at' => now()]);
    }

    /** @return array<string,mixed> */
    public function snapshot(KnowledgeArticle $article): array
    {
        return collect(self::FIELDS)->mapWithKeys(fn (string $field): array => [$field => $article->{$field}])->all();
    }

    /** @param array<string,mixed> $snapshot */
    private function validate(array $snapshot): void
    {
        if (! filled($snapshot['title'] ?? null)) {
            throw new RuntimeException('Policy title is required.');
        }
        if (! in_array($snapshot['knowledge_type'] ?? null, array_keys(KnowledgeArticle::TYPES), true)) {
            throw new RuntimeException('Policy knowledge type is invalid.');
        }
        if (! in_array($snapshot['automation_tier'] ?? null, ['draft_low_risk', 'draft_sensitive', 'manual_only'], true)) {
            throw new RuntimeException('Policy automation tier is invalid.');
        }
        foreach (self::ARRAY_FIELDS as $field) {
            if (! is_array($snapshot[$field] ?? null)) {
                throw new RuntimeException("Policy {$field} must be a JSON array.");
            }
        }
    }
}
