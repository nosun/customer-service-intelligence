<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_question_factory_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commerce_site_id')->constrained()->cascadeOnDelete();
            $table->string('capability_key')->index();
            $table->string('title');
            $table->unsignedInteger('question_count')->default(0);
            $table->unsignedInteger('product_count')->default(0);
            $table->decimal('confidence', 5, 4);
            $table->json('evidence_question_ids');
            $table->json('product_sns')->default('[]');
            $table->json('representative_questions')->default('[]');
            $table->text('factory_question');
            $table->string('review_status')->default('pending')->index();
            $table->text('applicability')->nullable();
            $table->text('exceptions')->nullable();
            $table->string('fee_policy')->nullable();
            $table->string('lead_time_policy')->nullable();
            $table->text('factory_answer')->nullable();
            $table->text('review_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTimeTz('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['commerce_site_id', 'capability_key'], 'factory_capability_site_key_unique');
        });

        DB::table(config('admin.database.menu_table'))->updateOrInsert(
            ['uri' => 'product-question-factory-capabilities'],
            [
                'parent_id' => 0,
                'order' => 35,
                'title' => '问答工厂能力',
                'icon' => 'fa-industry',
                'permission' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table(config('admin.database.menu_table'))->where('uri', 'product-question-factory-capabilities')->delete();
        Schema::dropIfExists('product_question_factory_capabilities');
    }
};
