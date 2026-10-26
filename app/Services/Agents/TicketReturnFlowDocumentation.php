<?php

namespace App\Services\Agents;

/** A generated view of the executable return definition, never a second source of policy. */
class TicketReturnFlowDocumentation
{
    public const PATH = 'docs/workflows/return-flow.md';

    public function render(array $definition): string
    {
        $lines = ['# Return 业务流程', '',
            '> 自动生成，请修改 resources/data/ticket-return-playbook-v1.json，再运行 php artisan tickets:return-flow-check --write-doc。', '',
            '流程版本：'.$definition['version'].'；格式版本：'.$definition['format_version'].'；维护角色：'.$definition['owner'].'；范围：本地待人工审核草稿。', '',
            $definition['scope'], '',
            'required_evidence 表示必须评估的证据类别，不代表接口一定可用。证据缺失时按节点异常出口和实际回复要求判断 readiness；可解释政策基础的方案不因缺少最终地址退回条件询问。', '',
            '## 阶段关系', '', '```mermaid', 'flowchart TD'];
        foreach ($definition['stages'] as $key => $stage) {
            $lines[] = '    '.$key.'["'.$stage['label_zh'].' · '.$stage['template_id'].'"]';
        }
        foreach ($definition['edges'] as $edge) {
            $lines[] = '    '.$edge['from'].' -->|'.$edge['event'].'| '.$edge['to'];
        }
        $lines = [...$lines, '```', '', '关系图用于阅读；实际阶段每次由当前证据重新判定。已接受、已寄回等进度优先保留；新冲突阻止 ready，不重启初始问答。', '',
            '## 实际路由顺序', '', $definition['routing_semantics'], '', '| 规则 | 条件 | 阶段 |', '| --- | --- | --- |'];
        foreach ($definition['routing'] as $rule) {
            $lines[] = '| '.$rule['id'].' | `'.json_encode($rule['when'], JSON_UNESCAPED_UNICODE).'` | '.$rule['stage'].' |';
        }
        $lines = [...$lines, '', '## 来源与权限', ''];
        foreach ($definition['sources'] as $source) {
            $lines[] = '- '.$source['id'].'（'.$source['location'].'）：'.$source['authority'];
        }
        $lines = [...$lines, '', '## 工具能力与缺口', '', '| 能力 | 当前状态 | 工具 | 跟进角色与限制 |', '| --- | --- | --- | --- |'];
        foreach ($definition['capabilities'] as $key => $capability) {
            $lines[] = '| '.$key.' | '.$capability['status'].' | '.implode(', ', $capability['tools']).' | '.$capability['owner'].'：'.$capability['gap'].' |';
        }
        $lines = [...$lines, '', '## 判断原则', ''];
        foreach ($definition['interpretation_rules'] as $rule) {
            $lines[] = '- '.$rule;
        }
        foreach ($definition['stages'] as $key => $stage) {
            $lines = [...$lines, '', '## '.$stage['template_id'].' '.$stage['label_zh'], '', '`'.$key.'` — '.$stage['goal'], '', '进入条件：', ''];
            foreach ($stage['entry'] as $entry) {
                $lines[] = '- '.$entry;
            }
            $lines = [...$lines, '', '必须评估：'.implode(', ', $stage['required_evidence']).'。补充证据：'.(implode(', ', $stage['optional_evidence']) ?: '无').'。', '',
                '| 完成层次 | 标准 |', '| --- | --- |', '| 分析完成 | '.$stage['completion']['analysis'].' |',
                '| 草稿完成 | '.$stage['completion']['draft'].' |', '| 业务完成 | '.$stage['completion']['business'].' |', '', '回复必含内容：'.implode(', ', $stage['requirements']).'。', '', '异常出口：', ''];
            foreach ($stage['exceptions'] as $exception) {
                $lines[] = '- '.$exception['when'].' → '.$exception['owner'].'：'.$exception['action'];
            }
            $lines = [...$lines, '', '对应语境案例：'.implode(', ', $stage['evaluation_cases']).'。', '', '```text', $stage['template'], '```'];
        }
        $lines = [...$lines, '', '## 维护与发布', '', $definition['maintenance']['change_policy'], '', $definition['maintenance']['feedback_policy'], '',
            '监测指标：'.implode(', ', $definition['maintenance']['metrics']).'。', '', '发布检查：', ''];
        foreach ($definition['maintenance']['release_requirements'] as $requirement) {
            $lines[] = '- '.$requirement;
        }

        return implode("\n", $lines)."\n";
    }
}
