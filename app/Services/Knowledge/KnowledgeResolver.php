<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeArticle;
use App\Models\KnowledgeArticleBinding;
use Illuminate\Support\Collection;

class KnowledgeResolver
{
    /** @return Collection<int, KnowledgeArticle> */
    public function available(string $consumer, ?string $siteKey, string $usage = 'draft'): Collection
    {
        $siteKey = trim((string) $siteKey);
        $siteKeys = array_values(array_unique(array_filter([
            $siteKey,
            str_contains($siteKey, '.') ? strstr($siteKey, '.', true) : null,
        ], 'filled')));

        return KnowledgeArticle::query()
            ->where('status', 'verified')
            ->where(function ($query) use ($siteKeys): void {
                $query->whereNull('site_key');
                if ($siteKeys !== []) {
                    $query->orWhereIn('site_key', $siteKeys);
                }
            })
            ->whereHas('bindings', fn ($query) => $query
                ->where('consumer', $consumer)
                ->where('usage', $usage)
                ->where('enabled', true))
            ->withMax(['bindings as binding_priority' => fn ($query) => $query
                ->where('consumer', $consumer)
                ->where('usage', $usage)
                ->where('enabled', true)], 'priority')
            ->with(['bindings' => fn ($query) => $query
                ->where('consumer', $consumer)
                ->where('usage', $usage)
                ->where('enabled', true)
                ->orderByDesc('priority')
                ->orderBy('id')])
            ->when($siteKeys !== [], function ($query) use ($siteKeys): void {
                foreach ($siteKeys as $index => $candidate) {
                    $query->orderByRaw('case when site_key = ? then ? else ? end', [
                        $candidate,
                        $index,
                        count($siteKeys),
                    ]);
                }
            })
            ->orderByDesc('binding_priority')
            ->orderBy('key')
            ->get()
            ->pipe(fn (Collection $articles): Collection => $this->preferScopedBindings($articles));
    }

    /** @param list<string> $intents @return Collection<int, KnowledgeArticle> */
    public function forIntents(string $consumer, ?string $siteKey, array $intents, string $usage = 'draft'): Collection
    {
        $intents = array_values(array_unique(array_filter($intents, 'is_string')));

        return $this->available($consumer, $siteKey, $usage)
            ->filter(function (KnowledgeArticle $article) use ($intents): bool {
                $bound = $article->bindings->pluck('intent_key');

                return $bound->contains('*') || $bound->intersect($intents)->isNotEmpty();
            })
            ->values();
    }

    /** @return list<string> */
    public function intentKeys(KnowledgeArticle $article): array
    {
        return $article->bindings
            ->pluck('intent_key')
            ->filter(fn (string $intent): bool => $intent !== '*')
            ->unique()
            ->values()
            ->all();
    }

    public function intentKey(KnowledgeArticle $article): string
    {
        return $this->intentKeys($article)[0] ?? '*';
    }

    public function bind(
        KnowledgeArticle $article,
        string $consumer,
        string $intentKey = '*',
        string $usage = 'draft',
        int $priority = 100,
    ): KnowledgeArticleBinding {
        return $article->bindings()->updateOrCreate(
            ['consumer' => $consumer, 'intent_key' => $intentKey, 'usage' => $usage],
            ['priority' => $priority, 'enabled' => true],
        );
    }

    /** @param Collection<int, KnowledgeArticle> $articles @return Collection<int, KnowledgeArticle> */
    private function preferScopedBindings(Collection $articles): Collection
    {
        $claimed = [];

        return $articles->filter(function (KnowledgeArticle $article) use (&$claimed): bool {
            $intents = $this->intentKeys($article);
            if ($intents === []) {
                return true;
            }
            if (collect($intents)->every(fn (string $intent): bool => isset($claimed[$intent]))) {
                return false;
            }
            foreach ($intents as $intent) {
                $claimed[$intent] = true;
            }

            return true;
        })->values();
    }
}
