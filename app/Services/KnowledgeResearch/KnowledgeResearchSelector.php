<?php

namespace App\Services\KnowledgeResearch;

use App\Models\Conversation;
use App\Models\KnowledgeResearchBatchItem;
use Illuminate\Support\Collection;

class KnowledgeResearchSelector
{
    private const BUCKETS = ['presales', 'checkout', 'production', 'logistics', 'after_sales', 'dispute', 'other', 'unknown'];

    /** @return Collection<int, Conversation> */
    public function select(\DateTimeInterface $windowStart, \DateTimeInterface $windowEnd, int $limit, string $seed): Collection
    {
        $candidates = Conversation::query()
            ->with('analysis')
            ->whereIn('silo', ['tickets', 'archive'])
            ->whereBetween('created_at_external', [$windowStart, $windowEnd])
            ->whereHas('events', fn ($events) => $events
                ->where('event_type', 'message')
                ->where('is_private', false)
                ->where('author_type', 'client'))
            ->whereNotIn('id', KnowledgeResearchBatchItem::query()->where('status', 'completed')->select('conversation_id'))
            ->get(['id', 'external_id', 'external_short_id', 'site_key', 'created_at_external']);

        $ranked = $candidates
            ->sortBy(fn (Conversation $conversation): string => hash('sha256', $seed.'|'.$conversation->id))
            ->values();
        $groups = $ranked->groupBy(fn (Conversation $conversation): string => $this->bucket($conversation));
        $selected = collect();

        while ($selected->count() < $limit) {
            $added = false;
            foreach (self::BUCKETS as $bucket) {
                $candidate = $groups->get($bucket)?->shift();
                if ($candidate === null) {
                    continue;
                }
                $selected->push($candidate);
                $added = true;
                if ($selected->count() === $limit) {
                    break 2;
                }
            }
            if (! $added) {
                break;
            }
        }

        return $selected->values();
    }

    private function bucket(Conversation $conversation): string
    {
        $category = $conversation->analysis?->primary_category;

        return is_string($category) && in_array($category, self::BUCKETS, true) ? $category : 'unknown';
    }
}
