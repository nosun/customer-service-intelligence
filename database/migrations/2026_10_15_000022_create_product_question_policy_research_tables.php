<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_question_policy_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commerce_site_id')->constrained()->cascadeOnDelete();
            $table->string('batch_key')->unique();
            $table->dateTimeTz('window_start');
            $table->dateTimeTz('window_end');
            $table->unsignedInteger('sample_limit');
            $table->string('selection_seed');
            $table->string('source_assumption')->nullable();
            $table->string('status')->default('running')->index();
            $table->unsignedInteger('selected_count')->default(0);
            $table->unsignedInteger('analyzed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('candidate_count')->default(0);
            $table->string('provider');
            $table->string('model');
            $table->string('prompt_version');
            $table->json('usage')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTimeTz('started_at');
            $table->dateTimeTz('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_question_policy_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_question_policy_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_site_id')->constrained()->cascadeOnDelete();
            $table->string('scenario_key')->index();
            $table->string('category')->index();
            $table->string('title');
            $table->text('definition');
            $table->text('applicability');
            $table->json('required_facts')->default('[]');
            $table->json('policy_rules')->default('[]');
            $table->json('decision_tree')->default('[]');
            $table->json('reply_strategy')->default('[]');
            $table->json('reply_templates')->default('[]');
            $table->json('action_steps')->default('[]');
            $table->json('prohibited_claims')->default('[]');
            $table->json('escalation_rules')->default('[]');
            $table->json('evidence_question_ids');
            $table->json('conflicts')->default('[]');
            $table->json('open_questions')->default('[]');
            $table->decimal('confidence', 5, 4);
            $table->string('review_status')->default('candidate')->index();
            $table->text('review_notes')->nullable();
            $table->foreignId('knowledge_article_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTimeTz('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['product_question_policy_run_id', 'scenario_key'],
                'product_question_policy_run_scenario_unique',
            );
        });

        $now = now();
        DB::table(config('admin.database.menu_table'))->updateOrInsert(
            ['uri' => 'product-question-policies'],
            [
                'parent_id' => 0,
                'order' => 5,
                'title' => '问答策略',
                'icon' => 'fa-sitemap',
                'permission' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table(config('admin.database.menu_table'))->where('uri', 'product-question-policies')->delete();
        Schema::dropIfExists('product_question_policy_candidates');
        Schema::dropIfExists('product_question_policy_runs');
    }
};
