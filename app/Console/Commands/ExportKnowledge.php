<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExportKnowledge extends Command
{
    protected $signature = 'knowledge:export
        {--output= : Destination CSV path}';

    protected $description = 'Export candidate customer-question and agent-reply pairs to CSV';

    public function handle(): int
    {
        $output = (string) ($this->option('output') ?: storage_path('app/private/candidate-reply-pairs.csv'));
        $directory = dirname($output);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create output directory: {$directory}");
        }

        $handle = fopen($output, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open output file: {$output}");
        }

        try {
            fputcsv($handle, [
                'pair_id',
                'category',
                'customer_subject',
                'customer_excerpt',
                'agent_reply_excerpt',
                'matched_rules',
                'review_status',
            ], ',', '"', '');

            $rows = DB::table('candidate_reply_pairs')
                ->join('messages as customer', 'customer.id', '=', 'candidate_reply_pairs.customer_message_id')
                ->leftJoin('message_analyses', 'message_analyses.message_id', '=', 'customer.id')
                ->orderBy('candidate_reply_pairs.id')
                ->get([
                    'candidate_reply_pairs.id as pair_id',
                    'candidate_reply_pairs.category',
                    'customer.subject as customer_subject',
                    'candidate_reply_pairs.customer_excerpt',
                    'candidate_reply_pairs.agent_reply_excerpt',
                    'message_analyses.matched_rules',
                    'candidate_reply_pairs.review_status',
                ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->pair_id,
                    $row->category,
                    $row->customer_subject,
                    $row->customer_excerpt,
                    $row->agent_reply_excerpt,
                    $row->matched_rules ?? '[]',
                    $row->review_status,
                ], ',', '"', '');
            }
        } finally {
            fclose($handle);
        }

        chmod($output, 0600);
        $this->line(json_encode([
            'output' => $output,
            'rows' => $rows->count(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
