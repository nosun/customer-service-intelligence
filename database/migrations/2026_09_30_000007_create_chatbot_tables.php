<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_stories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->text('name')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('current_version')->nullable();
            $table->boolean('published')->default(false);
            $table->json('integrations')->default('[]');
            $table->dateTimeTz('fetched_at');
            $table->timestamps();
            $table->unique(['channel_account_id', 'external_id']);
        });

        Schema::create('chatbot_story_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chatbot_story_id')->constrained()->cascadeOnDelete();
            $table->char('content_hash', 64);
            $table->unsignedInteger('version')->nullable();
            $table->boolean('published')->default(false);
            $table->json('settings')->nullable();
            $table->json('state')->nullable();
            $table->json('history')->nullable();
            $table->json('diagram')->nullable();
            $table->json('raw_payload');
            $table->dateTimeTz('captured_at');
            $table->timestamps();
            $table->unique(['chatbot_story_id', 'content_hash'], 'chatbot_story_snapshot_hash_unique');
        });

        Schema::create('chatbot_chats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatbot_story_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id');
            $table->string('external_story_id')->nullable()->index();
            $table->string('external_user_id')->nullable()->index();
            $table->json('user_snapshot')->nullable();
            $table->string('source')->nullable()->index();
            $table->json('attributes')->nullable();
            $table->dateTimeTz('started_at')->nullable()->index();
            $table->dateTimeTz('updated_at_external')->nullable()->index();
            $table->unsignedInteger('message_count')->default(0);
            $table->string('retention_status')->default('unreviewed')->index();
            $table->text('retention_reason')->nullable();
            $table->dateTimeTz('purge_after')->nullable()->index();
            $table->json('raw_payload');
            $table->dateTimeTz('synced_at');
            $table->timestamps();
            $table->unique(['channel_account_id', 'external_id']);
        });

        Schema::create('chatbot_chat_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chatbot_chat_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('request_id')->nullable();
            $table->dateTimeTz('occurred_at')->nullable()->index();
            $table->string('author_type')->nullable()->index();
            $table->string('event_type')->nullable();
            $table->longText('body_text')->nullable();
            $table->decimal('score', 8, 4)->nullable();
            $table->char('content_hash', 64);
            $table->json('raw_payload');
            $table->timestamps();
            $table->unique(['chatbot_chat_id', 'external_id']);
        });

        Schema::create('chatbot_ticket_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chatbot_chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('match_method');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('evidence')->default('[]');
            $table->string('review_status')->default('candidate')->index();
            $table->dateTimeTz('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['chatbot_chat_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_ticket_links');
        Schema::dropIfExists('chatbot_chat_events');
        Schema::dropIfExists('chatbot_chats');
        Schema::dropIfExists('chatbot_story_snapshots');
        Schema::dropIfExists('chatbot_stories');
    }
};
