<?php

namespace App\Console\Commands;

use App\Models\KnowledgeArticle;
use App\Services\Knowledge\TicketFeedbackSynthesizer;
use App\Services\ProductQuestions\KnowledgeArticleVersionPublisher;
use Encore\Admin\Auth\Database\Administrator;
use Illuminate\Console\Command;
use Throwable;

class SynthesizeTicketFeedback extends Command
{
    protected $signature = 'knowledge:synthesize-ticket-feedback {article : Article ID or new topic key} {--title= : Required for a new topic} {--site= : Site key for a new topic; omit for global} {--feedback=* : Explicit reviewed-for-synthesis feedback IDs} {--actor= : Administrator ID}';

    protected $description = 'Create an unapproved knowledge revision from opted-in Ticket feedback';

    public function handle(TicketFeedbackSynthesizer $synthesizer): int
    {
        $actor = (int) $this->option('actor');
        if (! Administrator::whereKey($actor)->exists()) {
            $this->error('A valid administrator is required.');

            return self::INVALID;
        }
        try {
            $topic = (string) $this->argument('article');
            if (ctype_digit($topic)) {
                $article = KnowledgeArticle::findOrFail((int) $topic);
            } else {
                validator(['key' => $topic, 'title' => $this->option('title')], [
                    'key' => ['required', 'regex:/^[a-z][a-z0-9_-]{2,119}$/'], 'title' => ['required', 'string', 'max:255'],
                ])->validate();
                $article = KnowledgeArticle::firstOrCreate(['key' => $topic], [
                    'title' => $this->option('title'), 'site_key' => $this->option('site') ?: null,
                    'knowledge_type' => 'process', 'status' => 'observed', 'automation_tier' => 'manual_only', 'version' => 0,
                    ...array_fill_keys(KnowledgeArticleVersionPublisher::ARRAY_FIELDS, []),
                ]);
                $article->bindings()->firstOrCreate(['consumer' => 'ticket', 'intent_key' => '*', 'usage' => 'draft'], ['enabled' => true, 'priority' => 100]);
            }
            $version = $synthesizer->synthesize($article, $this->option('feedback'), $actor);
            $this->info("Created unapproved candidate v{$version->version}. Review /admin/knowledge-articles/{$version->knowledge_article_id}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Synthesis failed; no published knowledge was changed. Check the application log.');

            return self::FAILURE;
        }
    }
}
