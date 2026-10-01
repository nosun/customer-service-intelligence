<?php

namespace App\Console\Commands;

use App\Services\Analysis\HelpDeskAnalyzer;
use Illuminate\Console\Command;

class AnalyzeHelpDesk extends Command
{
    protected $signature = 'helpdesk:analyze';

    protected $description = 'Classify HelpDesk customer messages and extract reply candidates';

    public function handle(HelpDeskAnalyzer $analyzer): int
    {
        $this->line(json_encode($analyzer->analyze(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
