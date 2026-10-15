<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_questions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('status')->default(1)->index()->after('needs_reply');
            $table->string('status_text')->default('')->after('status');
            $table->json('reply_operator')->nullable()->after('helpful');
            $table->string('reply_source')->nullable()->index()->after('reply_operator');
            $table->dateTimeTz('replied_at')->nullable()->index()->after('reply_source');
            $table->text('close_reason')->nullable()->after('replied_at');
            $table->json('close_operator')->nullable()->after('close_reason');
        });

        Schema::create('commerce_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commerce_site_id')->constrained()->cascadeOnDelete();
            $table->string('sn');
            $table->string('name')->default('');
            $table->string('product_url')->nullable();
            $table->integer('status')->nullable()->index();
            $table->integer('visible')->nullable()->index();
            $table->json('product');
            $table->json('product_detail')->nullable();
            $table->json('images')->default('[]');
            $table->json('options')->nullable();
            $table->string('payload_hash', 64);
            $table->string('sync_status')->default('completed')->index();
            $table->text('sync_error')->nullable();
            $table->dateTimeTz('synced_at');
            $table->timestamps();

            $table->unique(['commerce_site_id', 'sn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_products');
        Schema::table('product_questions', function (Blueprint $table): void {
            $table->dropColumn([
                'status', 'status_text', 'reply_operator', 'reply_source', 'replied_at',
                'close_reason', 'close_operator',
            ]);
        });
    }
};
