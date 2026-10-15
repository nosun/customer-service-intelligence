<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table(config('admin.database.menu_table'))->updateOrInsert(
            ['uri' => 'chatbot-chats'],
            [
                'parent_id' => 0,
                'order' => 3,
                'title' => 'ChatBot Chats',
                'icon' => 'fa-commenting',
                'permission' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table(config('admin.database.menu_table'))->where('uri', 'chatbot-chats')->delete();
    }
};
