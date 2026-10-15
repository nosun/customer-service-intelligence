<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_sites', function (Blueprint $table): void {
            $table->string('public_base_url')->nullable()->after('admin_api_url');
        });
        Schema::table('product_question_policy_candidates', function (Blueprint $table): void {
            $table->string('scope')->default('site')->after('commerce_site_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('product_question_policy_candidates', function (Blueprint $table): void {
            $table->dropIndex(['scope']);
            $table->dropColumn('scope');
        });
        Schema::table('commerce_sites', function (Blueprint $table): void {
            $table->dropColumn('public_base_url');
        });
    }
};
