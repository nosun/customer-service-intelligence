<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_case_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('benchmark_case_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('title');
            $table->unsignedBigInteger('trigger_event_id');
            $table->unsignedBigInteger('context_start_event_id')->nullable();
            $table->unsignedBigInteger('context_end_event_id');
            $table->json('observed_reply_event_ids')->default('[]');
            $table->boolean('requires_response')->default(true);
            $table->string('stage_end_reason')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('gold_site_key')->nullable();
            $table->string('gold_lifecycle_stage')->nullable();
            $table->text('gold_customer_goal')->nullable();
            $table->json('gold_intents')->default('[]');
            $table->string('gold_primary_intent')->nullable();
            $table->string('gold_risk_level')->nullable();
            $table->json('gold_required_facts')->default('[]');
            $table->json('gold_missing_information')->default('[]');
            $table->string('gold_decision')->nullable();
            $table->string('gold_response_strategy')->nullable();
            $table->json('gold_required_actions')->default('[]');
            $table->json('gold_knowledge_article_ids')->default('[]');
            $table->json('gold_knowledge_gaps')->default('[]');
            $table->json('gold_prohibited_claims')->default('[]');
            $table->foreignId('gold_email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->longText('gold_reply')->nullable();
            $table->string('gold_automation_level')->nullable();
            $table->json('gold_evidence_event_ids')->default('[]');
            $table->string('gold_observed_reply_assessment')->nullable();
            $table->text('gold_notes')->nullable();
            $table->unsignedBigInteger('adjudicated_by')->nullable();
            $table->dateTimeTz('adjudicated_at')->nullable();
            $table->timestamps();

            $table->unique(['benchmark_case_id', 'sequence']);
            $table->index(['benchmark_case_id', 'status']);
        });

        Schema::table('benchmark_annotations', function (Blueprint $table): void {
            $table->dropUnique('benchmark_annotation_author_round_unique');
            $table->foreignId('benchmark_case_step_id')
                ->nullable()
                ->after('benchmark_case_id')
                ->constrained('benchmark_case_steps')
                ->cascadeOnDelete();
            $table->string('lifecycle_stage')->nullable()->after('site_key');
            $table->text('customer_goal')->nullable()->after('lifecycle_stage');
            $table->string('response_strategy')->nullable()->after('decision');
            $table->json('required_actions')->default('[]')->after('response_strategy');
            $table->json('knowledge_article_ids')->default('[]')->after('required_actions');
            $table->json('knowledge_gaps')->default('[]')->after('knowledge_article_ids');
            $table->json('prohibited_claims')->default('[]')->after('knowledge_gaps');
            $table->string('observed_reply_assessment')->nullable()->after('evidence_event_ids');
            $table->unique(
                ['benchmark_case_id', 'benchmark_case_step_id', 'annotator_id', 'annotation_round'],
                'benchmark_step_annotation_author_round_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('benchmark_annotations', function (Blueprint $table): void {
            $table->dropUnique('benchmark_step_annotation_author_round_unique');
            $table->dropConstrainedForeignId('benchmark_case_step_id');
            $table->dropColumn([
                'lifecycle_stage',
                'customer_goal',
                'response_strategy',
                'required_actions',
                'knowledge_article_ids',
                'knowledge_gaps',
                'prohibited_claims',
                'observed_reply_assessment',
            ]);
            $table->unique(
                ['benchmark_case_id', 'annotator_id', 'annotation_round'],
                'benchmark_annotation_author_round_unique',
            );
        });

        Schema::dropIfExists('benchmark_case_steps');
    }
};
