<?php

namespace App\Services\KnowledgeResearch;

use App\Models\DecisionNode;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeResearchBatch;
use App\Models\KnowledgeResearchFinding;
use App\Models\KnowledgeResearchQuestion;
use Illuminate\Support\Facades\Storage;

class KnowledgeResearchReport
{
    public function write(KnowledgeResearchBatch $batch): string
    {
        $batch->load(['findings', 'questions', 'items.conversation:id,external_short_id,external_id']);
        $openQuestions = $batch->questions->where('status', 'open');
        $evidenceNodes = DecisionNode::query()
            ->whereIn('id', $openQuestions->pluck('evidence_decision_node_ids')->flatten()->unique())
            ->with([
                'decisionCase.conversation:id,external_short_id,external_id',
                'evidence.event:id,author_type,body_text,occurred_at',
            ])
            ->get()
            ->keyBy('id');
        $lines = [
            '# 客服知识研究批次 '.$batch->batch_key,
            '',
            '- 研究窗口：'.$batch->window_start->toDateString().' 至 '.$batch->window_end->toDateString(),
            '- Ticket：'.$batch->completed_count.' 完成 / '.$batch->selected_count.' 选中 / '.$batch->failed_count.' 失败',
            '- Decision Node：'.$batch->node_count,
            '- 场景候选：'.$batch->findings->count().'（新增 '.$batch->new_scenario_count.'）',
            '- 待业务确认：'.$openQuestions->count(),
            '- 知识状态：`observed`，尚未成为正式政策',
            '',
            '## 本批 Ticket',
            '',
        ];
        foreach ($batch->items as $item) {
            $ticket = $item->conversation?->external_short_id ?: $item->conversation?->external_id;
            $lines[] = '- '.$ticket.'：'.$item->status;
        }

        foreach ($batch->findings as $finding) {
            $lines = [...$lines,
                '',
                '## '.$finding->title.' (`'.$finding->scenario_key.'`)',
                '',
                $finding->definition,
                '',
                '**纳入条件**',
                '',
                ...$this->bullets($finding->inclusion_rules),
                '',
                '**排除条件**',
                '',
                ...$this->bullets($finding->exclusion_rules),
                '',
                '**所需事实**',
                '',
                ...$this->bullets($finding->required_facts),
                '',
                '**决策树候选**',
                '',
            ];
            foreach ($finding->decision_tree as $branch) {
                $lines[] = '- 如果 '.$branch['condition'].' → '.$branch['outcome'].'（节点 '.implode(', ', $branch['evidence_decision_node_ids']).'）';
            }
            $lines = [...$lines,
                '',
                '**回复策略**',
                '',
            ];
            foreach ($finding->reply_strategy as $strategy) {
                $lines[] = '- '.$strategy['stage'].'：'.$strategy['goal'].'；'.implode('；', $strategy['content_requirements']);
            }
            $lines = [...$lines,
                '',
                '**后续操作**',
                '',
                ...$this->bullets($finding->required_actions),
                '',
                '**禁止承诺**',
                '',
                ...$this->bullets($finding->prohibited_claims),
                '',
                '**不确定性**',
                '',
                ...$this->bullets($finding->uncertainties),
                '',
                '证据 Decision Node：'.implode(', ', $finding->evidence_decision_node_ids),
            ];
        }

        $lines = [...$lines, '', '## 待业务确认', ''];
        if ($openQuestions->isEmpty()) {
            $lines[] = '- 本批没有新增问题。';
        }
        foreach ($openQuestions as $question) {
            $lines[] = '### '.$question->question;
            $lines[] = '';
            $lines[] = '- 影响：'.$question->why_it_matters;
            $lines[] = '- 可选结论：'.implode('；', $question->options);
            $lines[] = '- 临时安全处理：'.$question->provisional_safe_handling;
            if (filled($question->resolution)) {
                $lines[] = '- 已确认部分：'.$question->resolution;
            }
            $lines[] = '- 影响场景：'.implode(', ', $question->affected_scenario_keys);
            $lines[] = '- 证据 Decision Node：'.implode(', ', $question->evidence_decision_node_ids);
            $lines[] = '';
            $lines[] = '**原始沟通上下文**';
            $lines[] = '';
            foreach ($question->evidence_decision_node_ids as $nodeId) {
                $node = $evidenceNodes->get($nodeId);
                if ($node === null) {
                    continue;
                }
                $ticket = $node->decisionCase?->conversation?->external_short_id
                    ?: $node->decisionCase?->conversation?->external_id
                    ?: '未知';
                $events = $node->evidence
                    ->filter(fn ($evidence) => $evidence->event !== null)
                    ->unique('conversation_event_id')
                    ->sortBy(fn ($evidence) => [$evidence->event->occurred_at?->timestamp ?? 0, $evidence->event->id]);
                $lines[] = '**Ticket '.$ticket.'｜Decision Node '.$nodeId.'**';
                $lines[] = '';
                foreach (['client' => '客户原文', 'agent' => '当时客服回复'] as $authorType => $label) {
                    $messages = $events->filter(fn ($evidence) => $evidence->event->author_type === $authorType);
                    if ($messages->isEmpty()) {
                        $lines[] = '- '.$label.'：无对应公开消息证据';

                        continue;
                    }
                    foreach ($messages as $evidence) {
                        $lines[] = '- '.$label.'：';
                        $lines[] = '';
                        $lines = [...$lines, ...$this->quote($evidence->event->body_text)];
                        $lines[] = '';
                    }
                }
                $lines[] = '';
            }
        }

        $path = 'knowledge-research/'.$batch->batch_key.'.md';
        Storage::disk('local')->put($path, implode("\n", $lines)."\n");

        return Storage::disk('local')->path($path);
    }

    public function writeKnowledgeBase(): string
    {
        $verifiedArticles = KnowledgeArticle::query()->where('status', 'verified')->orderBy('key')->get();
        $findings = KnowledgeResearchFinding::query()->latest('id')->get()->unique('scenario_key')->values();
        $occurrences = KnowledgeResearchFinding::query()
            ->selectRaw('scenario_key, count(*) aggregate')
            ->groupBy('scenario_key')
            ->pluck('aggregate', 'scenario_key');
        $questions = KnowledgeResearchQuestion::query()
            ->where('status', 'open')
            ->latest('id')
            ->get()
            ->unique('question_key')
            ->values();
        $batches = KnowledgeResearchBatch::query()
            ->whereIn('status', ['completed', 'completed_with_errors'])
            ->latest('id')
            ->limit(10)
            ->get();
        $lines = [
            '# 客服知识库与决策树候选',
            '',
            '- 生成时间：'.now()->toDateTimeString(),
            '- 场景数：'.$findings->count(),
            '- 待业务确认：'.$questions->count(),
            '- 默认状态：历史案例归纳的 `observed` 候选；未经业务确认不得视为正式政策',
            '',
            '## 分类发现进度',
            '',
            '| 批次 | Ticket | Episode | 场景 | 新场景 |',
            '| --- | ---: | ---: | ---: | ---: |',
        ];
        foreach ($batches as $batch) {
            $lines[] = '| '.$batch->batch_key.' | '.$batch->completed_count.' | '.$batch->node_count.' | '.$batch->findings()->count().' | '.$batch->new_scenario_count.' |';
        }

        $lines = [...$lines, '', '## 已验证知识', ''];
        if ($verifiedArticles->isEmpty()) {
            $lines[] = '- 当前没有 `verified` 知识。';
        }
        foreach ($verifiedArticles as $article) {
            $lines = [...$lines,
                '### '.$article->title.' (`'.$article->key.'`)',
                '',
                '- 版本：'.$article->version,
                '- 适用范围：'.$article->applicability,
                '',
                '**决策树**',
                '',
            ];
            foreach ($article->decision_tree as $branch) {
                $lines[] = '- 如果 '.$branch['condition'].' → '.$branch['outcome'];
            }
            $lines = [...$lines,
                '',
                '**操作步骤**',
                '',
                ...$this->bullets($article->action_steps),
                '',
                '**禁止承诺**',
                '',
                ...$this->bullets($article->prohibited_claims),
                '',
            ];
        }

        $lines = [...$lines, '## 观察候选', ''];

        foreach ($findings as $finding) {
            $lines = [...$lines,
                '',
                '## '.$finding->title.' (`'.$finding->scenario_key.'`)',
                '',
                '- 分类：'.$finding->category.' / '.($finding->problem_type ?: '未定'),
                '- 跨批次出现：'.($occurrences[$finding->scenario_key] ?? 1).' 次',
                '- 状态：'.$finding->knowledge_status.' / '.$finding->review_status,
                '',
                $finding->definition,
                '',
                '**所需事实**',
                '',
                ...$this->bullets($finding->required_facts),
                '',
                '**决策树**',
                '',
            ];
            foreach ($finding->decision_tree as $branch) {
                $lines[] = '- 如果 '.$branch['condition'].' → '.$branch['outcome'];
            }
            $lines = [...$lines,
                '',
                '**回复策略**',
                '',
            ];
            foreach ($finding->reply_strategy as $strategy) {
                $lines[] = '- '.$strategy['stage'].'：'.$strategy['goal'].'；'.implode('；', $strategy['content_requirements']);
            }
            $lines = [...$lines,
                '',
                '**后续操作**',
                '',
                ...$this->bullets($finding->required_actions),
                '',
                '**禁止承诺**',
                '',
                ...$this->bullets($finding->prohibited_claims),
                '',
                '**仍需确认**',
                '',
                ...$this->bullets($finding->uncertainties),
            ];
        }

        $lines = [...$lines, '', '## 待业务确认问题', ''];
        if ($questions->isEmpty()) {
            $lines[] = '- 当前没有开放问题。';
        }
        foreach ($questions as $question) {
            $confirmedPart = filled($question->resolution) ? '；已确认部分：'.$question->resolution : '';
            $lines[] = '- **'.$question->question.'**：'.$question->why_it_matters.'；临时处理：'.$question->provisional_safe_handling.$confirmedPart;
        }

        $path = 'knowledge-research/knowledge-base.md';
        Storage::disk('local')->put($path, implode("\n", $lines)."\n");

        return Storage::disk('local')->path($path);
    }

    /** @param list<string> $items
     * @return list<string>
     */
    private function bullets(array $items): array
    {
        return $items === [] ? ['- 无'] : array_map(fn (string $item): string => '- '.$item, $items);
    }

    /** @return list<string> */
    private function quote(?string $value): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return ['> （空消息）'];
        }
        if (mb_strlen($value) > 6000) {
            $value = mb_substr($value, 0, 6000)."\n[内容过长，已截断]";
        }

        return array_map(
            fn (string $line): string => '> '.$line,
            preg_split('/\R/u', $value) ?: [$value],
        );
    }
}
