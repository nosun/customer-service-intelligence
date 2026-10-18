<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table(config('admin.database.menu_table'))
            ->where('uri', 'product-question-factory-capabilities')
            ->delete();
    }

    public function down(): void
    {
        DB::table(config('admin.database.menu_table'))->updateOrInsert(
            ['uri' => 'product-question-factory-capabilities'],
            [
                'parent_id' => 0,
                'order' => 35,
                'title' => '问答工厂能力',
                'icon' => 'fa-industry',
                'permission' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
};
