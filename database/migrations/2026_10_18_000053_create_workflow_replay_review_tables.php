<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_workflow_replay_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_workflow_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('reviewing')->index();
            $table->char('definition_checksum', 64);
            $table->char('suite_checksum', 64);
            $table->char('predictions_checksum', 64);
            $table->string('suite_path');
            $table->string('predictions_path');
            $table->unsignedInteger('case_count');
            $table->unsignedInteger('reviewed_count')->default(0);
            $table->json('metrics')->nullable();
            $table->json('release_report')->nullable();
            $table->string('release_report_path')->nullable();
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->dateTimeTz('imported_at');
            $table->dateTimeTz('gated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['ai_workflow_id', 'suite_checksum', 'predictions_checksum'],
                'workflow_replay_run_input_unique',
            );
        });

        Schema::create('ai_workflow_replay_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_workflow_replay_run_id')->constrained()->cascadeOnDelete();
            $table->string('case_key');
            $table->string('category')->nullable()->index();
            $table->text('question');
            $table->json('expected_intents')->default('[]');
            $table->boolean('must_block')->default(false);
            $table->json('prediction');
            $table->unsignedSmallInteger('fact_errors')->nullable();
            $table->unsignedSmallInteger('policy_violations')->nullable();
            $table->boolean('accepted_as_is')->nullable();
            $table->text('review_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTimeTz('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['ai_workflow_replay_run_id', 'case_key'], 'workflow_replay_case_unique');
        });

        $now = now();
        DB::table(config('admin.database.menu_table'))->updateOrInsert(
            ['uri' => 'workflow-replay-reviews'],
            [
                'parent_id' => 0,
                'order' => 6,
                'title' => 'Workflow 回放审核',
                'icon' => 'fa-check-square-o',
                'permission' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table(config('admin.database.menu_table'))->where('uri', 'workflow-replay-reviews')->delete();
        Schema::dropIfExists('ai_workflow_replay_cases');
        Schema::dropIfExists('ai_workflow_replay_runs');
    }
};
