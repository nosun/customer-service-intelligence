<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('case_key')->unique();
            $table->string('site_key')->nullable()->index();
            $table->string('case_type')->index();
            $table->string('title');
            $table->string('status')->default('open')->index();
            $table->string('risk_level')->default('normal')->index();
            $table->string('priority')->default('normal')->index();
            $table->string('queue')->nullable()->index();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->dateTimeTz('sla_due_at')->nullable()->index();
            $table->dateTimeTz('first_response_at')->nullable();
            $table->string('outcome_type')->nullable()->index();
            $table->json('outcome')->nullable();
            $table->dateTimeTz('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_case_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_case_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->string('source_id');
            $table->char('customer_identity_hash', 64)->nullable()->index();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id'], 'customer_case_source_unique');
        });

        Schema::create('work_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('work_item_key')->unique();
            $table->foreignId('customer_case_id')->constrained()->cascadeOnDelete();
            $table->string('work_type')->index();
            $table->string('status')->default('queued')->index();
            $table->string('workflow_key')->nullable();
            $table->string('workflow_version')->nullable();
            $table->string('input_schema_version')->default('1.0.0');
            $table->string('output_schema_version')->default('1.0.0');
            $table->char('input_hash', 64)->nullable()->index();
            $table->json('input_snapshot')->nullable();
            $table->string('lease_owner')->nullable()->index();
            $table->dateTimeTz('lease_expires_at')->nullable()->index();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->string('execution_id')->nullable()->index();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTimeTz('deadline_at')->nullable()->index();
            $table->dateTimeTz('started_at')->nullable();
            $table->dateTimeTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['customer_case_id', 'status']);
        });

        Schema::create('action_proposals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('proposal_key')->unique();
            $table->foreignId('customer_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action_type')->index();
            $table->string('risk_level')->default('high')->index();
            $table->string('status')->default('proposed')->index();
            $table->json('parameters');
            $table->json('evidence')->nullable();
            $table->char('idempotency_key', 64)->unique();
            $table->unsignedBigInteger('proposed_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('review_notes')->nullable();
            $table->dateTimeTz('reviewed_at')->nullable();
            $table->json('execution_result')->nullable();
            $table->dateTimeTz('executed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_case_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_case_id')->constrained()->cascadeOnDelete();
            $table->string('from_queue')->nullable();
            $table->unsignedBigInteger('from_owner_id')->nullable();
            $table->string('to_queue')->nullable();
            $table->unsignedBigInteger('to_owner_id')->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::table('product_question_draft_requests', function (Blueprint $table): void {
            $table->foreignId('work_item_id')->nullable()->after('product_question_id')
                ->constrained()->nullOnDelete();
        });

        $now = now();
        DB::table(config('admin.database.menu_table'))->updateOrInsert(
            ['uri' => 'customer-cases'],
            [
                'parent_id' => 0,
                'order' => 2,
                'title' => '客服 Case',
                'icon' => 'fa-briefcase',
                'permission' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table(config('admin.database.menu_table'))->where('uri', 'customer-cases')->delete();
        Schema::table('product_question_draft_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_item_id');
        });
        Schema::dropIfExists('customer_case_handoffs');
        Schema::dropIfExists('action_proposals');
        Schema::dropIfExists('work_items');
        Schema::dropIfExists('customer_case_sources');
        Schema::dropIfExists('customer_cases');
    }
};
