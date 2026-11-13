<?php

namespace Tests\Feature;

use App\Models\AiWorkflow;
use App\Services\CustomerContacts\MultiDressDraftService;
use App\Services\CustomerContacts\MultiDressInput;
use App\Services\CustomerContacts\MultiDressRoundManager;
use App\Services\CustomerContacts\MultiDressTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\MultiDressScenario as Scenario;
use Tests\TestCase;

class N8nMultiDressWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Storage::fake('local');
        AiWorkflow::create(['workflow_key' => 'work/multi-dress-first-round-v1', 'workflow_version' => '1.0.0',
            'center' => 'work', 'domain' => 'customer_contacts', 'status' => 'evaluating']);
        config(['services.n8n.work_internal_api_token' => 'work-test']);
    }

    public function test_workflow_freezes_exact_reviewed_content_and_duplicate_complete_is_idempotent(): void
    {
        $s = Scenario::snapshot();
        $c = Scenario::communication();
        $manager = app(MultiDressRoundManager::class);
        $round = $manager->ingest($s, $c);
        $work = $manager->requestWork($round);
        $this->mock(MultiDressInput::class)->shouldReceive('capture')->andReturn(['snapshot' => $s, 'communication' => $c, 'hash' => $round->source_hash]);
        $service = app(MultiDressDraftService::class);
        $context = $service->start($work, 'exec-1');
        $this->assertArrayNotHasKey('customer', $context['snapshot']);
        $analysis = ['decision' => 'prepare_first_email', 'question_module' => 'purpose_and_keep_plan', 'evidence_refs' => ['items.101'], 'blocking_reasons' => []];
        $rendered = $service->render($work->refresh(), 'exec-1', $analysis);
        $review = ['approved' => true, 'reviewed_input_hash' => $round->source_hash, 'reviewed_content_hash' => $rendered['mail']['content_hash'], 'violations' => []];
        $first = $service->complete($work->refresh(), 'exec-1', $analysis, $review);
        $again = $service->complete($work->refresh(), 'exec-1', $analysis, $review);
        $this->assertSame($first, $again);
        $this->assertSame('ready', $round->fresh()->communication_state);
        Storage::disk('local')->assertExists($round->fresh()->approved_mail_ref);
        $this->assertDatabaseCount('customer_contact_effects', 0);
    }

    public function test_input_change_during_work_invalidates_draft(): void
    {
        $manager = app(MultiDressRoundManager::class);
        $round = $manager->ingest(Scenario::snapshot(), Scenario::communication());
        $work = $manager->requestWork($round);
        $this->mock(MultiDressInput::class)->shouldReceive('capture')->andReturn(['hash' => str_repeat('b', 64)]);
        $this->expectException(\RuntimeException::class);
        try {
            app(MultiDressDraftService::class)->start($work, 'exec-1');
        } finally {
            $this->assertSame('stale', $work->fresh()->status);
            $this->assertNotSame('ready', $round->fresh()->communication_state);
        }
    }

    public function test_template_never_exposes_timeout_rule_and_rejects_unapproved_module(): void
    {
        $template = app(MultiDressTemplate::class);
        $mail = $template->render(Scenario::snapshot(), ['question_module' => 'purpose_and_keep_plan']);
        foreach (['on hold', 'within 48 hours', 'TEST-A', 'TEST-B', 'Black'] as $text) {
            $this->assertStringContainsString($text, $mail['body']);
        }
        $this->assertStringNotContainsString('automatically', $mail['body']);
        $this->assertStringNotContainsString('canceling the others', $mail['body']);
        $this->expectException(ValidationException::class);
        $template->render(Scenario::snapshot(), ['question_module' => 'send_and_release_now']);
    }

    public function test_internal_routes_require_work_token_and_real_work_type(): void
    {
        $path = '/api/internal/n8n/multi-dress-work-items/missing/start';
        $this->postJson($path, [])->assertUnauthorized();
        $this->withToken('work-test')->postJson($path, ['execution_id' => 'x', 'workflow_key' => 'x', 'workflow_version' => 'x'])->assertNotFound();
    }

    public function test_workflow_registration_keeps_customer_contacts_domain(): void
    {
        AiWorkflow::query()->delete();
        $this->artisan('n8n:register-workflow', ['key' => 'work/multi-dress-first-round-v1', 'version' => '1.0.0',
            'file' => base_path('n8n/workflows/work__multi-dress-first-round-v1.json'), '--status' => 'evaluating'])->assertSuccessful();
        $this->assertDatabaseHas('ai_workflows', ['workflow_key' => 'work/multi-dress-first-round-v1', 'center' => 'work', 'domain' => 'customer_contacts', 'status' => 'evaluating']);
    }
}
