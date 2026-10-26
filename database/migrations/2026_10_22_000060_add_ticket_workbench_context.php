<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->text('draft_guidance')->nullable();
            $table->unsignedBigInteger('draft_guidance_updated_by')->nullable();
            $table->timestamp('draft_guidance_updated_at')->nullable();
            $table->string('linked_order_number')->nullable()->index();
            $table->unsignedBigInteger('order_linked_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['linked_order_number']);
            $table->dropColumn(['draft_guidance', 'draft_guidance_updated_by', 'draft_guidance_updated_at', 'linked_order_number', 'order_linked_by']);
        });
    }
};
