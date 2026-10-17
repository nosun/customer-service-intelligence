<?php

namespace App\Console\Commands;

use App\Services\ProductQuestions\ProductQuestionDraftReplaySuite;
use Illuminate\Console\Command;

class EvaluateProductQuestionDraftReplay extends Command
{
    protected $signature = 'product-questions:evaluate-draft-replay
                            {predictions : JSON array keyed by replay case ID}
                            {--suite= : Optional replay suite JSON path}';

    protected $description = 'Evaluate Draft workflow predictions against the fixed product-question replay suite';

    public function handle(ProductQuestionDraftReplaySuite $suite): int
    {
        $predictions = json_decode((string) file_get_contents((string) $this->argument('predictions')), true, flags: JSON_THROW_ON_ERROR);
        $metrics = $suite->evaluate($predictions, $this->option('suite') ?: null);
        $this->line(json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
