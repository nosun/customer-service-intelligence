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
            $table->string('brand_name')->nullable()->after('name');
        });

        DB::table('commerce_sites')->where('site_key', 'dorriswedding')->update(['brand_name' => 'Dorris Wedding']);
        DB::table('commerce_sites')->where('site_key', 'dressafford')->update(['brand_name' => 'Dress Afford']);
        DB::table('commerce_sites')->whereNull('brand_name')->update(['brand_name' => DB::raw('name')]);

        $this->normalizePolicyBrands('product_question_policy_candidates', [
            'required_facts', 'policy_rules', 'decision_tree', 'reply_strategy', 'reply_templates',
            'action_steps', 'prohibited_claims', 'escalation_rules', 'conflicts', 'open_questions',
        ]);
        $this->normalizePolicyBrands('knowledge_articles', [
            'required_facts', 'policy_rules', 'decision_tree', 'reply_strategy', 'reply_templates',
            'action_steps', 'prohibited_claims', 'escalation_rules',
        ]);
    }

    public function down(): void
    {
        Schema::table('commerce_sites', function (Blueprint $table): void {
            $table->dropColumn('brand_name');
        });
    }

    /** @param list<string> $fields */
    private function normalizePolicyBrands(string $table, array $fields): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($fields as $field) {
            if (! Schema::hasColumn($table, $field)) {
                continue;
            }
            DB::table($table)
                ->where($field, 'like', '%Dorris Wedding%')
                ->update([
                    $field => DB::raw("REPLACE({$field}, 'Dorris Wedding', '{{ site.brand_name }}')"),
                ]);
        }
    }
};
