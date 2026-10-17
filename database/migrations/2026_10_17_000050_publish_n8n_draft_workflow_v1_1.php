<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const WORKFLOW_KEY = 'work/qa-draft-agent-v1';

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('ai_workflows')
                ->where('workflow_key', self::WORKFLOW_KEY)
                ->where('workflow_version', '1.0.0')
                ->update([
                    'status' => 'deprecated',
                    'deprecated_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('ai_workflows')->updateOrInsert(
                [
                    'workflow_key' => self::WORKFLOW_KEY,
                    'workflow_version' => '1.1.0',
                ],
                [
                    'center' => 'work',
                    'domain' => 'product_questions',
                    'input_schema_version' => '1.0.0',
                    'output_schema_version' => '1.0.0',
                    'prompt_version' => 'n8n-qa-draft-agent-v3',
                    'status' => 'active',
                    'allowed_fact_keys' => '[]',
                    'allowed_actions' => '[]',
                    'published_at' => now(),
                    'deprecated_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('ai_workflows')
                ->where('workflow_key', self::WORKFLOW_KEY)
                ->where('workflow_version', '1.1.0')
                ->delete();

            DB::table('ai_workflows')
                ->where('workflow_key', self::WORKFLOW_KEY)
                ->where('workflow_version', '1.0.0')
                ->update([
                    'status' => 'active',
                    'published_at' => now(),
                    'deprecated_at' => null,
                    'updated_at' => now(),
                ]);
        });
    }
};
