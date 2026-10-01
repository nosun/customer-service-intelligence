<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Services\DecisionCases\DecisionCaseBuilder;
use Illuminate\Console\Command;

class BuildDecisionCases extends Command
{
    protected $signature = 'helpdesk:cases:build
        {--problem-type= : Only process Tickets with this primary problem type}
        {--ticket-id= : Only process one external HelpDesk Ticket ID}
        {--limit=0 : Maximum number of Tickets; zero means no limit}
        {--force : Re-run unchanged unreviewed Tickets}';

    protected $description = 'Use MiniMax to convert HelpDesk Tickets into auditable decision cases';

    public function handle(DecisionCaseBuilder $builder): int
    {
        if (! $builder->isConfigured()) {
            $this->error('MiniMax is not configured. Set MINIMAX_API_KEY, MINIMAX_API_URL and MINIMAX_MODEL.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        if ($limit < 0) {
            $this->error('The limit must be zero or a positive integer.');

            return self::INVALID;
        }

        $query = Conversation::query()
            ->whereIn('silo', ['tickets', 'archive'])
            ->whereHas('analysis');
        $problemType = trim((string) $this->option('problem-type'));
        if ($problemType !== '') {
            $query->whereHas('analysis', fn ($analysis) => $analysis->where('primary_problem_type', $problemType));
        }
        $ticketId = trim((string) $this->option('ticket-id'));
        if ($ticketId !== '') {
            $query->where('external_id', $ticketId);
        }

        $matching = (clone $query)->count();
        $selectedTotal = $limit > 0 ? min($matching, $limit) : $matching;
        if ($limit > 0) {
            $query->limit($limit);
        }

        $counts = [
            'selected' => 0,
            'completed' => 0,
            'skipped_unchanged' => 0,
            'skipped_reviewed' => 0,
            'failed' => 0,
            'nodes' => 0,
            'api_calls' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'api_duration_ms' => 0,
        ];
        $startedAt = hrtime(true);
        $this->info("准备处理 {$selectedTotal} 张 Ticket（匹配 {$matching} 张）");
        $this->line('模型: '.$builder->model());
        if ($problemType !== '') {
            $this->line('问题类型: '.$problemType);
        }
        if ($ticketId !== '') {
            $this->line('Ticket: '.$ticketId);
        }
        if ($selectedTotal === 0) {
            $this->warn('没有符合条件的 Ticket。');
        }

        foreach ($query->orderBy('id')->cursor() as $conversation) {
            $counts['selected']++;
            $result = $builder->build($conversation, (bool) $this->option('force'));
            $counts[$result['status']]++;
            $counts['nodes'] += $result['nodes'];
            $counts['api_calls'] += $result['api_called'] ? 1 : 0;
            $counts['prompt_tokens'] += $result['prompt_tokens'];
            $counts['completion_tokens'] += $result['completion_tokens'];
            $counts['total_tokens'] += $result['total_tokens'];
            $counts['api_duration_ms'] += $result['duration_ms'];
            $this->line($this->progressLine($counts['selected'], $selectedTotal, $conversation, $result));
        }

        $counts['elapsed_ms'] = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $counts['average_api_ms'] = $counts['api_calls'] > 0
            ? (int) round($counts['api_duration_ms'] / $counts['api_calls'])
            : 0;
        $this->newLine();
        $this->info('处理汇总');
        $this->line(json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{status: string, nodes: int, error: ?string, api_called: bool, duration_ms: int, prompt_tokens: int, completion_tokens: int, total_tokens: int}  $result
     */
    private function progressLine(int $current, int $total, Conversation $conversation, array $result): string
    {
        $percent = $total > 0 ? min(100, (int) floor($current / $total * 100)) : 100;
        $status = match ($result['status']) {
            'completed' => '完成',
            'skipped_unchanged' => '跳过：内容未变化',
            'skipped_reviewed' => '跳过：已经人工审核',
            'failed' => '失败',
            default => $result['status'],
        };
        $parts = [
            sprintf('[%d/%d %3d%%]', $current, $total, $percent),
            "Ticket {$conversation->external_id}",
            $status,
            "节点 {$result['nodes']}",
        ];
        if ($result['api_called']) {
            $parts[] = number_format($result['duration_ms'] / 1000, 1).'s';
            $parts[] = sprintf(
                'tokens %s（输入 %s / 输出 %s）',
                number_format($result['total_tokens']),
                number_format($result['prompt_tokens']),
                number_format($result['completion_tokens']),
            );
        }
        if ($result['error'] !== null) {
            $parts[] = '错误: '.mb_strimwidth($result['error'], 0, 240, '…');
        }

        return implode(' | ', $parts);
    }
}
