<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_question_draft_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('product_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_question_draft_id')->nullable()->constrained()->nullOnDelete();
            $table->string('workflow_key');
            $table->string('workflow_version');
            $table->string('status')->index();
            $table->string('n8n_execution_id')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->dateTimeTz('started_at')->nullable();
            $table->dateTimeTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['product_question_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_question_draft_requests');
    }
};
