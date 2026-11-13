<?php

namespace App\Console\Commands;

use App\Services\CustomerContacts\CommunicationReader;
use App\Services\CustomerContacts\ConsoleContactReader;
use App\Services\CustomerContacts\MultiDressPolicy;
use App\Services\CustomerContacts\MultiDressRoundManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ScanMultiDressContacts extends Command
{
    protected $signature = 'contacts:multi-dress-scan {--console=seawave} {--order=} {--mode=shadow} {--draft}';

    protected $description = 'Read existing Seawave multi-dress contacts and build first-round candidates';

    public function handle(ConsoleContactReader $console, CommunicationReader $communication, MultiDressRoundManager $manager, MultiDressPolicy $policy): int
    {
        if ($this->option('console') !== 'seawave' || $this->option('mode') !== 'shadow') {
            $this->error('This scanner only reads Seawave in shadow mode.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('customer_contact_rounds') || config('customer_contacts.mode') === 'off') {
            $this->line('not_installed_or_disabled');

            return self::SUCCESS;
        }
        $orders = [];
        if ($this->option('order')) {
            $orders[] = (string) $this->option('order');
        } else {
            $complete = false;
            for ($page = 1; $page <= config('customer_contacts.page_limit'); $page++) {
                $batch = $console->candidates($page, 100);
                foreach ($batch['items'] as $item) {
                    if (($item['source'] ?? '') === 'multi_dress' && filled(data_get($item, 'order.number'))) {
                        $orders[] = $item['order']['number'];
                    }
                }
                if ($batch['complete']) {
                    $complete = true;
                    break;
                }
            }
            if (! $complete) {
                $this->error('incomplete_scan');

                return self::FAILURE;
            }
        }
        $failed = 0;
        foreach (array_unique($orders) as $number) {
            try {
                $s = $console->snapshot($number);
                $preflight = $policy->assess($s, []);
                $c = $preflight['decision'] === 'skip' ? [] : $communication->capture([...$s['identity'], 'email' => $s['customer']['email']], now()->toImmutable()->subDays(30));
                $round = $manager->ingest($s, $c);
                if ($this->option('draft') && data_get($round->assessment, 'decision') === 'prepare_first_email') {
                    $manager->requestWork($round);
                }
                $this->line(json_encode(['order_number' => $number, 'round_id' => $round->id, 'assessment' => $round->assessment], JSON_UNESCAPED_UNICODE));
            } catch (Throwable) {
                $failed++;
                $this->error(json_encode(['order_number' => $number, 'error' => 'scan_failed_review_private_logs_or_permissions']));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
