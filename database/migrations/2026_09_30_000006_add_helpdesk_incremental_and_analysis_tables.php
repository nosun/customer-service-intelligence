<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('site_key')->nullable()->after('channel_account_id')->index();
        });

        Schema::create('channel_sync_cursors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_account_id')->constrained()->cascadeOnDelete();
            $table->string('source_resource');
            $table->string('silo');
            $table->string('status');
            $table->string('sort_by')->default('updatedAt');
            $table->string('cursor_value');
            $table->string('cursor_external_id');
            $table->dateTimeTz('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['channel_account_id', 'source_resource', 'silo', 'status'],
                'channel_sync_cursor_scope_unique',
            );
        });

        Schema::create('conversation_event_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_event_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('problem_type')->nullable();
            $table->unsignedInteger('rule_score')->default(0);
            $table->string('urgency')->default('normal');
            $table->boolean('is_noise')->default(false);
            $table->string('noise_type')->nullable();
            $table->json('matched_rules')->default('[]');
            $table->json('order_candidates')->default('[]');
            $table->string('analyzer_version');
            $table->dateTimeTz('analyzed_at');
            $table->timestamps();
            $table->index(['category', 'problem_type']);
        });

        Schema::create('conversation_reply_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_event_id')->constrained('conversation_events')->cascadeOnDelete();
            $table->foreignId('agent_event_id')->constrained('conversation_events')->cascadeOnDelete();
            $table->string('category');
            $table->text('customer_excerpt');
            $table->text('agent_reply_excerpt');
            $table->string('review_status')->default('candidate');
            $table->timestamps();
            $table->unique(['customer_event_id', 'agent_event_id'], 'conversation_reply_event_pair_unique');
            $table->index(['conversation_id', 'review_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_reply_candidates');
        Schema::dropIfExists('conversation_event_analyses');
        Schema::dropIfExists('channel_sync_cursors');
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('site_key');
        });
    }
};
