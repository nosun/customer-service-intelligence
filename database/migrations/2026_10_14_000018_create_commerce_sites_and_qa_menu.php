<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('site_key')->unique();
            $table->string('admin_api_url');
            $table->text('api_token');
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
        });

        $now = now();
        DB::table(config('admin.database.menu_table'))->updateOrInsert(
            ['uri' => 'product-questions'],
            [
                'parent_id' => 0,
                'order' => 4,
                'title' => '售前问答',
                'icon' => 'fa-question-circle',
                'permission' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
        DB::table(config('admin.database.menu_table'))->updateOrInsert(
            ['uri' => 'commerce-sites'],
            [
                'parent_id' => 0,
                'order' => 5,
                'title' => '站点配置',
                'icon' => 'fa-cogs',
                'permission' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table(config('admin.database.menu_table'))
            ->whereIn('uri', ['product-questions', 'commerce-sites'])
            ->delete();
        Schema::dropIfExists('commerce_sites');
    }
};
