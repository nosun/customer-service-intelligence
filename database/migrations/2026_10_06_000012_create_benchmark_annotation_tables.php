<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('dataset_version');
            $table->string('split')->default('pilot');
            $table->string('sampling_bucket');
            $table->string('source_hash', 64);
            $table->string('status')->default('pending');
            $table->string('gold_site_key')->nullable();
            $table->json('gold_intents')->default('[]');
            $table->string('gold_primary_intent')->nullable();
            $table->string('gold_risk_level')->nullable();
            $table->json('gold_required_facts')->default('[]');
            $table->json('gold_missing_information')->default('[]');
            $table->string('gold_decision')->nullable();
            $table->foreignId('gold_email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->longText('gold_reply')->nullable();
            $table->string('gold_automation_level')->nullable();
            $table->json('gold_evidence_event_ids')->default('[]');
            $table->text('gold_notes')->nullable();
            $table->unsignedBigInteger('adjudicated_by')->nullable();
            $table->dateTimeTz('selected_at');
            $table->dateTimeTz('adjudicated_at')->nullable();
            $table->timestamps();

            $table->unique(['dataset_version', 'conversation_id']);
            $table->index(['dataset_version', 'split', 'status']);
            $table->index(['sampling_bucket', 'status']);
        });

        Schema::create('benchmark_annotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('benchmark_case_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('annotator_id');
            $table->unsignedInteger('annotation_round')->default(1);
            $table->string('site_key');
            $table->json('intents');
            $table->string('primary_intent');
            $table->string('risk_level');
            $table->json('required_facts')->default('[]');
            $table->json('missing_information')->default('[]');
            $table->string('decision');
            $table->foreignId('email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->longText('ideal_reply')->nullable();
            $table->string('automation_level');
            $table->json('evidence_event_ids');
            $table->text('notes')->nullable();
            $table->dateTimeTz('submitted_at');
            $table->timestamps();

            $table->unique(['benchmark_case_id', 'annotator_id', 'annotation_round'], 'benchmark_annotation_author_round_unique');
            $table->index(['annotator_id', 'submitted_at']);
        });

        DB::table(config('admin.database.menu_table'))->updateOrInsert(
            ['uri' => 'benchmark-cases'],
            [
                'parent_id' => 0,
                'order' => 4,
                'title' => '基准集标注',
                'icon' => 'fa-check-square-o',
                'permission' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table(config('admin.database.menu_table'))->where('uri', 'benchmark-cases')->delete();
        Schema::dropIfExists('benchmark_annotations');
        Schema::dropIfExists('benchmark_cases');
    }
};
