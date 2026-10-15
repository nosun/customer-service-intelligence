<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_sites', function (Blueprint $table): void {
            $table->string('product_question_sync_status')->nullable()->after('enabled');
            $table->text('product_question_sync_error')->nullable()->after('product_question_sync_status');
            $table->dateTimeTz('product_questions_last_synced_at')->nullable()->after('product_question_sync_error');
        });

        Schema::create('product_question_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commerce_site_id')->constrained()->cascadeOnDelete();
            $table->uuid('sync_token')->unique();
            $table->string('status')->index();
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('reported_total')->default(0);
            $table->unsignedInteger('records_seen')->default(0);
            $table->unsignedInteger('records_saved')->default(0);
            $table->unsignedInteger('missing_marked')->default(0);
            $table->text('error_message')->nullable();
            $table->dateTimeTz('started_at');
            $table->dateTimeTz('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commerce_site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('external_id');
            $table->string('sn')->index();
            $table->json('product')->nullable();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->string('name')->default('');
            $table->string('email')->default('')->index();
            $table->unsignedTinyInteger('type')->default(0)->index();
            $table->string('type_text')->default('');
            $table->text('question');
            $table->longText('answer')->nullable();
            $table->string('reply_status')->index();
            $table->boolean('needs_reply')->default(false)->index();
            $table->boolean('visible')->default(false)->index();
            $table->unsignedTinyInteger('notice_status')->default(0)->index();
            $table->string('notice_status_text')->default('');
            $table->json('images')->default('[]');
            $table->integer('weight')->default(0);
            $table->unsignedInteger('helpful')->default(0);
            $table->dateTimeTz('remote_created_at')->nullable();
            $table->dateTimeTz('remote_updated_at')->nullable()->index();
            $table->json('raw_payload');
            $table->boolean('remote_present')->default(true)->index();
            $table->uuid('last_seen_sync_token')->nullable()->index();
            $table->dateTimeTz('synced_at');
            $table->string('review_status')->default('unreviewed')->index();
            $table->text('analysis_notes')->nullable();
            $table->json('tags')->default('[]');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTimeTz('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['commerce_site_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_questions');
        Schema::dropIfExists('product_question_sync_runs');

        Schema::table('commerce_sites', function (Blueprint $table): void {
            $table->dropColumn([
                'product_question_sync_status',
                'product_question_sync_error',
                'product_questions_last_synced_at',
            ]);
        });
    }
};
