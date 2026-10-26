<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_questions', function (Blueprint $table): void {
            $table->text('draft_guidance')->nullable();
            $table->unsignedBigInteger('draft_guidance_updated_by')->nullable();
            $table->dateTimeTz('draft_guidance_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_questions', function (Blueprint $table): void {
            $table->dropColumn(['draft_guidance', 'draft_guidance_updated_by', 'draft_guidance_updated_at']);
        });
    }
};
