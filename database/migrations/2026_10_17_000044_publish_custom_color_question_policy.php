<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $source = DB::table('knowledge_articles')
            ->where('key', 'color_customization_not_custom_size_classification')
            ->where('status', 'verified')
            ->first();
        if ($source === null || DB::table('knowledge_articles')->where('key', 'qa_global_request_custom_color')->exists()) {
            return;
        }

        $now = now();
        DB::table('knowledge_articles')->insert([
            'key' => 'qa_global_request_custom_color',
            'title' => '售前颜色定制可行性核实',
            'knowledge_type' => 'policy',
            'status' => 'verified',
            'site_key' => null,
            'applicability' => '适用于客户询问当前商品能否制作页面或色卡以外的目标颜色。仅询问页面现有颜色、配色效果或部件颜色时不适用。',
            'required_facts' => $source->required_facts,
            'policy_rules' => $source->policy_rules,
            'decision_tree' => $source->decision_tree,
            'reply_strategy' => $source->reply_strategy,
            'reply_templates' => $source->reply_templates,
            'action_steps' => $source->action_steps,
            'prohibited_claims' => $source->prohibited_claims,
            'escalation_rules' => $source->escalation_rules,
            'source_notes' => trim((string) $source->source_notes.' 已发布为产品问答 request_custom_color Policy；仍须按当前商品和目标颜色核实，不得自动承诺。'),
            'version' => 1,
            'reviewed_by' => $source->reviewed_by,
            'reviewed_at' => $source->reviewed_at ?? $now,
            'automation_tier' => 'draft_sensitive',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('knowledge_articles')->where('key', 'qa_global_request_custom_color')->delete();
    }
};
