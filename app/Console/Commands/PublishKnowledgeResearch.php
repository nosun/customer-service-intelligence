<?php

namespace App\Console\Commands;

use App\Models\KnowledgeArticle;
use App\Models\KnowledgeResearchFinding;
use App\Services\KnowledgeResearch\KnowledgeResearchReport;
use Illuminate\Console\Command;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublishKnowledgeResearch extends Command
{
    protected $signature = 'helpdesk:research:publish
        {--scenario= : Scenario key to publish}
        {--batch= : Optional source batch key}
        {--status=observed : observed, verified, conflicted, or deprecated}
        {--reviewed-by= : Required administrator ID when publishing verified knowledge}';

    protected $description = 'Publish one reviewed research finding into the versioned knowledge base';

    public function handle(KnowledgeResearchReport $report): int
    {
        $scenario = trim((string) $this->option('scenario'));
        $batch = trim((string) $this->option('batch'));
        $status = trim((string) $this->option('status'));
        $reviewedBy = $this->option('reviewed-by') === null ? null : (int) $this->option('reviewed-by');
        try {
            validator(compact('scenario', 'status'), [
                'scenario' => ['required', 'string', 'max:150'],
                'status' => ['required', Rule::in(array_keys(KnowledgeArticle::STATUSES))],
            ])->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }
        if ($status === 'verified' && ($reviewedBy === null || $reviewedBy < 1)) {
            $this->error('发布 verified 知识必须提供有效的 --reviewed-by。');

            return self::INVALID;
        }

        $finding = KnowledgeResearchFinding::query()
            ->where('scenario_key', $scenario)
            ->when($batch !== '', fn ($query) => $query->whereHas('batch', fn ($batches) => $batches->where('batch_key', $batch)))
            ->latest('id')
            ->first();
        if ($finding === null) {
            $this->error('找不到指定的场景候选。');

            return self::INVALID;
        }
        $existing = KnowledgeArticle::where('key', $scenario)->first();
        if ($existing?->status === 'verified' && $status === 'observed') {
            $this->error('不能用 observed 候选覆盖 verified 知识。');

            return self::FAILURE;
        }

        $article = KnowledgeArticle::updateOrCreate(['key' => $scenario], [
            'title' => $finding->title,
            'knowledge_type' => 'process',
            'status' => $status,
            'applicability' => $finding->definition."\n纳入：".implode('；', $finding->inclusion_rules)."\n排除：".implode('；', $finding->exclusion_rules),
            'required_facts' => $finding->required_facts,
            'policy_rules' => collect($finding->decision_tree)->map(fn (array $branch): string => '如果 '.$branch['condition'].'，则 '.$branch['outcome'])->all(),
            'decision_tree' => $finding->decision_tree,
            'reply_strategy' => $finding->reply_strategy,
            'reply_templates' => $finding->reply_templates,
            'action_steps' => $finding->required_actions,
            'prohibited_claims' => $finding->prohibited_claims,
            'escalation_rules' => $finding->uncertainties,
            'source_notes' => '研究批次 '.$finding->batch->batch_key.'；Decision Node '.implode(', ', $finding->evidence_decision_node_ids),
            'source_finding_id' => $finding->id,
            'version' => ($existing?->version ?? 0) + 1,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => $status === 'verified' ? now() : null,
        ]);
        $article->bindings()->updateOrCreate(
            ['consumer' => 'ticket', 'intent_key' => '*', 'usage' => 'draft'],
            ['priority' => 100, 'enabled' => true],
        );
        $finding->update([
            'knowledge_status' => $status,
            'review_status' => $status === 'observed' ? 'candidate' : 'reviewed',
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => $status === 'observed' ? null : now(),
        ]);
        $path = $report->writeKnowledgeBase();
        $this->info("知识 {$article->key} 已发布为 {$article->status}，版本 {$article->version}。");
        $this->line('知识库报告：'.$path);

        return self::SUCCESS;
    }
}
