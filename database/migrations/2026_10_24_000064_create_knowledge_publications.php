<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_article_id')->constrained()->cascadeOnDelete();
            $table->string('knowledge_base_id', 100);
            $table->string('remote_id', 100)->nullable();
            $table->unsignedInteger('version');
            $table->string('content_hash', 64);
            $table->longText('content');
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('verification_attempts')->default(0);
            $table->string('error_message')->nullable();
            $table->timestamps();
            $table->unique(['knowledge_article_id', 'knowledge_base_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_publications');
    }
};
