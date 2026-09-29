<?php

namespace App\Console\Commands;

use App\Services\Analysis\RuleAnalyzer;
use Illuminate\Console\Command;

class AnalyzeGmail extends Command
{
    protected $signature = 'gmail:analyze';

    protected $description = 'Classify inbound messages and extract candidate reply pairs';

    public function handle(RuleAnalyzer $analyzer): int
    {
        $this->line(json_encode(
            $analyzer->analyze(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        return self::SUCCESS;
    }
}
