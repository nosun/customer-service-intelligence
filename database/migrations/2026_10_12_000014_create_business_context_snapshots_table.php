<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_context_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('context_type')->index();
            $table->string('lookup_key')->nullable()->index();
            $table->string('lookup_status')->index();
            $table->string('outcome')->nullable()->index();
            $table->json('summary')->nullable();
            $table->string('response_hash', 64)->nullable();
            $table->string('source_request_id')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTimeTz('queried_at');
            $table->timestamps();
            $table->index(['conversation_id', 'source', 'context_type'], 'business_context_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_context_snapshots');
    }
};
