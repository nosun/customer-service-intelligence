<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_question_analyses', function (Blueprint $table): void {
            $table->string('intent_key')->nullable()->after('secondary_categories')->index();
            $table->string('answer_source')->nullable()->after('question_scope')->index();
            $table->string('work_queue')->nullable()->after('answer_source')->index();
            $table->string('factory_capability_key')->nullable()->after('missing_facts')->index();
            $table->text('factory_question')->nullable()->after('factory_capability_key');
        });
    }

    public function down(): void
    {
        Schema::table('product_question_analyses', function (Blueprint $table): void {
            $table->dropIndex(['intent_key']);
            $table->dropIndex(['answer_source']);
            $table->dropIndex(['work_queue']);
            $table->dropIndex(['factory_capability_key']);
            $table->dropColumn([
                'intent_key',
                'answer_source',
                'work_queue',
                'factory_capability_key',
                'factory_question',
            ]);
        });
    }
};
