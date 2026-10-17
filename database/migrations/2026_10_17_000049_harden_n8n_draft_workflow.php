<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_workflows', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow_key');
            $table->string('workflow_version');
            $table->string('center')->default('work');
            $table->string('domain')->default('product_questions');
            $table->string('n8n_workflow_id')->nullable();
            $table->string('input_schema_version')->default('1.0.0');
            $table->string('output_schema_version')->default('1.0.0');
            $table->string('prompt_version')->nullable();
            $table->string('definition_checksum', 64)->nullable();
            $table->string('status')->default('experimental')->index();
            $table->json('allowed_fact_keys')->default('[]');
            $table->json('allowed_actions')->default('[]');
            $table->json('replay_metrics')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->dateTimeTz('published_at')->nullable();
            $table->dateTimeTz('deprecated_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow_key', 'workflow_version']);
        });

        Schema::table('product_question_draft_requests', function (Blueprint $table): void {
            $table->string('input_hash', 64)->nullable()->after('workflow_version')->index();
            $table->json('input_snapshot')->nullable()->after('input_hash');
            $table->boolean('force_regeneration')->default(false)->after('input_snapshot');
            $table->foreignId('reused_from_request_id')->nullable()->after('force_regeneration')
                ->constrained('product_question_draft_requests')->nullOnDelete();
            $table->unsignedSmallInteger('attempt_count')->default(0)->after('reused_from_request_id');
        });

        Schema::create('product_question_draft_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_question_draft_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('previewed')->index();
            $table->longText('original_answer')->nullable();
            $table->longText('final_answer');
            $table->boolean('final_visible');
            $table->json('text_diff')->nullable();
            $table->decimal('similarity_percent', 5, 2)->nullable();
            $table->text('review_reason')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTimeTz('previewed_at');
            $table->dateTimeTz('confirmed_at')->nullable();
            $table->timestamps();
        });

        DB::table('ai_workflows')->insert([
            'workflow_key' => 'work/qa-draft-agent-v1',
            'workflow_version' => '1.0.0',
            'center' => 'work',
            'domain' => 'product_questions',
            'input_schema_version' => '1.0.0',
            'output_schema_version' => '1.0.0',
            'prompt_version' => 'n8n-qa-draft-agent-v3',
            'status' => 'active',
            'allowed_fact_keys' => '[]',
            'allowed_actions' => '[]',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_question_draft_feedback');
        Schema::table('product_question_draft_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reused_from_request_id');
            $table->dropColumn(['input_hash', 'input_snapshot', 'force_regeneration', 'attempt_count']);
        });
        Schema::dropIfExists('ai_workflows');
    }
};
