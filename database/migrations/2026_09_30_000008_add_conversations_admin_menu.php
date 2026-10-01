<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $roleTable = config('admin.database.roles_table');
        $permissionTable = config('admin.database.permissions_table');

        DB::table($roleTable)->updateOrInsert(
            ['slug' => 'administrator'],
            ['name' => 'Administrator', 'updated_at' => $now, 'created_at' => $now],
        );
        DB::table($permissionTable)->updateOrInsert(
            ['slug' => '*'],
            [
                'name' => 'All permission',
                'http_method' => '',
                'http_path' => '*',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $roleId = DB::table($roleTable)->where('slug', 'administrator')->value('id');
        $permissionId = DB::table($permissionTable)->where('slug', '*')->value('id');
        DB::table(config('admin.database.role_permissions_table'))->updateOrInsert([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ], ['updated_at' => $now, 'created_at' => $now]);

        DB::table(config('admin.database.menu_table'))->updateOrInsert(
            ['uri' => '/'],
            [
                'parent_id' => 0,
                'order' => 1,
                'title' => 'Dashboard',
                'icon' => 'fa-dashboard',
                'permission' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
        DB::table(config('admin.database.menu_table'))->updateOrInsert(
            ['uri' => 'conversations'],
            [
                'parent_id' => 0,
                'order' => 2,
                'title' => 'Tickets',
                'icon' => 'fa-comments',
                'permission' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table(config('admin.database.menu_table'))->where('uri', 'conversations')->delete();
        DB::table(config('admin.database.role_permissions_table'))->where([
            'role_id' => DB::table(config('admin.database.roles_table'))->where('slug', 'administrator')->value('id'),
            'permission_id' => DB::table(config('admin.database.permissions_table'))->where('slug', '*')->value('id'),
        ])->delete();
        DB::table(config('admin.database.permissions_table'))->where('slug', '*')->delete();
        DB::table(config('admin.database.roles_table'))->where('slug', 'administrator')->delete();
    }
};
