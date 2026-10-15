<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('title');
            $table->string('knowledge_type')->default('process');
            $table->string('status')->default('observed');
            $table->string('site_key')->nullable();
            $table->text('applicability')->nullable();
            $table->json('required_facts')->default('[]');
            $table->json('policy_rules')->default('[]');
            $table->json('action_steps')->default('[]');
            $table->json('prohibited_claims')->default('[]');
            $table->json('escalation_rules')->default('[]');
            $table->text('source_notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTimeTz('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'site_key']);
        });

        Schema::table('decision_nodes', function (Blueprint $table): void {
            $table->string('lifecycle_stage')->nullable()->after('scenario_key')->index();
            $table->json('intents')->default('[]')->after('request_summary');
            $table->json('required_facts')->default('[]')->after('missing_facts');
            $table->json('required_actions')->default('[]')->after('required_facts');
            $table->text('response_strategy')->nullable()->after('required_actions');
            $table->json('knowledge_gaps')->default('[]')->after('response_strategy');
            $table->json('prohibited_claims')->default('[]')->after('knowledge_gaps');
        });

        Schema::create('knowledge_research_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_key')->unique();
            $table->dateTimeTz('window_start');
            $table->dateTimeTz('window_end');
            $table->unsignedInteger('sample_limit');
            $table->string('selection_seed');
            $table->string('status')->default('running')->index();
            $table->unsignedInteger('selected_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('node_count')->default(0);
            $table->unsignedInteger('new_scenario_count')->default(0);
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->json('synthesis_output')->nullable();
            $table->json('usage')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTimeTz('started_at');
            $table->dateTimeTz('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_research_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_research_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('decision_case_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('selected')->index();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(
                ['knowledge_research_batch_id', 'conversation_id'],
                'knowledge_research_batch_conversation_unique',
            );
        });

        Schema::create('knowledge_research_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_research_batch_id')->constrained()->cascadeOnDelete();
            $table->string('scenario_key')->index();
            $table->string('category')->index();
            $table->string('problem_type')->nullable()->index();
            $table->string('title');
            $table->text('definition');
            $table->json('inclusion_rules')->default('[]');
            $table->json('exclusion_rules')->default('[]');
            $table->json('required_facts')->default('[]');
            $table->json('decision_tree')->default('[]');
            $table->json('reply_strategy')->default('[]');
            $table->json('reply_templates')->default('[]');
            $table->json('required_actions')->default('[]');
            $table->json('prohibited_claims')->default('[]');
            $table->json('uncertainties')->default('[]');
            $table->json('evidence_decision_node_ids')->default('[]');
            $table->string('knowledge_status')->default('observed')->index();
            $table->string('review_status')->default('candidate')->index();
            $table->string('source_hash', 64);
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTimeTz('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['knowledge_research_batch_id', 'scenario_key'],
                'knowledge_research_batch_scenario_unique',
            );
        });

        Schema::create('knowledge_research_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_research_batch_id')->constrained()->cascadeOnDelete();
            $table->string('question_key')->index();
            $table->text('question');
            $table->text('why_it_matters');
            $table->json('options')->default('[]');
            $table->text('provisional_safe_handling');
            $table->json('affected_scenario_keys')->default('[]');
            $table->json('evidence_decision_node_ids')->default('[]');
            $table->string('status')->default('open')->index();
            $table->text('resolution')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTimeTz('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['knowledge_research_batch_id', 'question_key'],
                'knowledge_research_batch_question_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_research_questions');
        Schema::dropIfExists('knowledge_research_findings');
        Schema::dropIfExists('knowledge_research_batch_items');
        Schema::dropIfExists('knowledge_research_batches');
        Schema::dropIfExists('knowledge_articles');

        Schema::table('decision_nodes', function (Blueprint $table): void {
            $table->dropColumn([
                'lifecycle_stage',
                'intents',
                'required_facts',
                'required_actions',
                'response_strategy',
                'knowledge_gaps',
                'prohibited_claims',
            ]);
        });
    }
};
