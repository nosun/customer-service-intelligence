<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_question_analysis_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_question_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('model');
            $table->string('prompt_version');
            $table->string('analyzer_version');
            $table->string('input_hash', 64)->index();
            $table->string('prompt_hash', 64);
            $table->string('status')->index();
            $table->json('structured_output')->nullable();
            $table->json('usage')->nullable();
            $table->string('provider_request_id')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTimeTz('started_at');
            $table->dateTimeTz('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_question_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_question_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('primary_category')->index();
            $table->json('secondary_categories')->default('[]');
            $table->text('customer_intent');
            $table->string('question_scope')->index();
            $table->string('answerability')->index();
            $table->string('risk_level')->index();
            $table->json('required_facts')->default('[]');
            $table->json('missing_facts')->default('[]');
            $table->json('response_moves')->default('[]');
            $table->string('reply_pattern')->index();
            $table->text('answer_summary')->nullable();
            $table->json('answer_assessment');
            $table->string('automation_recommendation')->index();
            $table->decimal('confidence', 5, 4);
            $table->text('analysis_summary');
            $table->string('review_status')->default('candidate')->index();
            $table->text('review_notes')->nullable();
            $table->string('analyzer_version');
            $table->string('input_hash', 64)->index();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTimeTz('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_question_strategy_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_question_analysis_id')->nullable()->constrained()->nullOnDelete();
            $table->string('evidence_role')->default('example');
            $table->timestamps();

            $table->unique(
                ['knowledge_article_id', 'product_question_id', 'evidence_role'],
                'product_question_strategy_evidence_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_question_strategy_evidence');
        Schema::dropIfExists('product_question_analyses');
        Schema::dropIfExists('product_question_analysis_runs');
    }
};
