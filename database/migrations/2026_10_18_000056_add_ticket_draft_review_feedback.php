<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_drafts', function (Blueprint $table): void {
            $table->string('review_outcome')->nullable()->after('requires_human_review')->index();
            $table->text('review_notes')->nullable()->after('review_outcome');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('review_notes');
            $table->dateTimeTz('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_drafts', function (Blueprint $table): void {
            $table->dropIndex(['review_outcome']);
            $table->dropColumn(['review_outcome', 'review_notes', 'reviewed_by', 'reviewed_at']);
        });
    }
};
