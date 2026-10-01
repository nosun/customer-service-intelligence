<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('case_key')->unique();
            $table->string('primary_category')->nullable()->index();
            $table->string('primary_problem_type')->nullable()->index();
            $table->string('case_risk')->default('normal')->index();
            $table->text('case_summary')->nullable();
            $table->unsignedInteger('node_count')->default(0);
            $table->string('review_status')->default('candidate')->index();
            $table->string('extractor_version');
            $table->string('input_hash', 64);
            $table->dateTimeTz('extracted_at');
            $table->dateTimeTz('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('decision_case_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('model');
            $table->string('prompt_version');
            $table->string('extractor_version');
            $table->string('input_hash', 64)->index();
            $table->string('prompt_hash', 64);
            $table->string('status')->default('running')->index();
            $table->json('structured_output')->nullable();
            $table->json('usage')->nullable();
            $table->string('provider_request_id')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTimeTz('started_at');
            $table->dateTimeTz('finished_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'status']);
        });

        Schema::create('decision_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('decision_case_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('problem_type')->nullable()->index();
            $table->string('scenario_key')->nullable()->index();
            $table->text('request_summary');
            $table->json('observed_facts')->default('[]');
            $table->json('missing_facts')->default('[]');
            $table->string('observed_decision')->default('unknown')->index();
            $table->text('observed_action')->nullable();
            $table->text('stated_reason')->nullable();
            $table->text('observed_outcome')->nullable();
            $table->boolean('is_exception')->default(false)->index();
            $table->json('uncertainties')->default('[]');
            $table->decimal('extraction_confidence', 5, 4)->default(0);
            $table->string('review_status')->default('candidate')->index();
            $table->string('extractor_version');
            $table->timestamps();
            $table->unique(['decision_case_id', 'sequence']);
        });

        Schema::create('decision_node_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('decision_node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_event_id')->constrained()->cascadeOnDelete();
            $table->string('evidence_role');
            $table->string('source')->default('ai');
            $table->string('extractor_version');
            $table->timestamps();
            $table->unique(
                ['decision_node_id', 'conversation_event_id', 'evidence_role'],
                'decision_node_evidence_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_node_evidence');
        Schema::dropIfExists('decision_nodes');
        Schema::dropIfExists('decision_case_runs');
        Schema::dropIfExists('decision_cases');
    }
};
