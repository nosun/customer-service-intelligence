<?php

namespace App\Services\TicketWork;

use App\Models\KnowledgeArticle;
use App\Services\Knowledge\KnowledgeResolver;
use Illuminate\Support\Collection;

class TicketPolicyRepository
{
    public function __construct(private readonly KnowledgeResolver $knowledge) {}

    /** @param list<string> $intents @return list<string> */
    public function baselineKeys(array $intents): array
    {
        $map = [
            'production_order_status' => ['production_status_eta_verification'],
            'logistics_tracking_delivery' => ['shipped_order_tracking_and_eta_communication'],
        ];

        return collect($intents)->flatMap(fn (string $intent): array => $map[$intent] ?? [])
            ->unique()->values()->all();
    }

    /** @return Collection<int,KnowledgeArticle> */
    public function available(?string $siteKey): Collection
    {
        return $this->knowledge->available('ticket', $siteKey);
    }

    /** @param list<string> $keys @return Collection<int,KnowledgeArticle> */
    public function selected(?string $siteKey, array $keys): Collection
    {
        $keys = array_values(array_unique(array_filter($keys, 'is_string')));

        return $this->available($siteKey)->whereIn('key', $keys)->values();
    }

    /** @return array<string,mixed> */
    public function payload(KnowledgeArticle $article): array
    {
        return [
            'id' => $article->id,
            'key' => $article->key,
            'version' => $article->version,
            'title' => $article->title,
            'knowledge_type' => $article->knowledge_type,
            'applicability' => $article->applicability,
            'automation_tier' => $article->automation_tier,
            'bound_intents' => $article->bindings->pluck('intent_key')->values()->all(),
            'required_facts' => $article->required_facts,
            'policy_rules' => $article->policy_rules,
            'decision_tree' => $article->decision_tree,
            'action_steps' => $article->action_steps,
            'site_key' => $article->site_key,
            'reply_strategy' => $article->reply_strategy,
            'reply_templates' => $article->reply_templates,
            'prohibited_claims' => $article->prohibited_claims,
            'escalation_rules' => $article->escalation_rules,
        ];
    }
}
