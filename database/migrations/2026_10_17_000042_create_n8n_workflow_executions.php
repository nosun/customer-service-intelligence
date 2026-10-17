<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('n8n_workflow_executions', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow_key');
            $table->string('workflow_version');
            $table->string('execution_id');
            $table->foreignId('product_question_id')->constrained()->cascadeOnDelete();
            $table->string('status')->index();
            $table->string('result_type')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTimeTz('started_at');
            $table->dateTimeTz('finished_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['workflow_key', 'workflow_version', 'execution_id', 'product_question_id'],
                'n8n_workflow_execution_item_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('n8n_workflow_executions');
    }
};
