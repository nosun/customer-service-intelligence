<?php

namespace Tests\Feature;

use App\Services\ProductQuestions\ProductQuestionDraftReplaySuite;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductQuestionDraftReleaseGateTest extends TestCase
{
    public function test_real_replay_predictions_require_complete_human_quality_annotations(): void
    {
        [$predictions, $annotations, $report] = $this->files(false);

        $this->assertSame(1, Artisan::call('product-questions:gate-draft-release', [
            'predictions' => $predictions,
            '--annotations' => $annotations,
            '--report' => $report,
        ]));
        $this->assertFalse(json_decode((string) file_get_contents($report), true)['passed']);
    }

    public function test_release_gate_writes_a_passing_report_for_a_fully_reviewed_safe_run(): void
    {
        [$predictions, $annotations, $report] = $this->files(true);

        $this->assertSame(0, Artisan::call('product-questions:gate-draft-release', [
            'predictions' => $predictions,
            '--annotations' => $annotations,
            '--report' => $report,
        ]));
        $saved = json_decode((string) file_get_contents($report), true);
        $this->assertTrue($saved['passed']);
        $this->assertSame(1, $saved['metrics']['prediction_coverage']);
        $this->assertSame(1, $saved['metrics']['quality_review_coverage']);
        $this->assertSame(
            hash_file('sha256', base_path('n8n/workflows/work__qa-draft-agent-v1.json')),
            $saved['workflow']['definition_checksum'],
        );
    }

    /** @return array{string,string,string} */
    private function files(bool $complete): array
    {
        $directory = storage_path('framework/testing/draft-release-gate-'.($complete ? 'complete' : 'partial'));
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $suite = app(ProductQuestionDraftReplaySuite::class)->cases();
        $predictions = collect($suite)->map(fn (array $case): array => [
            'id' => $case['id'],
            'status' => $case['must_block'] ? 'blocked' : 'generated',
            'intents' => $case['expected_intents'],
            'answer_en' => 'Reviewed replay answer.',
            'duration_ms' => 100,
        ])->all();
        $annotations = collect($complete ? $suite : array_slice($suite, 0, 49))->mapWithKeys(fn (array $case): array => [
            $case['id'] => ['fact_error' => 0, 'policy_violation' => 0, 'accepted_as_is' => true],
        ])->all();
        $predictionPath = $directory.'/predictions.json';
        $annotationPath = $directory.'/annotations.json';
        $reportPath = $directory.'/report.json';
        file_put_contents($predictionPath, json_encode($predictions, JSON_THROW_ON_ERROR));
        file_put_contents($annotationPath, json_encode($annotations, JSON_THROW_ON_ERROR));

        return [$predictionPath, $annotationPath, $reportPath];
    }
}
