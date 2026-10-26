<?php

namespace Tests\Unit;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class N8nWorkflowDefinitionTest extends TestCase
{
    public function test_ticket_plan_normalizes_customer_text_without_discarding_supported_intent(): void
    {
        $workflow = json_decode(file_get_contents(base_path('n8n/workflows/work__ticket-draft-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $code = collect($workflow['nodes'])->keyBy('name')['Normalize Ticket Plan']['parameters']['jsCode'];
        foreach (['text', 'customer_text', 'missing'] as $field) {
            $clause = ['intent' => 'refund_review'];
            if ($field !== 'missing') {
                $clause[$field] = 'I want to return this dress';
            }
            $plan = ['clauses' => [$clause], 'policy_keys' => ['ticket_order_action_review_process'], 'unsupported_requests' => []];
            $script = 'const $input = {first: () => ({json: {output: '.json_encode($plan).'}})};'
                .'const $ = () => ({first: () => ({json: {context: {ticket: {messages: [{role: "customer", text: "I want to return this dress"}]}}}})});'
                .'process.stdout.write(JSON.stringify((new Function("$", "$input", '.json_encode($code).'))($, $input)[0].json.output));';
            $process = new Process(['node', '-e', $script]);
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            if ($field === 'missing') {
                $this->assertSame('unsupported', $result['clauses'][0]['intent']);
                $this->assertNotEmpty($result['unsupported_requests']);
            } else {
                $this->assertSame('I want to return this dress', $result['clauses'][0]['text']);
                $this->assertSame('refund_review', $result['clauses'][0]['intent']);
                $this->assertSame([], $result['unsupported_requests']);
            }
        }
    }

    public function test_consultation_scope_requires_a_verified_channel_and_a_hidden_reply(): void
    {
        $workflow = json_decode(file_get_contents(base_path('n8n/workflows/work__qa-draft-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $code = collect($workflow['nodes'])->keyBy('name')['Plan Answer Scope']['parameters']['jsCode'];
        foreach (['valid', 'wrong_email', 'public', 'missing_context', 'missing_strategy'] as $scenario) {
            $strategy = ['public_value' => 'individual_case', 'recommended_visible' => $scenario === 'public',
                'can_resolve_in_one_reply' => false, 'response_mode' => 'email_handoff', 'reason' => 'Needs individual requirements',
                'contact_email' => $scenario === 'wrong_email' ? 'wrong@example.test' : 'support@example.test',
                'email_context' => $scenario === 'missing_context' ? [] : ['Requested change']];
            $plan = ['version' => 'customer-decision-v1', 'customer_goal' => 'Discuss alterations',
                'answer_units' => [['covered_intents' => ['request_other_customization'], 'supported_answer_points' => ['Email with the requested change'], 'essential_unknowns' => []]]];
            if ($scenario !== 'missing_strategy') {
                $plan['service_strategy'] = $strategy;
            }
            $script = 'const plan = '.json_encode($plan).';'
                .'const $ = name => ({first: () => ({json: name === "Start Draft Request" ? {context: {question: "Can it be altered?"}} : {plan: {intents: ["request_other_customization"]}, facts: {site: {customer_service_email: "support@example.test"}}}})});'
                .'const $input = {first: () => ({json: {output: plan}})};'
                .'try {(new Function("$", "$input", '.json_encode($code).'))($, $input); process.stdout.write("valid");} catch (error) {process.stdout.write("rejected");}';
            $process = new Process(['node', '-e', $script]);
            $process->mustRun();
            $this->assertSame($scenario === 'valid' ? 'valid' : 'rejected', $process->getOutput(), $scenario);
        }
    }

    public function test_writer_gets_selected_evidence_and_planner_does_not_get_policy_templates(): void
    {
        $workflow = json_decode(file_get_contents(base_path('n8n/workflows/work__qa-draft-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $nodes = collect($workflow['nodes'])->keyBy('name');
        $scope = ['version' => 'customer-decision-v1', 'customer_goal' => 'Decide whether to purchase', 'answer_units' => [['supported_answer_points' => ['Use the current chart']]], 'deferred_details' => [['detail' => 'Shipping instructions', 'reason' => 'Not needed before purchase']]];
        foreach (['Build Answer Scope Prompt', 'Build Concise Reply Prompt'] as $name) {
            $script = 'const scope = '.json_encode($scope).';'
                .'const state = {"Start Draft Request": {context: {question: "What size? Can I return it?"}}, "Resolve Product Order and Live Facts": {plan: {intents: ["ask_size_chart", "ask_return_policy"]}, facts: {product: {name: "Raw catalog"}}}, "Plan Answer Scope": {output: scope}};'
                .'const $ = name => ({first: () => ({json: state[name]})});'
                .'const $input = {first: () => ({json: {data: [{id: 1, policy_rules: ["Approved rule"], reply_templates: ["A full canned answer"], reply_strategy: ["Every possible branch"]}]}})};'
                .'process.stdout.write(JSON.stringify((new Function("$", "$input", '.json_encode($nodes[$name]['parameters']['jsCode']).'))($, $input)[0].json));';
            $process = new Process(['node', '-e', $script]);
            $process->mustRun();
            $input = json_decode(json_decode($process->getOutput(), true)['user_prompt'], true);
            if ($name === 'Build Answer Scope Prompt') {
                $this->assertSame(['Approved rule'], $input['policies'][0]['policy_rules']);
                $this->assertArrayNotHasKey('reply_templates', $input['policies'][0]);
                $this->assertArrayNotHasKey('reply_strategy', $input['policies'][0]);
            } else {
                $this->assertSame($scope, $input['answer_scope']);
                $this->assertArrayNotHasKey('facts', $input);
                $this->assertArrayNotHasKey('policies', $input);
            }
        }
    }

    public function test_claimed_approval_cannot_bypass_failed_or_missing_decision_quality_checks(): void
    {
        $workflow = json_decode(file_get_contents(base_path('n8n/workflows/work__qa-draft-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $nodes = collect($workflow['nodes'])->keyBy('name');
        $passingChecks = array_fill_keys(['factual_accuracy', 'relevance', 'actionable_guidance', 'natural_tone', 'decision_risks', 'intent_coverage', 'language_equivalence'], true);
        foreach ([$passingChecks, [...$passingChecks, 'relevance' => false], []] as $checks) {
            foreach (['Apply Review Guards', 'Apply Final Review Guards'] as $name) {
                $script = 'const checks = '.json_encode($checks).';'
                    .'const $ = name => ({first: () => ({json: name === "Plan Answer Scope" ? {output: {version: "customer-decision-v1", answer_units: [{essential_unknowns: []}]}} : name === "Start Draft Request" ? {context: {}} : name === "Resolve Product Order and Live Facts" ? {facts: {}} : {data: [], output: {answer_en: "Please use the chart."}}})});'
                    .'const $input = {first: () => ({json: {output: {approved: true, must_block: false, quality_checks: checks, final_answer_en: "Please use the chart."}}})};'
                    .'process.stdout.write(JSON.stringify((new Function("$", "$input", '.json_encode($nodes[$name]['parameters']['jsCode']).'))($, $input)[0].json));';
                $process = new Process(['node', '-e', $script]);
                $process->mustRun();
                $output = json_decode($process->getOutput(), true)['output'];
                $this->assertSame($checks === $passingChecks, $output['approved'], $name);
                $this->assertFalse($output['must_block'], 'Quality failures do not invent a safety failure.');
            }
        }
    }

    public function test_ai_http_nodes_outlast_the_bounded_php_completion(): void
    {
        $workflow = json_decode(file_get_contents(base_path('n8n/workflows/work__qa-draft-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $nodes = collect($workflow['nodes'])->filter(fn ($node) => str_contains($node['parameters']['url'] ?? '', '/ai/structured-completion'));
        $this->assertNotEmpty($nodes);
        foreach ($nodes as $node) {
            $this->assertSame(240000, $node['parameters']['options']['timeout'], $node['name']);
        }
    }

    public function test_verified_color_options_resolve_browsing_without_swallowing_explicit_photo_requests(): void
    {
        $workflow = json_decode((string) file_get_contents(base_path('n8n/workflows/work__qa-draft-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $nodes = collect($workflow['nodes'])->keyBy('name');
        foreach ([
            ['Can I see this dress in different colors?', ['As Shown'], ['ask_available_colors'], true],
            ['Can I see this dress in different colors?', ['Navy', 'Ivory'], ['ask_available_colors'], true],
            ['Can I see a photo of an older version?', ['As Shown'], ['ask_product_photos'], false],
            ['Can I see this dress in different colors?', [], ['ask_available_colors'], false],
            ['Can I see different colors and how much is shipping?', ['As Shown'], ['ask_available_colors', 'ask_shipping_options'], false],
        ] as [$question, $colors, $intents, $resolved]) {
            $state = [
                'Start Draft Request' => ['context' => ['question' => $question]],
                'Resolve Product Order and Live Facts' => ['plan' => ['intents' => $intents], 'facts' => ['product' => ['colors' => $colors]]],
            ];
            $script = 'const state = '.json_encode($state).';'
                .'const $ = name => ({first: () => ({json: state[name]})});'
                .'const $input = {first: () => ({json: {output: {version: "customer-decision-v1", customer_goal: "Answer the request", service_strategy: {public_value: "general_product", recommended_visible: true, can_resolve_in_one_reply: true, response_mode: "answer", reason: "General product question", contact_email: null, email_context: []}, answer_units: [{covered_intents: [], supported_answer_points: [], essential_unknowns: ["Unverified photo"]}]}}})};'
                .'process.stdout.write(JSON.stringify((new Function("$", "$input", '.json_encode($nodes['Plan Answer Scope']['parameters']['jsCode']).'))($, $input)[0].json));';
            $process = new Process(['node', '-e', $script]);
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($resolved ? [] : ['Unverified photo'], $result['output']['answer_units'][0]['essential_unknowns'], $question);
        }
    }

    public function test_review_guards_block_essential_unknowns_but_allow_incidental_missing_metadata(): void
    {
        $workflow = json_decode((string) file_get_contents(base_path('n8n/workflows/work__qa-draft-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $nodes = collect($workflow['nodes'])->keyBy('name');
        foreach ([[], ['Whether a historical red photo is available']] as $unknowns) {
            foreach (['Apply Review Guards', 'Apply Final Review Guards'] as $name) {
                $scope = ['answer_units' => [['essential_unknowns' => $unknowns]], 'incidental_unknowns' => ['Missing image color tags']];
                $script = 'const scope = '.json_encode($scope).';'
                    .'const $ = name => ({first: () => ({json: name === "Plan Answer Scope" ? {output: scope} : name === "Start Draft Request" ? {context: {}} : name === "Resolve Product Order and Live Facts" ? {facts: {}} : {data: [], output: {answer_en: "This dress is currently available only in the color shown."}}})});'
                    .'const $input = {first: () => ({json: {output: {approved: true, must_block: false, final_answer_en: "This dress is currently available only in the color shown."}}})};'
                    .'process.stdout.write(JSON.stringify((new Function("$", "$input", '.json_encode($nodes[$name]['parameters']['jsCode']).'))($, $input)[0].json));';
                $process = new Process(['node', '-e', $script]);
                $process->mustRun();
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($unknowns === [], $result['output']['approved'], $name);
                $this->assertSame($unknowns !== [], $result['output']['must_block'], $name);
            }
        }
    }

    public function test_answer_scope_is_planned_from_facts_and_policies_before_drafting_and_reaches_reviews(): void
    {
        $workflow = json_decode((string) file_get_contents(base_path('n8n/workflows/work__qa-draft-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $nodes = collect($workflow['nodes'])->keyBy('name');
        foreach (['Load Verified Policies' => 'Build Answer Scope Prompt', 'Build Answer Scope Prompt' => 'Propose Answer Scope', 'Propose Answer Scope' => 'Plan Answer Scope', 'Plan Answer Scope' => 'Build Concise Reply Prompt'] as $from => $to) {
            $this->assertSame($to, $workflow['connections'][$from]['main'][0][0]['node']);
        }
        foreach (['Build Answer Scope Prompt', 'Propose Answer Scope', 'Plan Answer Scope'] as $name) {
            $this->assertSame('Mark Draft Failed', $workflow['connections'][$name]['main'][1][0]['node']);
        }
        $scope = ['answer_units' => [['covered_intents' => ['ask_available_colors', 'ask_color_photos'], 'essential_unknowns' => []]]];
        foreach (['Build Concise Reply Prompt', 'Build Review Prompt', 'Build Repair Prompt', 'Build Final Review Prompt'] as $name) {
            $script = 'const scope = '.json_encode($scope).';'
                .'const $ = name => ({first: () => ({json: name === "Plan Answer Scope" ? {output: scope} : name === "Start Draft Request" ? {context: {}} : name === "Resolve Product Order and Live Facts" ? {plan: {}, facts: {}} : {data: [], output: {}}})});'
                .'const $input = {first: () => ({json: {output: {}}})};'
                .'process.stdout.write(JSON.stringify((new Function("$", "$input", '.json_encode($nodes[$name]['parameters']['jsCode']).'))($, $input)[0].json));';
            $process = new Process(['node', '-e', $script]);
            $process->mustRun();
            $prompt = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $input = json_decode($prompt['user_prompt'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($scope, $input['answer_scope'], $name);
        }
    }

    public function test_repair_instructions_returned_as_text_remain_a_complete_instruction(): void
    {
        $workflow = json_decode((string) file_get_contents(base_path('n8n/workflows/work__qa-draft-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $nodes = collect($workflow['nodes'])->keyBy('name');
        $script = 'const $ = name => ({first: () => ({json: name === "Start Draft Request" ? {context: {}} : name === "Resolve Product Order and Live Facts" ? {facts: {}} : name === "Generate English Draft" ? {output: {answer_en: "A short answer."}} : {data: []}})});'
            .'const $input = {first: () => ({json: {output: {approved: false, problems: ["Too blunt"], repair_instructions: "Soften the limitation without changing the facts."}}})};'
            .'process.stdout.write(JSON.stringify((new Function("$", "$input", '.json_encode($nodes['Apply Review Guards']['parameters']['jsCode']).'))($, $input)[0].json));';
        $process = new Process(['node', '-e', $script]);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['Soften the limitation without changing the facts.'], $result['output']['repair_instructions']);
        $this->assertFalse($result['output']['approved']);
    }

    public function test_customer_style_reaches_every_generation_translation_and_review_stage(): void
    {
        $workflow = json_decode((string) file_get_contents(base_path('n8n/workflows/work__qa-draft-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $nodes = collect($workflow['nodes'])->keyBy('name');
        $context = ['question' => 'Can I see other colors?', 'reply_style' => ['warm_customer_service_voice' => true]];
        $resolved = ['plan' => ['customer_language' => 'de', 'intents' => ['ask_color_photos']], 'facts' => []];

        foreach (['Build Concise Reply Prompt', 'Build Translation Prompt', 'Build Review Prompt', 'Build Repair Prompt', 'Build Final Review Prompt'] as $name) {
            $script = 'const context = '.json_encode($context).'; const resolved = '.json_encode($resolved).';'
                .'const $ = name => ({first: () => ({json: name === "Start Draft Request" ? {context} : name === "Resolve Product Order and Live Facts" ? resolved : {data: [], output: {answer_en: "Example"}}})});'
                .'const $input = {first: () => ({json: {data: [], output: {answer_en: "Example"}}})};'
                .'process.stdout.write(JSON.stringify((new Function("$", "$input", '.json_encode($nodes[$name]['parameters']['jsCode']).'))($, $input)[0].json));';
            $process = new Process(['node', '-e', $script]);
            $process->mustRun();
            $prompt = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $input = json_decode($prompt['user_prompt'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($context['reply_style'], $input['reply_style'], $name);
            $this->assertStringContainsString('warm, courteous, natural customer-service voice', $prompt['system_prompt'], $name);
        }
    }

    public function test_ticket_workflow_keeps_runtime_facts_verified_policies_and_repair_in_work_center(): void
    {
        $path = base_path('n8n/workflows/work__ticket-draft-v1.json');
        $workflow = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $nodes = collect($workflow['nodes'])->keyBy('name');

        foreach ([
            'Start Ticket WorkItem',
            'Plan Ticket Reply',
            'Resolve Ticket Facts',
            'Load Ticket Policies',
            'Generate Ticket Draft',
            'Review and Repair Ticket Draft Once',
            'Save Ticket Draft',
            'Mark Ticket Draft Failed',
        ] as $name) {
            $this->assertTrue($nodes->has($name), "Missing Ticket n8n node: {$name}");
        }
        $this->assertFalse($workflow['active']);
        $this->assertStringContainsString('/ticket-work-items/', $nodes['Start Ticket WorkItem']['parameters']['url']);
        $this->assertStringContainsString('verified Policies', $nodes['Build Ticket Draft Prompt']['parameters']['jsCode']);
        $this->assertStringContainsString('repair it at most once', $nodes['Build Ticket Review Repair Prompt']['parameters']['jsCode']);
        $this->assertStringContainsString('execution_id', $nodes['Save Ticket Draft']['parameters']['body']);
        $this->assertSame('continueErrorOutput', $nodes['Resolve Ticket Facts']['onError']);
        $this->assertSame('Mark Ticket Draft Failed', $workflow['connections']['Review and Repair Ticket Draft Once']['main'][1][0]['node']);
    }

    public function test_draft_workflow_has_separate_review_repair_and_final_review_nodes(): void
    {
        $path = base_path('n8n/workflows/work__qa-draft-agent-v1.json');
        $workflow = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $nodes = collect($workflow['nodes'])->keyBy('name');

        foreach (['Review Draft', 'Apply Review Guards', 'Reviewer Approved', 'Repair Draft Once', 'Final Review Draft', 'Apply Final Review Guards', 'Save Reviewed Draft', 'Mark Draft Failed'] as $name) {
            $this->assertTrue($nodes->has($name), "Missing n8n node: {$name}");
        }
        $this->assertStringContainsString('input_hash', $nodes['Save Reviewed Draft']['parameters']['body']);
        $this->assertSame('continueErrorOutput', $nodes['Build Review Prompt']['onError']);
        $this->assertSame('continueErrorOutput', $nodes['Build Repair Prompt']['onError']);
        $this->assertSame('continueErrorOutput', $nodes['Build Final Review Prompt']['onError']);
        $this->assertSame('Mark Draft Failed', $workflow['connections']['Final Review Draft']['main'][1][0]['node']);
        $this->assertSame('Apply Review Guards', $workflow['connections']['Review Draft']['main'][0][0]['node']);
        $this->assertSame('Apply Final Review Guards', $workflow['connections']['Final Review Draft']['main'][0][0]['node']);
        $this->assertStringContainsString('generic contact referral', $nodes['Apply Review Guards']['parameters']['jsCode']);
    }
}
