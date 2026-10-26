<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Services\Agents\TicketInvestigationInput;
use App\Services\Agents\TicketInvestigationWorker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class InvestigateTicketWithAgent extends Command
{
    protected $signature = 'tickets:agent-investigate
                            {--conversation= : Local Conversation ID, scoped to logistics investigation}
                            {--fixture= : Synthetic snapshot JSON; mutually exclusive with --conversation}
                            {--provider= : minimax or omniroute; defaults to the existing Draft AI provider}
                            {--prepare-only : Save the input without calling a model}';

    protected $description = 'Run an experimental read-only logistics Agent and save a local investigation report';

    public function handle(TicketInvestigationInput $inputs, TicketInvestigationWorker $worker): int
    {
        try {
            if (filled($this->option('conversation')) === filled($this->option('fixture'))) {
                throw new RuntimeException('Choose exactly one of --conversation or --fixture.');
            }
            $conversation = null;
            if (filled($this->option('conversation'))) {
                $conversation = Conversation::findOrFail($this->option('conversation'));
                $input = $inputs->capture($conversation);
            } else {
                $path = (string) $this->option('fixture');
                $path = str_starts_with($path, '/') ? $path : base_path($path);
                $input = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
                if (($input['synthetic'] ?? false) !== true) {
                    throw new RuntimeException('Fixture mode only accepts explicitly synthetic snapshots.');
                }
                $input['captured_at'] = now()->toIso8601String();
                foreach ($input['facts'] as &$fact) {
                    $fact['captured_at'] = $input['captured_at'];
                    $fact['fresh_until'] = now()->addMinutes(10)->toIso8601String();
                }
                unset($fact);
                $input['input_hash'] = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            }
            $directory = storage_path('app/private/agent-investigations');
            File::ensureDirectoryExists($directory, 0700);
            $base = $directory.'/'.now()->format('Ymd-His').'-'.Str::uuid();
            $this->save($base.'-input.json', $input);
            $this->line('input='.$base.'-input.json');
            if ($this->option('prepare-only')) {
                return self::SUCCESS;
            }
            if ($input['safety_flags'] !== []) {
                $this->warn('Investigation blocked before model invocation: order identity or lookup is unverified.');

                return self::FAILURE;
            }
            $report = $worker->run($input, $this->option('provider') ?: (string) config('services.draft_ai.provider'));
            if ($conversation !== null) {
                $inputs->assertCurrent($conversation, $input['source_input_hash']);
                if (count($inputs->shareableFacts($input['facts'])) !== count($input['facts'])) {
                    throw new RuntimeException('Investigation facts expired before the run completed.');
                }
            }
            $this->save($base.'-report.json', $report);
            $this->info('report='.$base.'-report.json status='.$report['status']);

            return $report['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            // Local validation messages are safe; external/provider exceptions aren't printed.
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Unable to prepare or run the investigation.');

            return self::FAILURE;
        }
    }

    private function save(string $path, array $data): void
    {
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('Unable to create a private investigation artifact.');
        }
        try {
            chmod($path, 0600);
            fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            fclose($handle);
        }
    }
}
