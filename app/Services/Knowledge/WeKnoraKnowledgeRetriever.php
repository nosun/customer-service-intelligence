<?php

namespace App\Services\Knowledge;

use App\Models\Conversation;
use App\Models\KnowledgePublication;
use App\Services\TicketWork\TicketRuntimeContext;
use Throwable;

class WeKnoraKnowledgeRetriever
{
    public function __construct(private readonly WeKnoraClient $client, private readonly KnowledgeResolver $knowledge, private readonly TicketRuntimeContext $context) {}

    /** Search ranks approved local policies; remote prose never grants authority. */
    public function keys(Conversation $ticket, array $plan): array
    {
        if (! config('weknora.retrieval_enabled')) {
            return [];
        }
        $eligible = $this->knowledge->forIntents('ticket', $ticket->site_key, $plan['intents'] ?? [])->keyBy('id');
        $publications = KnowledgePublication::whereIn('knowledge_article_id', $eligible->keys())->where('status', 'published')->get()
            ->filter(fn ($publication) => (int) $publication->version === (int) $eligible[$publication->knowledge_article_id]->version
                && in_array($publication->knowledge_base_id, config('weknora.knowledge_bases', []), true));
        $query = $this->context->redactForConversation(implode(' ', array_column($plan['clauses'] ?? [], 'text')), $ticket);
        if ($query === '') {
            return [];
        }
        $keys = [];
        foreach ($publications->groupBy('knowledge_base_id') as $base => $group) {
            try {
                $hits = $this->client->search((string) $base, mb_substr($query, 0, 2000), $group->pluck('remote_id')->all());
                foreach ($hits as $hit) {
                    $publication = $group->firstWhere('remote_id', $hit['knowledge_id'] ?? null);
                    if ($publication !== null) {
                        $keys[] = $eligible[$publication->knowledge_article_id]->key;
                    }
                }
            } catch (Throwable $exception) {
                // Keep the existing approved policy path available during retrieval outages.
                report($exception);
            }
        }

        return array_slice(array_values(array_unique($keys)), 0, 3);
    }
}
