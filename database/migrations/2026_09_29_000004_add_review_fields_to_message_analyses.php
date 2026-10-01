<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_analyses', function (Blueprint $table): void {
            $table->string('problem_type')->nullable()->after('category');
            $table->string('urgency')->default('normal')->after('rule_score');
            $table->boolean('is_noise')->default(false)->after('urgency');
            $table->string('noise_type')->nullable()->after('is_noise');
        });
    }

    public function down(): void
    {
        Schema::table('message_analyses', function (Blueprint $table): void {
            $table->dropColumn(['problem_type', 'urgency', 'is_noise', 'noise_type']);
        });
    }
};
