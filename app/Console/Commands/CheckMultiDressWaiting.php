<?php

namespace App\Console\Commands;

use App\Models\CustomerContactRound;
use App\Services\CustomerContacts\MultiDressWaitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CheckMultiDressWaiting extends Command
{
    protected $signature = 'contacts:multi-dress-check-waiting {--limit=20}';

    protected $description = 'Check replies and 48-hour multi-dress waiting; never execute a bare release';

    public function handle(MultiDressWaitService $service): int
    {
        if (! Schema::hasTable('customer_contact_rounds') || ! in_array(config('customer_contacts.mode'), ['shadow', 'live'], true)) {
            return self::SUCCESS;
        }
        foreach (CustomerContactRound::where('communication_state', 'sent')->whereIn('waiting_state', ['waiting', 'due'])
            ->orderBy('wait_until')->limit(min(100, max(1, (int) $this->option('limit'))))->get() as $round) {
            $this->line(json_encode(['round_id' => $round->id, ...$service->inspect($round->id, now()->toImmutable())]));
        }

        return self::SUCCESS;
    }
}
