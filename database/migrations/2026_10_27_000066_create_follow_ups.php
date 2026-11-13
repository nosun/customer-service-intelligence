<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('status', 20)->default('open');
            $table->boolean('urgent')->default(false);
            $table->string('reference', 1000)->nullable();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('owner', 100)->nullable();
            $table->text('latest_note')->nullable();
            $table->dateTime('next_follow_up_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['status', 'next_follow_up_at']);
        });

        Schema::create('follow_up_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('follow_up_id')->constrained()->cascadeOnDelete();
            $table->string('actor', 150);
            $table->text('note')->nullable();
            $table->json('changes');
            $table->timestamp('created_at');
        });

        DB::table(config('admin.database.menu_table'))->insert([
            'parent_id' => 0, 'order' => 2, 'title' => '跟进台账',
            'icon' => 'fa-calendar-check-o', 'uri' => 'follow-ups',
            'permission' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table(config('admin.database.menu_table'))->where('uri', 'follow-ups')->delete();
        Schema::dropIfExists('follow_up_entries');
        Schema::dropIfExists('follow_ups');
    }
};
