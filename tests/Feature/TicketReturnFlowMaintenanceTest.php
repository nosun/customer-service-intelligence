<?php

namespace Tests\Feature;

use App\Services\Agents\TicketReturnAssessment;
use App\Services\Agents\TicketReturnFlowDocumentation;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TicketReturnFlowMaintenanceTest extends TestCase
{
    public function test_local_check_covers_every_route_and_reports_limits_without_calling_external_sources(): void
    {
        Http::preventStrayRequests();
        $this->artisan('tickets:return-flow-check', ['--json' => true])
            ->expectsOutputToContain('"routing_cases_passed":19,"semantic_cases_registered":10,"semantic_model_replay_performed":false')
            ->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_stale_generated_documentation_fails_the_check(): void
    {
        $this->mock(TicketReturnFlowDocumentation::class)->shouldReceive('render')->once()->andReturn('stale documentation');
        $this->artisan('tickets:return-flow-check')
            ->expectsOutputToContain('documentation is missing or stale')
            ->assertFailed();
    }

    public function test_rule_changes_that_break_existing_cases_fail_the_check(): void
    {
        $definition = app(TicketReturnAssessment::class)->playbook();
        $definition['routing'][0]['stage'] = 'offer_options';
        $this->partialMock(TicketReturnAssessment::class)->shouldReceive('playbook')->andReturn($definition);
        $this->artisan('tickets:return-flow-check')
            ->expectsOutputToContain('routing regression failed: accepted_offer')
            ->assertFailed();
    }

    public function test_unknown_semantic_case_references_fail_the_check(): void
    {
        $definition = app(TicketReturnAssessment::class)->playbook();
        $definition['stages']['offer_options']['evaluation_cases'][] = 'missing-case';
        $this->partialMock(TicketReturnAssessment::class)->shouldReceive('playbook')->andReturn($definition);
        $this->artisan('tickets:return-flow-check')
            ->expectsOutputToContain('unknown semantic evaluation case')
            ->assertFailed();
    }
}
