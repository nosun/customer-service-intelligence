<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('channel');
            $table->string('site_key')->nullable();
            $table->string('external_account_id');
            $table->string('display_name')->nullable();
            $table->string('credential_reference')->nullable();
            $table->string('sync_status')->nullable();
            $table->text('sync_error')->nullable();
            $table->dateTimeTz('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'external_account_id']);
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('external_short_id')->nullable();
            $table->text('subject')->nullable();
            $table->string('status')->nullable()->index();
            $table->integer('priority')->nullable();
            $table->string('silo')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_detail')->nullable();
            $table->string('requester_email', 320)->nullable()->index();
            $table->json('requester_snapshot')->nullable();
            $table->json('assignment_snapshot')->nullable();
            $table->dateTimeTz('created_at_external')->nullable();
            $table->dateTimeTz('updated_at_external')->nullable()->index();
            $table->dateTimeTz('last_message_at')->nullable()->index();
            $table->json('raw_payload');
            $table->timestamps();
            $table->unique(['channel_account_id', 'external_id']);
        });

        Schema::create('conversation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('event_type')->index();
            $table->dateTimeTz('occurred_at')->nullable();
            $table->string('author_type')->nullable()->index();
            $table->string('external_author_id')->nullable();
            $table->text('author_name')->nullable();
            $table->text('author_email')->nullable();
            $table->boolean('is_private')->default(false);
            $table->longText('body_text')->nullable();
            $table->longText('rich_text_html')->nullable();
            $table->json('rich_text_payload')->nullable();
            $table->string('status_value')->nullable();
            $table->json('assignment_snapshot')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->char('content_hash', 64);
            $table->json('raw_payload');
            $table->timestamps();
            $table->unique(['conversation_id', 'external_id']);
            $table->index(['conversation_id', 'occurred_at']);
        });

        Schema::create('conversation_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_event_id')->constrained()->cascadeOnDelete();
            $table->string('external_key');
            $table->string('external_token')->nullable();
            $table->text('filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('content_id')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->text('remote_url')->nullable();
            $table->text('local_path')->nullable();
            $table->string('download_status')->default('remote');
            $table->json('raw_payload');
            $table->timestamps();
            $table->unique(['conversation_event_id', 'external_key']);
        });

        Schema::create('channel_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_account_id')->constrained()->cascadeOnDelete();
            $table->string('source_resource');
            $table->string('cursor_value')->nullable();
            $table->string('cursor_external_id')->nullable();
            $table->json('filter_snapshot')->nullable();
            $table->string('status');
            $table->unsignedInteger('records_seen')->default(0);
            $table->unsignedInteger('records_saved')->default(0);
            $table->text('error_message')->nullable();
            $table->dateTimeTz('started_at');
            $table->dateTimeTz('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_sync_runs');
        Schema::dropIfExists('conversation_attachments');
        Schema::dropIfExists('conversation_events');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('channel_accounts');
    }
};
