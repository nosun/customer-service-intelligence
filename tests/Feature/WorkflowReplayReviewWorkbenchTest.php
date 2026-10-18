<?php

namespace Tests\Feature;

use App\Models\AiWorkflow;
use App\Models\AiWorkflowReplayRun;
use App\Services\ProductQuestions\ProductQuestionDraftReplaySuite;
use App\Services\ProductQuestions\ProductQuestionReplayReviewService;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkflowReplayReviewWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_predictions_can_be_imported_reviewed_and_gated_from_the_backend(): void
    {
        $workflow = AiWorkflow::create([
            'workflow_key' => 'work/qa-draft-agent-v1',
            'workflow_version' => '1.2.0',
            'status' => 'evaluating',
            'definition_checksum' => str_repeat('a', 64),
            'allowed_fact_keys' => [],
            'allowed_actions' => [],
        ]);
        $suite = app(ProductQuestionDraftReplaySuite::class)->cases();
        $predictions = collect($suite)->map(fn (array $case): array => [
            'id' => $case['id'],
            'status' => $case['must_block'] ? 'blocked' : 'generated',
            'intents' => $case['expected_intents'],
            'answer_en' => 'A concise reviewed answer.',
            'facts' => [],
            'missing_facts' => [],
            'safety_flags' => [],
            'duration_ms' => 100,
        ])->all();
        $directory = storage_path('framework/testing/replay-review-workbench');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $path = $directory.'/predictions.json';
        file_put_contents($path, json_encode($predictions, JSON_THROW_ON_ERROR));

        $run = app(ProductQuestionReplayReviewService::class)->import(
            $workflow->workflow_key,
            $workflow->workflow_version,
            $path,
        );

        $this->assertSame(50, $run->case_count);
        $this->assertSame(0, $run->reviewed_count);
        $this->assertSame(50, $run->cases()->count());

        $administrator = $this->administrator();
        $first = $run->cases()->orderBy('case_key')->firstOrFail();
        $this->actingAs($administrator, 'admin')
            ->get("/admin/workflow-replay-reviews/{$run->id}")
            ->assertOk()
            ->assertSee($first->question)
            ->assertSee('事实错误数');
        $this->post("/admin/workflow-replay-reviews/{$run->id}/cases/{$first->id}", [
            'fact_errors' => 0,
            'policy_violations' => 0,
            'accepted_as_is' => 1,
            'review_notes' => 'Facts and policy checked.',
        ])->assertRedirect();
        $this->assertSame(1, $run->fresh()->reviewed_count);
        $this->assertDatabaseHas('ai_workflow_replay_cases', [
            'id' => $first->id,
            'reviewed_by' => $administrator->id,
            'fact_errors' => 0,
            'policy_violations' => 0,
        ]);

        $run->cases()->update([
            'fact_errors' => 0,
            'policy_violations' => 0,
            'accepted_as_is' => true,
            'reviewed_by' => $administrator->id,
            'reviewed_at' => now(),
        ]);
        app(ProductQuestionReplayReviewService::class)->refreshProgress($run);
        $report = app(ProductQuestionReplayReviewService::class)->gate($run->fresh(), $directory.'/report.json');

        $this->assertTrue($report['passed']);
        $this->assertSame(1.0, $report['metrics']['quality_review_coverage']);
        $this->assertSame('passed', AiWorkflowReplayRun::findOrFail($run->id)->status);
        $this->assertEquals(1.0, $workflow->fresh()->replay_metrics['quality_review_coverage']);

        $this->post("/admin/workflow-replay-reviews/{$run->id}/cases/{$first->id}", [
            'fact_errors' => 1,
            'policy_violations' => 0,
            'accepted_as_is' => 0,
            'review_notes' => 'Found a fact issue after the first gate.',
        ])->assertRedirect();
        $invalidated = AiWorkflowReplayRun::findOrFail($run->id);
        $this->assertSame('ready', $invalidated->status);
        $this->assertNull($invalidated->release_report);
        $this->assertNull($invalidated->gated_at);
    }

    public function test_replay_review_menu_is_created(): void
    {
        $this->assertDatabaseHas(config('admin.database.menu_table'), [
            'title' => 'Workflow 回放审核',
            'uri' => 'workflow-replay-reviews',
        ]);
    }

    private function administrator(): Administrator
    {
        $administrator = Administrator::create([
            'username' => 'replay-review-admin',
            'password' => Hash::make('secret'),
            'name' => 'Replay Review Admin',
        ]);
        $administrator->roles()->attach(Role::where('slug', 'administrator')->firstOrFail());

        return $administrator;
    }
}
