<?php

namespace App\Jobs;

use App\Models\KnowledgeArticle;
use App\Services\Knowledge\WeKnoraPublisher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncKnowledgeToWeKnora implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public int $uniqueFor = 60;

    public function __construct(public int $articleId) {}

    public function uniqueId(): string
    {
        return (string) $this->articleId;
    }

    public function handle(WeKnoraPublisher $publisher): void
    {
        $publisher->sync(KnowledgeArticle::findOrFail($this->articleId));
    }
}
