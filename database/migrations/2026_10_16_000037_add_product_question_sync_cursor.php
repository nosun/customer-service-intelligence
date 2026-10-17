<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_sites', function (Blueprint $table): void {
            $table->dateTimeTz('product_questions_cursor_updated_at')
                ->nullable()
                ->after('product_questions_last_synced_at');
            $table->unsignedBigInteger('product_questions_cursor_id')
                ->nullable()
                ->after('product_questions_cursor_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('commerce_sites', function (Blueprint $table): void {
            $table->dropColumn([
                'product_questions_cursor_updated_at',
                'product_questions_cursor_id',
            ]);
        });
    }
};
