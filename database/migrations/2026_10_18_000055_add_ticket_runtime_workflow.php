<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WORKFLOW_KEY = 'work/ticket-draft-v1';

    public function up(): void
    {
        Schema::table('conversation_drafts', function (Blueprint $table): void {
            $table->foreignId('work_item_id')->nullable()->after('conversation_id')
                ->constrained()->nullOnDelete();
            $table->json('metadata')->nullable()->after('source');
            $table->boolean('requires_human_review')->default(true)->after('metadata');
            $table->unique('work_item_id');
        });

        DB::table('ai_workflows')->updateOrInsert(
            [
                'workflow_key' => self::WORKFLOW_KEY,
                'workflow_version' => '1.0.0',
            ],
            [
                'center' => 'work',
                'domain' => 'tickets',
                'input_schema_version' => '1.0.0',
                'output_schema_version' => '1.0.0',
                'prompt_version' => 'n8n-ticket-draft-v1',
                'status' => 'evaluating',
                'allowed_fact_keys' => json_encode([
                    'order.found',
                    'order.identity_match',
                    'order.status',
                    'order.shipping_status',
                    'shipment.carrier',
                    'shipment.tracking_available',
                    'shipment.logistics_status',
                    'shipment.shipped_at',
                    'shipment.delivered_at',
                    'production.statuses',
                ]),
                'allowed_actions' => '[]',
                'published_at' => null,
                'deprecated_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('ai_workflows')
            ->where('workflow_key', self::WORKFLOW_KEY)
            ->where('workflow_version', '1.0.0')
            ->delete();

        Schema::table('conversation_drafts', function (Blueprint $table): void {
            $table->dropUnique(['work_item_id']);
            $table->dropConstrainedForeignId('work_item_id');
            $table->dropColumn(['metadata', 'requires_human_review']);
        });
    }
};
