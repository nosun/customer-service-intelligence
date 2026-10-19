<?php

namespace App\Console\Commands;

use App\Models\KnowledgeArticle;
use App\Models\KnowledgeResearchQuestion;
use App\Services\KnowledgeResearch\KnowledgeResearchReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;
use Throwable;

class ConfirmKnowledgeResearch extends Command
{
    protected $signature = 'helpdesk:research:confirm
        {file : Local JSON file containing reviewed business answers}
        {--reviewed-by= : Administrator ID that confirmed the answers}';

    protected $description = 'Resolve research questions and publish reviewed answers as verified knowledge';

    public function handle(KnowledgeResearchReport $report): int
    {
        $path = (string) $this->argument('file');
        $reviewedBy = (int) $this->option('reviewed-by');
        if ($reviewedBy < 1) {
            $this->error('必须提供有效的 --reviewed-by。');

            return self::INVALID;
        }

        try {
            $payload = $this->read($path);
            validator($payload, [
                'answers' => ['required', 'array', 'min:1'],
                'answers.*.question_key' => ['required', 'string', 'max:150'],
                'answers.*.resolution' => ['required', 'string'],
                'answers.*.keep_open' => ['sometimes', 'boolean'],
                'answers.*.existing_article_key' => ['sometimes', 'alpha_dash:ascii', 'max:120'],
                'answers.*.article' => ['sometimes', 'array'],
                'answers.*.article.key' => ['sometimes', 'alpha_dash:ascii', 'max:120'],
                'answers.*.article.title' => ['sometimes', 'string', 'max:255'],
                'answers.*.article.knowledge_type' => ['sometimes', Rule::in(array_keys(KnowledgeArticle::TYPES))],
                'answers.*.article.applicability' => ['sometimes', 'string'],
                'answers.*.article.required_facts' => ['sometimes', 'array'],
                'answers.*.article.policy_rules' => ['sometimes', 'array'],
                'answers.*.article.decision_tree' => ['sometimes', 'array'],
                'answers.*.article.reply_strategy' => ['sometimes', 'array'],
                'answers.*.article.reply_templates' => ['sometimes', 'array'],
                'answers.*.article.action_steps' => ['sometimes', 'array'],
                'answers.*.article.prohibited_claims' => ['sometimes', 'array'],
                'answers.*.article.escalation_rules' => ['sometimes', 'array'],
            ])->validate();
            foreach ($payload['answers'] as $answer) {
                if (isset($answer['article']) === isset($answer['existing_article_key'])) {
                    throw new RuntimeException('每条确认必须且只能提供 article 或 existing_article_key。');
                }
                if (isset($answer['article'])) {
                    foreach (['key', 'title', 'knowledge_type', 'applicability', 'required_facts', 'policy_rules', 'decision_tree', 'reply_strategy', 'reply_templates', 'action_steps', 'prohibited_claims', 'escalation_rules'] as $field) {
                        if (! array_key_exists($field, $answer['article'])) {
                            throw new RuntimeException("article 缺少字段：{$field}");
                        }
                    }
                }
            }
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        try {
            $published = DB::transaction(function () use ($payload, $reviewedBy, $path): array {
                $published = [];
                foreach ($payload['answers'] as $answer) {
                    $questions = KnowledgeResearchQuestion::query()
                        ->where('question_key', $answer['question_key'])
                        ->where('status', 'open');
                    if (! $questions->exists()) {
                        throw new RuntimeException("没有开放问题：{$answer['question_key']}");
                    }
                    $keepOpen = (bool) ($answer['keep_open'] ?? false);
                    $questions->update($keepOpen
                        ? ['resolution' => trim($answer['resolution'])]
                        : [
                            'status' => 'resolved',
                            'resolution' => trim($answer['resolution']),
                            'resolved_by' => $reviewedBy,
                            'resolved_at' => now(),
                        ]);

                    if (isset($answer['existing_article_key'])) {
                        $article = KnowledgeArticle::query()
                            ->where('key', $answer['existing_article_key'])
                            ->where('status', 'verified')
                            ->first();
                        if ($article === null) {
                            throw new RuntimeException("没有 verified 知识：{$answer['existing_article_key']}");
                        }
                        $article->bindings()->updateOrCreate(
                            ['consumer' => 'ticket', 'intent_key' => '*', 'usage' => 'draft'],
                            ['priority' => 100, 'enabled' => true],
                        );
                        $published[] = $article->key;

                        continue;
                    }

                    $articleData = $answer['article'];
                    $existing = KnowledgeArticle::where('key', $articleData['key'])->first();
                    $article = KnowledgeArticle::updateOrCreate(['key' => $articleData['key']], [
                        ...$articleData,
                        'status' => 'verified',
                        'source_notes' => ($keepOpen ? '业务阶段性确认来源：' : '业务确认来源：')
                            .$answer['question_key'].'；文件：'.basename($path),
                        'source_finding_id' => null,
                        'version' => ($existing?->version ?? 0) + 1,
                        'reviewed_by' => $reviewedBy,
                        'reviewed_at' => now(),
                    ]);
                    $article->bindings()->updateOrCreate(
                        ['consumer' => 'ticket', 'intent_key' => '*', 'usage' => 'draft'],
                        ['priority' => 100, 'enabled' => true],
                    );
                    $published[] = $article->key;
                }

                return $published;
            });
        } catch (Throwable $exception) {
            $this->error('确认失败，未写入任何结果：'.$exception->getMessage());

            return self::FAILURE;
        }

        $knowledgeBasePath = $report->writeKnowledgeBase();
        $this->info('已确认 '.count($published).' 条业务结论并发布为 verified 知识。');
        foreach ($published as $key) {
            $this->line('- '.$key);
        }
        $this->line('知识库报告：'.$knowledgeBasePath);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('确认文件不存在或不可读。');
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('确认文件不是有效 JSON。', previous: $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('确认文件根节点必须是 JSON 对象。');
        }

        return $decoded;
    }
}
