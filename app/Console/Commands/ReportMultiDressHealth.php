<?php

namespace App\Console\Commands;

use App\Services\CustomerContacts\MultiDressHealthService;
use Illuminate\Console\Command;

class ReportMultiDressHealth extends Command
{
    protected $signature = 'contacts:multi-dress-health {--fail-on-unhealthy}';

    protected $description = 'Report first-round send, note and timeout health without customer message data';

    public function handle(MultiDressHealthService $service): int
    {
        $report = $service->report(now()->toImmutable());
        $this->line(json_encode($report, JSON_UNESCAPED_UNICODE));

        return $this->option('fail-on-unhealthy') && ($report['unknown_sends'] + $report['note_failures'] + $report['overdue']) > 0
            ? self::FAILURE : self::SUCCESS;
    }
}
