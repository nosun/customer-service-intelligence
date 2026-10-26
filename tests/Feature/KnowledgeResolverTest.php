<?php

namespace Tests\Feature;

use App\Models\KnowledgeArticle;
use App\Services\Knowledge\KnowledgeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_verified_article_can_be_consumed_by_ticket_and_product_question(): void
    {
        $article = $this->article('shared_order_status');
        $article->bindings()->createMany([
            ['consumer' => 'product_question', 'intent_key' => 'ask_order_status', 'usage' => 'draft'],
            ['consumer' => 'ticket', 'intent_key' => 'production_order_status', 'usage' => 'draft'],
        ]);
        $resolver = app(KnowledgeResolver::class);

        $questionKnowledge = $resolver->forIntents(
            'product_question',
            'dressafford',
            ['ask_order_status'],
        );
        $ticketKnowledge = $resolver->forIntents(
            'ticket',
            'dressafford.com',
            ['production_order_status'],
        );

        $this->assertSame([$article->id], $questionKnowledge->pluck('id')->all());
        $processIds = KnowledgeArticle::where('key', 'like', 'ticket_flow_%_v2')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$article->id, ...$processIds], $ticketKnowledge->pluck('id')->all());
    }

    public function test_resolver_enforces_status_channel_site_and_enabled_binding(): void
    {
        $global = $this->article('global_return_policy');
        $global->bindings()->create([
            'consumer' => 'product_question', 'intent_key' => 'ask_return_policy', 'usage' => 'draft',
        ]);
        $scoped = $this->article('dressafford_return_policy', 'dressafford');
        $scoped->bindings()->create([
            'consumer' => 'product_question', 'intent_key' => 'ask_return_policy', 'usage' => 'draft',
        ]);
        $observed = $this->article('observed_policy', null, 'observed');
        $observed->bindings()->create([
            'consumer' => 'product_question', 'intent_key' => 'ask_observed', 'usage' => 'draft',
        ]);
        $disabled = $this->article('disabled_policy');
        $disabled->bindings()->create([
            'consumer' => 'product_question', 'intent_key' => 'ask_disabled', 'usage' => 'draft', 'enabled' => false,
        ]);
        $ticketOnly = $this->article('ticket_only');
        $ticketOnly->bindings()->create([
            'consumer' => 'ticket', 'intent_key' => '*', 'usage' => 'draft',
        ]);

        $resolved = app(KnowledgeResolver::class)->available('product_question', 'dressafford');

        $this->assertContains($scoped->id, $resolved->pluck('id'));
        $this->assertNotContains($global->id, $resolved->pluck('id'));
        $this->assertNotContains($observed->id, $resolved->pluck('id'));
        $this->assertNotContains($disabled->id, $resolved->pluck('id'));
        $this->assertNotContains($ticketOnly->id, $resolved->pluck('id'));
    }

    public function test_higher_priority_binding_wins_within_the_same_scope(): void
    {
        $lower = $this->article('lower_priority');
        $lower->bindings()->create([
            'consumer' => 'product_question', 'intent_key' => 'ask_payment', 'usage' => 'draft', 'priority' => 50,
        ]);
        $higher = $this->article('higher_priority');
        $higher->bindings()->create([
            'consumer' => 'product_question', 'intent_key' => 'ask_payment', 'usage' => 'draft', 'priority' => 200,
        ]);

        $resolved = app(KnowledgeResolver::class)->forIntents(
            'product_question',
            'dressafford',
            ['ask_payment'],
        );

        $this->assertSame([$higher->id], $resolved->pluck('id')->all());
    }

    private function article(string $key, ?string $siteKey = null, string $status = 'verified'): KnowledgeArticle
    {
        return KnowledgeArticle::create([
            'key' => $key,
            'title' => str_replace('_', ' ', $key),
            'knowledge_type' => 'policy',
            'status' => $status,
            'site_key' => $siteKey,
            'applicability' => 'Test only.',
            'required_facts' => [],
            'policy_rules' => [],
            'decision_tree' => [],
            'reply_strategy' => [],
            'reply_templates' => [],
            'action_steps' => [],
            'prohibited_claims' => [],
            'escalation_rules' => [],
            'automation_tier' => 'draft_sensitive',
            'version' => 1,
        ]);
    }
}
