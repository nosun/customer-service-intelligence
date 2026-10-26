<?php

namespace Tests\Unit;

use App\Services\TicketWork\TicketReplyPlan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TicketInvestigationDefinitionTest extends TestCase
{
    public function test_workflow_is_bounded_and_repaired_text_always_has_an_independent_review(): void
    {
        $workflow = $this->workflow();
        $nodes = collect($workflow['nodes'])->keyBy('name');
        $edges = $workflow['connections'];
        $this->assertFalse($workflow['active']);
        $this->assertSame('Final Review Ticket Draft', $edges['Build Ticket Final Review Prompt']['main'][0][0]['node']);
        $this->assertSame('Apply Ticket Final Review Guards', $edges['Final Review Ticket Draft']['main'][0][0]['node']);
        $this->assertSame('Prepare Ticket Generation', $edges['Validate Reassessed Ticket Plan']['main'][0][0]['node']);
        foreach ($nodes as $node) {
            if ($node['type'] === 'n8n-nodes-base.code') {
                $process = new Process(['node', '-e', 'new Function('.json_encode($node['parameters']['jsCode']).');']);
                $process->mustRun();
                $this->assertTrue($process->isSuccessful(), $node['name']);
            }
            if (str_contains($node['parameters']['url'] ?? '', '/ai/structured-completion')) {
                $this->assertSame(240000, $node['parameters']['options']['timeout']);
            }
        }
        // No arbitrary HTTP tool or business mutation appears in this workflow.
        foreach ($nodes->where('type', 'n8n-nodes-base.httpRequest') as $node) {
            $this->assertStringContainsString('LARAVEL_INTERNAL_BASE_URL', $node['parameters']['url']);
        }
    }

    public function test_both_review_guards_fail_closed_on_missing_checks_conflicts_and_text_mismatch(): void
    {
        $nodes = collect($this->workflow()['nodes'])->keyBy('name');
        foreach (['Apply Ticket Review Guards', 'Apply Ticket Final Review Guards'] as $name) {
            foreach (['valid', 'quality', 'conflict', 'wrong_text', 'missing_request', 'guidance'] as $scenario) {
                $review = ['approved' => true, 'must_block' => false, 'repairable' => true,
                    'reviewed_answer' => 'We will cancel your order.', 'covered_request_ids' => ['request_1'],
                    'business_conflicts' => [], 'guidance_conflicts' => [], 'guidance_evidence' => 'Please cancel.',
                    'quality_checks' => array_fill_keys(TicketReplyPlan::QUALITY_CHECKS, true)];
                match ($scenario) {
                    'quality' => $review['quality_checks']['operator_decision'] = false,
                    'conflict' => $review['business_conflicts'] = [['code' => 'CUSTOMER_REFUSAL']],
                    'wrong_text' => $review['reviewed_answer'] = 'Different email.',
                    'missing_request' => $review['covered_request_ids'] = [],
                    'guidance' => $review['guidance_evidence'] = 'Invented decision.',
                    default => null,
                };
                $prepared = ['operator_guidance' => ['text' => 'Please cancel.'],
                    'plan' => ['clauses' => [['id' => 'request_1', 'intent' => 'production_cancellation']]],
                    'reply_plan' => ['blocking_reasons' => []]];
                $script = 'const prepared='.json_encode($prepared).';const review='.json_encode($review).';'
                    .'const $=name=>({first:()=>({json:name==="Prepare Ticket Generation"?prepared:{output:{answer_en:"We will cancel your order."}}})});'
                    .'const $input={first:()=>({json:{output:review}})};'
                    .'process.stdout.write(JSON.stringify(new Function("$","$input",'.json_encode($nodes[$name]['parameters']['jsCode']).')($,$input)[0].json.output));';
                $process = new Process(['node', '-e', $script]);
                $process->mustRun();
                $output = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($scenario === 'valid', $output['approved'], $name.' '.$scenario);
                $this->assertSame('We will cancel your order.', $output['final_answer']);
                if ($name === 'Apply Ticket Final Review Guards') {
                    $this->assertFalse($output['repairable']);
                }
            }
        }
    }

    public function test_no_reply_branch_skips_the_writer_and_requires_a_separate_review(): void
    {
        $workflow = $this->workflow();
        $edges = $workflow['connections'];
        $this->assertSame('Ticket Reply Needed', $edges['Prepare Ticket Generation']['main'][0][0]['node']);
        $this->assertSame('Build No Reply Review Prompt', $edges['Ticket Reply Needed']['main'][1][0]['node']);
        $this->assertSame('Review No Reply Decision', $edges['Build No Reply Review Prompt']['main'][0][0]['node']);
        $this->assertSame('Apply No Reply Guards', $edges['Review No Reply Decision']['main'][0][0]['node']);
        $this->assertSame('Save Ticket Draft', $edges['Apply No Reply Guards']['main'][0][0]['node']);
        $node = collect($workflow['nodes'])->firstWhere('name', 'Apply No Reply Guards');
        foreach ([true, false] as $reviewApproved) {
            $review = ['approved' => $reviewApproved, 'must_block' => false,
                'response_decision_approved' => $reviewApproved, 'reviewed_response_mode' => 'no_reply',
                'covered_request_ids' => ['request_1'], 'quality_checks' => array_fill_keys(TicketReplyPlan::QUALITY_CHECKS, true),
                'guidance_conflicts' => [], 'business_conflicts' => []];
            $prepared = ['plan' => ['clauses' => [['id' => 'request_1']], 'intents' => ['conversation_update']],
                'reply_plan' => ['blocking_reasons' => []]];
            $script = 'const $=()=>({first:()=>({json:'.json_encode($prepared).'})});'
                .'const $input={first:()=>({json:{output:'.json_encode($review).'}})};'
                .'process.stdout.write(JSON.stringify(new Function("$","$input",'.json_encode($node['parameters']['jsCode']).')($,$input)[0].json.output));';
            $process = new Process(['node', '-e', $script]);
            $process->mustRun();
            $output = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($reviewApproved, $output['approved']);
            $this->assertSame('', $output['final_answer']);
        }
    }

    private function workflow(): array
    {
        return json_decode(file_get_contents(base_path('n8n/workflows/work__ticket-draft-v2.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
