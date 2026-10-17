<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_article_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_article_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status')->default('draft')->index();
            $table->json('snapshot');
            $table->text('change_summary')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTimeTz('reviewed_at')->nullable();
            $table->dateTimeTz('published_at')->nullable();
            $table->timestamps();

            $table->unique(['knowledge_article_id', 'version']);
        });

        DB::table('knowledge_articles')->orderBy('id')->each(function (object $article): void {
            $snapshot = [];
            foreach ([
                'title', 'knowledge_type', 'site_key', 'applicability', 'automation_tier',
                'required_facts', 'policy_rules', 'decision_tree', 'reply_strategy', 'reply_templates',
                'action_steps', 'prohibited_claims', 'escalation_rules', 'source_notes',
            ] as $field) {
                $value = $article->{$field} ?? null;
                $snapshot[$field] = in_array($field, [
                    'required_facts', 'policy_rules', 'decision_tree', 'reply_strategy', 'reply_templates',
                    'action_steps', 'prohibited_claims', 'escalation_rules',
                ], true) ? (json_decode((string) $value, true) ?: []) : $value;
            }
            DB::table('knowledge_article_versions')->insert([
                'knowledge_article_id' => $article->id,
                'version' => $article->version,
                'status' => 'published',
                'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'change_summary' => 'Initial version snapshot created during versioning rollout.',
                'reviewed_by' => $article->reviewed_by,
                'reviewed_at' => $article->reviewed_at,
                'published_at' => $article->reviewed_at ?? $article->updated_at,
                'created_at' => $article->created_at,
                'updated_at' => $article->updated_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_article_versions');
    }
};
