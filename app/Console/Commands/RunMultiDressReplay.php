<?php

namespace App\Console\Commands;

use App\Services\CustomerContacts\GmailFirstRoundTransport;
use App\Services\CustomerContacts\MultiDressInput;
use App\Services\CustomerContacts\MultiDressReplayService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RunMultiDressReplay extends Command
{
    protected $signature = 'contacts:multi-dress-replay {--fixture=} {--output=}';

    protected $description = 'Replay multi-dress services in a disposable in-memory database with fake communication and execution';

    public function handle(): int
    {
        $path = $this->option('fixture') ?: resource_path('data/seawave-multi-dress-replay-ebd1295-v1.json');
        $raw = file_get_contents($path);
        $fixture = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $oldConnection = DB::getDefaultConnection();
        $oldConfig = config('customer_contacts');
        $oldDisk = config('filesystems.disks.local');
        $oldFilesystem = Storage::disk('local');
        $oldNow = CarbonImmutable::getTestNow();
        $sandbox = storage_path('app/private/customer-contact-replays/'.Str::uuid());
        config(['database.connections.contact_replay' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'filesystems.disks.local' => ['driver' => 'local', 'root' => $sandbox, 'throw' => true]]);
        DB::setDefaultConnection('contact_replay');
        Storage::forgetDisk('local');
        try {
            Artisan::call('migrate', ['--database' => 'contact_replay', '--force' => true]);
            $result = app(MultiDressReplayService::class)->run($fixture);
            $result['fixture_sha256'] = hash('sha256', $raw);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($this->option('output')) {
                file_put_contents($this->option('output'), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                chmod($this->option('output'), 0600);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Replay failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            DB::disconnect('contact_replay');
            DB::purge('contact_replay');
            DB::setDefaultConnection($oldConnection);
            Storage::build(['driver' => 'local', 'root' => $sandbox])->deleteDirectory('');
            config(['customer_contacts' => $oldConfig, 'filesystems.disks.local' => $oldDisk]);
            Storage::forgetDisk('local');
            Storage::set('local', $oldFilesystem);
            app()->forgetInstance(MultiDressInput::class);
            app()->forgetInstance(GmailFirstRoundTransport::class);
            CarbonImmutable::setTestNow($oldNow);
        }
    }
}
