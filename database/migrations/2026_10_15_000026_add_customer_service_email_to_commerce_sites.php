<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_sites', function (Blueprint $table): void {
            $table->string('customer_service_email')->nullable()->after('public_base_url');
        });

        DB::table('commerce_sites')
            ->where('site_key', 'dorriswedding')
            ->whereNull('customer_service_email')
            ->update(['customer_service_email' => 'support@dorriswedding.com']);

        $this->normalizePolicyEmails('product_question_policy_candidates', [
            'required_facts', 'policy_rules', 'decision_tree', 'reply_strategy', 'reply_templates',
            'action_steps', 'prohibited_claims', 'escalation_rules', 'conflicts', 'open_questions',
        ]);
        $this->normalizePolicyEmails('knowledge_articles', [
            'required_facts', 'policy_rules', 'decision_tree', 'reply_strategy', 'reply_templates',
            'action_steps', 'prohibited_claims', 'escalation_rules',
        ]);
    }

    public function down(): void
    {
        Schema::table('commerce_sites', function (Blueprint $table): void {
            $table->dropColumn('customer_service_email');
        });
    }

    /** @param list<string> $fields */
    private function normalizePolicyEmails(string $table, array $fields): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($fields as $field) {
            if (! Schema::hasColumn($table, $field)) {
                continue;
            }
            DB::table($table)
                ->where($field, 'like', '%support@dorriswedding.com%')
                ->update([
                    $field => DB::raw("REPLACE({$field}, 'support@dorriswedding.com', '{{ site.customer_service_email }}')"),
                ]);
        }
    }
};
