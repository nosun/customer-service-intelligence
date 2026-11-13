<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_contact_rounds', function (Blueprint $t): void {
            $t->id();
            $t->uuid('round_key')->unique();
            $t->foreignId('customer_case_id')->constrained()->cascadeOnDelete();
            $t->string('console_key');
            $t->string('site_key');
            $t->unsignedBigInteger('sid');
            $t->unsignedBigInteger('oid');
            $t->string('order_number')->index();
            $t->string('round_kind')->default('first_round');
            foreach (['contact_ids', 'trigger_snapshot', 'source_snapshot', 'communication_checkpoint', 'assessment'] as $field) {
                $t->json($field)->nullable();
            }
            $t->char('source_hash', 64);
            $t->string('policy_version')->nullable();
            $t->string('template_version')->nullable();
            $t->string('approved_mail_ref')->nullable();
            foreach (['approved_input_hash', 'approved_content_hash', 'review_hash'] as $field) {
                $t->char($field, 64)->nullable();
            }
            $t->foreignId('work_item_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('follow_up_id')->nullable()->constrained()->nullOnDelete();
            $t->string('communication_state')->default('new')->index();
            $t->string('waiting_state')->default('not_started')->index();
            $t->string('note_state')->default('not_due');
            foreach (['sent_at', 'wait_until', 'human_hold_at'] as $field) {
                $t->dateTimeTz($field)->nullable()->index();
            }
            $t->string('human_hold_reason')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['console_key', 'sid', 'oid', 'round_kind'], 'contact_round_identity');
        });
        Schema::create('customer_contact_effects', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('round_id')->constrained('customer_contact_rounds')->cascadeOnDelete();
            $t->string('effect_kind');
            $t->string('state')->default('prepared')->index();
            $t->char('payload_hash', 64);
            $t->json('payload');
            $t->uuid('idempotency_key')->unique();
            $t->string('operation_id')->nullable();
            $t->json('preview')->nullable();
            $t->unsignedBigInteger('authorized_by')->nullable();
            $t->char('authorized_payload_hash', 64)->nullable();
            $t->dateTimeTz('authorized_at')->nullable();
            $t->dateTimeTz('expires_at')->nullable();
            $t->json('receipt')->nullable();
            $t->string('error_code')->nullable();
            $t->dateTimeTz('attempt_started_at')->nullable();
            $t->timestamps();
            $t->unique(['round_id', 'effect_kind'], 'contact_round_effect');
        });
        Schema::create('customer_contact_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('round_id')->constrained('customer_contact_rounds')->cascadeOnDelete();
            $t->foreignId('effect_id')->nullable()->constrained('customer_contact_effects')->nullOnDelete();
            $t->string('event_key')->unique();
            $t->string('event_type')->index();
            $t->string('actor');
            $t->dateTimeTz('occurred_at');
            $t->json('evidence')->nullable();
            $t->timestamps();
        });
        DB::table(config('admin.database.menu_table'))->updateOrInsert(['uri' => 'multi-dress-rounds'],
            ['parent_id' => 0, 'order' => 3, 'title' => '多件确认首轮', 'icon' => 'fa-envelope-o', 'permission' => null,
                'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table(config('admin.database.menu_table'))->where('uri', 'multi-dress-rounds')->delete();
        Schema::dropIfExists('customer_contact_events');
        Schema::dropIfExists('customer_contact_effects');
        Schema::dropIfExists('customer_contact_rounds');
    }
};
