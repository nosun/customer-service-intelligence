<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_assistance_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('created_by');
            $table->string('target', 30);
            $table->string('kind', 30);
            $table->text('body');
            $table->json('snapshot')->nullable();
            $table->boolean('knowledge_candidate')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_assistance_feedback');
    }
};
