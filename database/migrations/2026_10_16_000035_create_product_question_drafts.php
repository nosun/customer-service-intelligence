<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_question_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_question_id')->constrained()->cascadeOnDelete();
            $table->string('status')->index();
            $table->string('provider')->default('minimax');
            $table->string('model');
            $table->string('prompt_version');
            $table->json('intents')->default('[]');
            $table->json('knowledge_article_ids')->default('[]');
            $table->json('facts')->default('[]');
            $table->json('fact_sources')->default('[]');
            $table->json('missing_facts')->default('[]');
            $table->json('safety_flags')->default('[]');
            $table->string('customer_language')->nullable();
            $table->longText('answer_en')->nullable();
            $table->longText('answer_localized')->nullable();
            $table->boolean('recommended_visible')->default(true);
            $table->boolean('requires_human_review')->default(true);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('input_hash');
            $table->string('prompt_hash')->nullable();
            $table->json('usage')->nullable();
            $table->string('provider_request_id')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTimeTz('generated_at')->nullable();
            $table->timestamps();

            $table->index(['product_question_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_question_drafts');
    }
};
