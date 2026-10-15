<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decision_cases', function (Blueprint $table): void {
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('review_status');
            $table->text('review_notes')->nullable()->after('reviewed_by');
            $table->index(['review_status', 'case_risk', 'extracted_at'], 'decision_case_review_queue_index');
        });
        Schema::table('decision_nodes', function (Blueprint $table): void {
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('review_status');
            $table->text('review_notes')->nullable()->after('reviewed_by');
            $table->dateTimeTz('reviewed_at')->nullable()->after('review_notes');
        });
        Schema::table('conversations', function (Blueprint $table): void {
            $table->index(['silo', 'status', 'updated_at_external'], 'conversation_silo_status_updated_index');
            $table->index(['site_key', 'status', 'updated_at_external'], 'conversation_site_status_updated_index');
        });
        Schema::create('conversation_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->longText('body')->nullable();
            $table->boolean('is_private')->default(false);
            $table->string('status_after_send')->nullable();
            $table->string('source')->default('manual');
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->dateTimeTz('sent_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_drafts');
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversation_silo_status_updated_index');
            $table->dropIndex('conversation_site_status_updated_index');
        });
        Schema::table('decision_nodes', function (Blueprint $table): void {
            $table->dropColumn(['reviewed_by', 'review_notes', 'reviewed_at']);
        });
        Schema::table('decision_cases', function (Blueprint $table): void {
            $table->dropIndex('decision_case_review_queue_index');
            $table->dropColumn(['reviewed_by', 'review_notes']);
        });
    }
};
