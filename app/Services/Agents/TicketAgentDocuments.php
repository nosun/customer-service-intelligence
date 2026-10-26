<?php

namespace App\Services\Agents;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class TicketAgentDocuments
{
    public function inspect(array $document): array
    {
        $url = $this->downloadUrl($document['url']);
        if ($url === null) {
            return ['status' => 'unsupported_source', 'limitations' => ['File host is not supported.']];
        }
        $directory = storage_path('app/private/ticket-agent-documents/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        $path = $directory.'/document';
        try {
            $limit = (int) config('ticket-agent.max_document_bytes');
            $response = Http::connectTimeout(5)->timeout(30)->withOptions([
                'allow_redirects' => false,
                'sink' => $path,
                'progress' => static function ($total, $downloaded) use ($limit): void {
                    if ($total > $limit || $downloaded > $limit) {
                        throw new RuntimeException('document_too_large');
                    }
                },
            ])->get($url);
            if (is_file($path)) {
                chmod($path, 0600);
            }
            if ($response->status() >= 300 && $response->status() < 400) {
                return ['status' => 'access_required', 'limitations' => ['Redirect or login is required; no authenticated session was supplied.']];
            }
            if (! $response->successful()) {
                return ['status' => 'unavailable', 'limitations' => ['Document request failed.']];
            }
            // Http::fake returns its body without writing a sink.
            if (! is_file($path) || filesize($path) === 0) {
                file_put_contents($path, $response->body());
                chmod($path, 0600);
            }
            if (filesize($path) > $limit) {
                throw new RuntimeException('document_too_large');
            }
            $type = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (str_contains($type, 'html')) {
                return ['status' => 'access_required', 'limitations' => ['The link returned an HTML access page, not a readable document.']];
            }
            if (filled($document['sha256'] ?? null) && ! hash_equals($document['sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('document_hash_mismatch');
            }
            if ($type === 'application/pdf') {
                $this->process([(string) config('ticket-agent.pdftoppm_binary'), '-f', '1', '-l', (string) config('ticket-agent.max_document_pages'), '-scale-to', '3000', '-png', $path, $directory.'/page']);
                $pages = glob($directory.'/page-*.png');
                sort($pages, SORT_NATURAL);
            } elseif (in_array($type, ['image/png', 'image/jpeg'], true)) {
                $size = getimagesize($path);
                if (! $size || $size[0] * $size[1] > 40_000_000) {
                    throw new RuntimeException('image_dimensions_exceeded');
                }
                $pages = [$path];
            } else {
                return ['status' => 'unsupported_format', 'limitations' => ['Only PDF, PNG and JPEG are supported.']];
            }
            $evidence = [];
            foreach ($pages as $index => $page) {
                $text = $this->process([(string) config('ticket-agent.tesseract_binary'), $page, 'stdout', '-l', 'eng', '--psm', '11']);
                // Return safe postal fields only. Raw OCR contains names and addresses and stays local.
                preg_match_all('/\b[A-Z]{2}\s?\d{9}\s?[A-Z]{2}\b/', strtoupper($text), $matches);
                $numbers = array_values(array_unique(array_map(fn ($number) => preg_replace('/\s+/', '', $number), $matches[0])));
                $carrier = preg_match('/USPS|UNITED STATES POSTAL SERVICE/i', $text) ? 'USPS' : null;
                $kind = preg_match('/customs declaration|dispatch note/i', $text) ? 'postal_customs_declaration' : 'unclassified_document';
                $evidence[] = ['page' => $index + 1, 'document_type' => $kind, 'carrier' => $carrier, 'reference_numbers' => $numbers];
            }

            return [
                'status' => $evidence === [] ? 'needs_manual_review' : 'read',
                'sha256' => hash_file('sha256', $path), 'pages' => $evidence,
                'limitations' => ['Local OCR may misread characters; reference numbers need carrier verification.',
                    'A postal form does not establish acceptance, delivery, warehouse receipt, inspection or refund execution.',
                    'Only the configured first pages are inspected; non-postal content and visual condition require manual review.'],
            ];
        } catch (Throwable) {
            return ['status' => 'unavailable', 'limitations' => ['Document download, integrity validation or local OCR failed.']];
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function process(array $command): string
    {
        $process = new Process($command, timeout: 40);
        $process->mustRun();

        return $process->getOutput();
    }

    private function downloadUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || ! in_array($parts['host'] ?? '', ['cdn.livechat-files.com', 'drive.google.com'], true)) {
            return null;
        }
        if ($parts['host'] === 'drive.google.com') {
            if (! preg_match('~/file/d/([a-zA-Z0-9_-]+)(?:/|$)~', $parts['path'] ?? '', $match)) {
                return null;
            }

            return 'https://drive.google.com/uc?export=download&id='.$match[1];
        }

        return str_starts_with($parts['path'] ?? '', '/dal/api/file/helpdesk/att/') ? $url : null;
    }
}
