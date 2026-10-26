<?php

namespace App\Console\Commands;

use App\Models\KnowledgeArticle;
use App\Services\Knowledge\WeKnoraPublisher;
use Illuminate\Console\Command;
use Throwable;

class SyncKnowledgeToWeKnora extends Command
{
    protected $signature = 'knowledge:sync-weknora {article : Article ID} {--adopt-id= : Reconcile an uncertain create using an exact matching remote document}';

    protected $description = 'Upload/update the current approved article and verify WeKnora parsing and retrieval';

    public function handle(WeKnoraPublisher $publisher): int
    {
        try {
            $publication = $publisher->sync(KnowledgeArticle::findOrFail($this->argument('article')), $this->option('adopt-id'));
            $this->info("v{$publication->version}: {$publication->status}");

            return $publication->status === 'failed' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Synchronization did not complete. Check publication status and application logs before retrying.');

            return self::FAILURE;
        }
    }
}
