<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailTemplateImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_and_updates_email_templates_idempotently(): void
    {
        $path = storage_path('framework/testing/email-templates.csv');
        $this->writeCsv($path, [
            ['1', 'orderStatus', 'Order status response', '1', '0', 'Order {[order.number]} update', 'template', 'emails.order-status', '0', 'Your order is being prepared.', '2026-01-01 00:00:00', '2026-01-02 00:00:00'],
        ]);

        $this->artisan('knowledge:import-email-templates', ['path' => $path])
            ->expectsOutputToContain('"created": 1')
            ->assertSuccessful();

        $template = EmailTemplate::sole();
        $this->assertSame('1', $template->external_key);
        $this->assertSame('email_csv', $template->source);
        $this->assertSame('orderStatus', $template->name);
        $this->assertSame('template', $template->template_type);
        $this->assertSame('Order status response', $template->trigger_description);
        $this->assertSame('Order {[order.number]} update', $template->subject);
        $this->assertSame('Your order is being prepared.', $template->body);
        $this->assertTrue($template->active);
        $this->assertSame('emails.order-status', $template->raw_row['view_name']);
        $this->assertSame('0', $template->raw_row['is_auto']);

        $this->writeCsv($path, [
            ['1', 'orderStatus', 'Updated description', '0', '0', 'Updated subject', 'template', 'emails.order-status', '0', 'Updated body.', '2026-01-01 00:00:00', '2026-02-01 00:00:00'],
        ]);

        $this->artisan('knowledge:import-email-templates', ['path' => $path])
            ->expectsOutputToContain('"created": 0')
            ->assertSuccessful();
        $this->artisan('knowledge:import-email-templates', ['path' => $path])
            ->expectsOutputToContain('"unchanged": 1')
            ->assertSuccessful();

        $this->assertSame(1, EmailTemplate::count());
        $template->refresh();
        $this->assertSame('Updated description', $template->trigger_description);
        $this->assertSame('Updated subject', $template->subject);
        $this->assertSame('Updated body.', $template->body);
        $this->assertFalse($template->active);
        $this->assertSame('2026-02-01 00:00:00', $template->raw_row['updated_at']);
    }

    /** @param list<list<string>> $rows */
    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);

        fputcsv($handle, [
            'eid',
            'name',
            'description',
            'status',
            'ccadmin',
            'title',
            'type',
            'view_name',
            'is_auto',
            'content',
            'created_at',
            'updated_at',
        ], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }

        fclose($handle);
    }
}
