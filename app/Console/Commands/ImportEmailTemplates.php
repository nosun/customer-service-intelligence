<?php

namespace App\Console\Commands;

use App\Models\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportEmailTemplates extends Command
{
    private const REQUIRED_HEADERS = [
        'eid',
        'name',
        'description',
        'status',
        'title',
        'type',
        'content',
    ];

    protected $signature = 'knowledge:import-email-templates
        {path=docs/email.csv : CSV file path, relative to the project root or absolute}
        {--source=email_csv : Stable source key used for idempotent updates}';

    protected $description = 'Import email templates from CSV into the local knowledge base';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $path = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        $source = trim((string) $this->option('source'));

        if ($source === '') {
            $this->error('The --source value cannot be empty.');

            return self::FAILURE;
        }

        try {
            $result = $this->import($path, $source);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode([
            'path' => $path,
            'source' => $source,
            ...$result,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /** @return array{rows: int, created: int, updated: int, unchanged: int} */
    private function import(string $path, string $source): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open email template CSV: {$path}");
        }

        try {
            $headers = fgetcsv($handle, null, ',', '"', '');
            if ($headers === false) {
                throw new RuntimeException('The email template CSV is empty.');
            }

            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]) ?? $headers[0];
            if (count($headers) !== count(array_unique($headers))) {
                throw new RuntimeException('The email template CSV contains duplicate headers.');
            }

            $missingHeaders = array_values(array_diff(self::REQUIRED_HEADERS, $headers));
            if ($missingHeaders !== []) {
                throw new RuntimeException('Missing required CSV headers: '.implode(', ', $missingHeaders));
            }

            $result = ['rows' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0];

            DB::transaction(function () use ($handle, $headers, $source, &$result): void {
                $line = 1;
                while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                    $line++;
                    if ($values === [null] || $values === []) {
                        continue;
                    }
                    if (count($values) !== count($headers)) {
                        throw new RuntimeException("CSV row {$line} has ".count($values).' columns; expected '.count($headers).'.');
                    }

                    /** @var array<string, string|null> $row */
                    $row = array_combine($headers, $values);
                    $externalKey = trim((string) $row['eid']);
                    $name = trim((string) $row['name']);
                    $body = (string) $row['content'];
                    if ($externalKey === '' || $name === '' || trim($body) === '') {
                        throw new RuntimeException("CSV row {$line} requires non-empty eid, name, and content values.");
                    }

                    $template = EmailTemplate::firstOrNew([
                        'source' => $source,
                        'external_key' => $externalKey,
                    ]);
                    $template->fill([
                        'name' => $name,
                        'template_type' => $this->nullable($row['type']),
                        'trigger_description' => $this->nullable($row['description']),
                        'subject' => $this->nullable($row['title']),
                        'body' => $body,
                        'active' => $this->boolean($row['status'], $line),
                        'raw_row' => $row,
                    ]);

                    $result['rows']++;
                    if (! $template->exists) {
                        $template->save();
                        $result['created']++;
                    } elseif ($template->isDirty()) {
                        $template->save();
                        $result['updated']++;
                    } else {
                        $result['unchanged']++;
                    }
                }
            });

            return $result;
        } finally {
            fclose($handle);
        }
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function boolean(?string $value, int $line): bool
    {
        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes', 'active' => true,
            '0', 'false', 'no', 'inactive' => false,
            default => throw new RuntimeException("CSV row {$line} has an invalid status value."),
        };
    }
}
