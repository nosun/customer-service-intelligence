<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('primary_category');
            $table->string('primary_problem_type')->nullable();
            $table->string('urgency')->default('normal');
            $table->unsignedInteger('customer_message_count')->default(0);
            $table->unsignedInteger('agent_message_count')->default(0);
            $table->unsignedInteger('analyzed_message_count')->default(0);
            $table->unsignedInteger('unknown_message_count')->default(0);
            $table->unsignedInteger('reply_candidate_count')->default(0);
            $table->json('category_counts')->default('{}');
            $table->json('problem_type_counts')->default('{}');
            $table->boolean('needs_review')->default(false)->index();
            $table->string('analyzer_version');
            $table->dateTimeTz('analyzed_at');
            $table->timestamps();
            $table->index(['primary_category', 'primary_problem_type']);
        });

        Schema::create('conversation_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('label_type');
            $table->string('label');
            $table->unsignedInteger('evidence_count')->default(1);
            $table->string('source')->default('rule');
            $table->string('analyzer_version');
            $table->timestamps();
            $table->unique(['conversation_id', 'label_type', 'label'], 'conversation_label_unique');
            $table->index(['label_type', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_labels');
        Schema::dropIfExists('conversation_analyses');
    }
};
