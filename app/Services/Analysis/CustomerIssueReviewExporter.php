<?php

namespace App\Services\Analysis;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class CustomerIssueReviewExporter
{
    /** @return array{csv: string, summary: string, rows: int} */
    public function export(string $csvPath, string $summaryPath): array
    {
        $rows = DB::table('messages')
            ->join('message_analyses', 'message_analyses.message_id', '=', 'messages.id')
            ->where('messages.direction', 'inbound')
            ->orderBy('messages.sent_at')
            ->orderBy('messages.id')
            ->get([
                'messages.id',
                'messages.sent_at',
                'messages.subject',
                'messages.body_text',
                'messages.body_html',
                'message_analyses.category',
                'message_analyses.problem_type',
                'message_analyses.urgency',
                'message_analyses.is_noise',
                'message_analyses.noise_type',
                'message_analyses.order_candidates',
                'message_analyses.matched_rules',
            ]);

        $this->ensureDirectory(dirname($csvPath));
        $this->ensureDirectory(dirname($summaryPath));
        $handle = fopen($csvPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open output file: {$csvPath}");
        }

        try {
            fputcsv($handle, [
                'message_id',
                'received_at',
                'category',
                'problem_type',
                'urgency',
                'is_noise',
                'noise_type',
                'order_numbers',
                'subject',
                'excerpt',
                'matched_rules',
                'review_decision',
                'corrected_category',
                'corrected_problem_type',
                'review_notes',
            ], ',', '"', '');

            foreach ($rows as $row) {
                $orders = $this->decodeList($row->order_candidates);
                fputcsv($handle, [
                    $row->id,
                    $row->sent_at,
                    $row->category,
                    $row->problem_type,
                    $row->urgency,
                    $row->is_noise ? 'yes' : 'no',
                    $row->noise_type,
                    implode('|', $orders),
                    $row->subject,
                    $this->excerpt((string) ($row->body_text ?: $row->body_html)),
                    implode('|', $this->decodeList($row->matched_rules)),
                    'pending',
                    '',
                    '',
                    '',
                ], ',', '"', '');
            }
        } finally {
            fclose($handle);
        }

        $summary = $this->summary($rows->all());
        file_put_contents(
            $summaryPath,
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
            LOCK_EX,
        );
        chmod($csvPath, 0600);
        chmod($summaryPath, 0600);

        return ['csv' => $csvPath, 'summary' => $summaryPath, 'rows' => $rows->count()];
    }

    /** @param list<object> $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows): array
    {
        $categories = [];
        $problemTypes = [];
        $urgency = [];
        $noiseTypes = [];
        $noiseCount = 0;
        $ordersFound = 0;
        $missingText = 0;
        $dates = [];

        foreach ($rows as $row) {
            $isNoise = (bool) $row->is_noise;
            $noiseCount += $isNoise ? 1 : 0;
            $missingText += trim((string) $row->body_text) === '' ? 1 : 0;
            $ordersFound += $this->decodeList($row->order_candidates) !== [] ? 1 : 0;
            if ($row->sent_at !== null) {
                $dates[] = (string) $row->sent_at;
            }

            if ($isNoise) {
                $noiseType = $row->noise_type ?: 'unknown_noise';
                $noiseTypes[$noiseType] = ($noiseTypes[$noiseType] ?? 0) + 1;

                continue;
            }

            $categories[$row->category] = ($categories[$row->category] ?? 0) + 1;
            $problemType = $row->problem_type ?: 'unknown';
            $problemTypes[$problemType] = ($problemTypes[$problemType] ?? 0) + 1;
            $urgency[$row->urgency] = ($urgency[$row->urgency] ?? 0) + 1;
        }

        arsort($categories);
        arsort($problemTypes);
        arsort($urgency);
        arsort($noiseTypes);
        $customerMessages = count($rows) - $noiseCount;

        return [
            'generated_at' => now()->toIso8601String(),
            'scope' => 'Inbound Gmail messages currently stored in the local database',
            'earliest_message_at' => $dates === [] ? null : min($dates),
            'latest_message_at' => $dates === [] ? null : max($dates),
            'total_inbound_messages' => count($rows),
            'customer_messages' => $customerMessages,
            'excluded_noise_messages' => $noiseCount,
            'noise_rate' => $this->rate($noiseCount, count($rows)),
            'categories' => $categories,
            'problem_types' => $problemTypes,
            'urgency' => $urgency,
            'noise_types' => $noiseTypes,
            'messages_with_order_number' => $ordersFound,
            'order_number_coverage' => $this->rate($ordersFound, $customerMessages),
            'messages_missing_plain_text' => $missingText,
            'plain_text_missing_rate' => $this->rate($missingText, count($rows)),
            'reply_pairs' => DB::table('candidate_reply_pairs')->count(),
        ];
    }

    /** @return list<string> */
    private function decodeList(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private function excerpt(string $body): string
    {
        $body = html_entity_decode(strip_tags($body));
        $body = preg_split('/\ROn .{0,300}wrote:\s*/is', $body, 2)[0] ?? $body;
        $body = preg_split('/\R-{2,}\s*Forwarded (?:message|Message)\s*-{2,}/', $body, 2)[0] ?? $body;
        $body = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[EMAIL]', $body) ?? $body;
        $body = preg_replace('/\b[a-z]{2,5}\d{8}-\d{3,}\b/i', '[ORDER]', $body) ?? $body;
        $body = preg_replace('/(?:\+?\d[\d .()\/-]{7,}\d)/', '[PHONE]', $body) ?? $body;
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);

        return mb_substr($body, 0, 500);
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create output directory: {$directory}");
        }
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : round($numerator / $denominator, 4);
    }
}
