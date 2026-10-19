<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_article_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_article_id')->constrained()->cascadeOnDelete();
            $table->string('consumer')->index();
            $table->string('intent_key')->default('*')->index();
            $table->string('usage')->default('draft');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();

            $table->unique(
                ['knowledge_article_id', 'consumer', 'intent_key', 'usage'],
                'knowledge_article_binding_unique',
            );
        });

        $now = now();
        DB::table('knowledge_articles')->orderBy('id')->each(function (object $article) use ($now): void {
            $intent = $this->productQuestionIntent($article);
            DB::table('knowledge_article_bindings')->insert([
                'knowledge_article_id' => $article->id,
                'consumer' => $intent === null ? 'ticket' : 'product_question',
                'intent_key' => $intent ?? '*',
                'usage' => 'draft',
                'priority' => 100,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $sharedOrderStatusId = DB::table('knowledge_articles')
            ->where('key', 'qa_global_ask_order_status')
            ->value('id');
        if ($sharedOrderStatusId !== null) {
            foreach (['production_order_status', 'logistics_tracking_delivery'] as $intent) {
                DB::table('knowledge_article_bindings')->insert([
                    'knowledge_article_id' => $sharedOrderStatusId,
                    'consumer' => 'ticket',
                    'intent_key' => $intent,
                    'usage' => 'draft',
                    'priority' => 110,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        DB::table(config('admin.database.menu_table'))->updateOrInsert(
            ['uri' => 'knowledge-articles'],
            [
                'parent_id' => 0,
                'order' => 6,
                'title' => '统一知识库',
                'icon' => 'fa-book',
                'permission' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table(config('admin.database.menu_table'))->where('uri', 'knowledge-articles')->delete();
        Schema::dropIfExists('knowledge_article_bindings');
    }

    private function productQuestionIntent(object $article): ?string
    {
        $key = (string) $article->key;
        if (str_starts_with($key, 'qa_global_')) {
            return substr($key, strlen('qa_global_'));
        }
        if (str_starts_with($key, 'qa_')) {
            foreach (DB::table('commerce_sites')->pluck('site_key') as $siteKey) {
                $prefix = 'qa_'.$siteKey.'_';
                if (str_starts_with($key, $prefix)) {
                    return substr($key, strlen($prefix));
                }
            }
        }

        return null;
    }
};
