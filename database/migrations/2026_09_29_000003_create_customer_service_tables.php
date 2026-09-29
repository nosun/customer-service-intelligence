<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('provider')->default('gmail');
            $table->string('last_history_id')->nullable();
            $table->timestamps();
        });

        Schema::create('gmail_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->string('gmail_thread_id');
            $table->string('history_id')->nullable();
            $table->text('snippet')->nullable();
            $table->dateTimeTz('first_message_at')->nullable();
            $table->dateTimeTz('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamps();
            $table->unique(['mailbox_id', 'gmail_thread_id']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('gmail_threads')->cascadeOnDelete();
            $table->string('gmail_message_id');
            $table->string('history_id')->nullable();
            $table->string('internal_date_ms')->nullable();
            $table->dateTimeTz('sent_at')->nullable();
            $table->string('direction');
            $table->text('subject')->nullable();
            $table->text('from_address')->nullable();
            $table->text('to_addresses')->nullable();
            $table->text('cc_addresses')->nullable();
            $table->text('message_id_header')->nullable();
            $table->text('in_reply_to')->nullable();
            $table->text('references_header')->nullable();
            $table->text('snippet')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->json('label_ids')->default('[]');
            $table->json('raw_headers')->default('{}');
            $table->unsignedInteger('size_estimate')->nullable();
            $table->timestamps();
            $table->unique(['mailbox_id', 'gmail_message_id']);
            $table->index(['thread_id', 'sent_at']);
        });

        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('gmail_attachment_id')->nullable();
            $table->text('filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->text('query')->nullable();
            $table->string('status');
            $table->unsignedInteger('threads_seen')->default(0);
            $table->unsignedInteger('messages_seen')->default(0);
            $table->text('error_message')->nullable();
            $table->dateTimeTz('started_at');
            $table->dateTimeTz('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('message_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->unsignedInteger('rule_score')->default(0);
            $table->json('matched_rules')->default('[]');
            $table->json('order_candidates')->default('[]');
            $table->string('analyzer_version');
            $table->dateTimeTz('analyzed_at');
            $table->timestamps();
        });

        Schema::create('candidate_reply_pairs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thread_id')->constrained('gmail_threads')->cascadeOnDelete();
            $table->foreignId('customer_message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('agent_message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('category');
            $table->text('customer_excerpt');
            $table->text('agent_reply_excerpt');
            $table->string('review_status')->default('candidate');
            $table->timestamps();
            $table->unique(['customer_message_id', 'agent_message_id']);
        });

        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('external_key')->nullable();
            $table->string('name');
            $table->string('template_type')->nullable();
            $table->string('source')->nullable();
            $table->text('trigger_description')->nullable();
            $table->text('subject')->nullable();
            $table->longText('body');
            $table->boolean('active')->nullable();
            $table->json('raw_row')->default('{}');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('candidate_reply_pairs');
        Schema::dropIfExists('message_analyses');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('gmail_threads');
        Schema::dropIfExists('mailboxes');
    }
};
