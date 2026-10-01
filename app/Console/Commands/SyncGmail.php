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
        {--thread-limit= : Maximum number of threads to import in this run}
        {--resume : Skip threads already stored for this mailbox}
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

        $threadLimit = $this->option('thread-limit');
        if ($threadLimit !== null && $threadLimit !== '' && (int) $threadLimit < 1) {
            $this->error('--thread-limit must be at least 1.');

            return self::INVALID;
        }

        $result = $sync->sync(
            mailboxEmail: $mailbox,
            query: (string) $this->option('query'),
            pageLimit: max(1, (int) $this->option('page-limit')),
            threadLimit: $threadLimit !== null && $threadLimit !== '' ? (int) $threadLimit : null,
            resume: (bool) $this->option('resume'),
            fixture: $this->filledOption('fixture'),
            gwsConfigDir: $this->filledOption('gws-config-dir'),
            progress: function (string $message): void {
                $this->line('['.now()->format('H:i:s')."] {$message}");
                fflush(STDOUT);
            },
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
