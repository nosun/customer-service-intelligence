<?php

namespace App\Console\Commands;

use App\Services\Gmail\GmailSyncService;
use Illuminate\Console\Command;

class SyncGmail extends Command
{
    protected $signature = 'gmail:sync
        {--mailbox= : Gmail mailbox address}
        {--query=in:anywhere : Gmail search query}
        {--page-limit=10 : Maximum number of result pages}
        {--fixture= : Import a local Gmail Threads JSON fixture instead of calling gws}
        {--gws-config-dir= : Isolated Google Workspace CLI configuration directory}';

    protected $description = 'Synchronize Gmail threads into the local database';

    public function handle(GmailSyncService $sync): int
    {
        $mailbox = strtolower(trim((string) $this->option('mailbox')));
        if (filter_var($mailbox, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('--mailbox must be a valid email address.');

            return self::INVALID;
        }

        $result = $sync->sync(
            mailboxEmail: $mailbox,
            query: (string) $this->option('query'),
            pageLimit: max(1, (int) $this->option('page-limit')),
            fixture: $this->filledOption('fixture'),
            gwsConfigDir: $this->filledOption('gws-config-dir'),
        );

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function filledOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
