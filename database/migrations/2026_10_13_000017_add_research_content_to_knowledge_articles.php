<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_articles', function (Blueprint $table): void {
            $table->json('decision_tree')->default('[]')->after('policy_rules');
            $table->json('reply_strategy')->default('[]')->after('decision_tree');
            $table->json('reply_templates')->default('[]')->after('reply_strategy');
            $table->foreignId('source_finding_id')
                ->nullable()
                ->after('source_notes')
                ->constrained('knowledge_research_findings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_articles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_finding_id');
            $table->dropColumn(['decision_tree', 'reply_strategy', 'reply_templates']);
        });
    }
};
