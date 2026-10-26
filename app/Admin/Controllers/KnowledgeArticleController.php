<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeArticleBinding;
use App\Models\KnowledgeArticleVersion;
use App\Models\KnowledgePublication;
use App\Services\ProductQuestions\KnowledgeArticleVersionPublisher;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use JsonException;
use RuntimeException;

class KnowledgeArticleController extends Controller
{
    public function index(Content $content): Content
    {
        $articles = KnowledgeArticle::query()
            ->with('bindings')
            ->withCount(['versions as draft_versions_count' => fn ($query) => $query->where('status', 'draft')])
            ->orderBy('key')
            ->paginate(50);

        return $content->title('统一知识库')
            ->description('客服与商品问答共享的版本化知识')
            ->body(view('admin.knowledge-articles.index', compact('articles')));
    }

    public function show(KnowledgeArticle $knowledgeArticle, Content $content): Content
    {
        $knowledgeArticle->load('bindings');
        $versions = $knowledgeArticle->versions()->latest('version')->get();

        return $content->title($knowledgeArticle->title)
            ->description($knowledgeArticle->key.' · published v'.$knowledgeArticle->version)
            ->body(view('admin.knowledge-articles.show', ['knowledgeArticle' => $knowledgeArticle, 'versions' => $versions, 'publications' => KnowledgePublication::where('knowledge_article_id', $knowledgeArticle->id)->get()]));
    }

    public function storeDraft(
        Request $request,
        KnowledgeArticle $knowledgeArticle,
        KnowledgeArticleVersionPublisher $publisher,
    ): RedirectResponse {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'knowledge_type' => ['required', Rule::in(array_keys(KnowledgeArticle::TYPES))],
            'site_key' => ['nullable', 'string', 'max:100'],
            'applicability' => ['nullable', 'string', 'max:20000'],
            'automation_tier' => ['required', Rule::in(['draft_low_risk', 'draft_sensitive', 'manual_only'])],
            'source_notes' => ['nullable', 'string', 'max:20000'],
            'change_summary' => ['required', 'string', 'max:2000'],
            ...collect(KnowledgeArticleVersionPublisher::ARRAY_FIELDS)
                ->mapWithKeys(fn (string $field): array => [$field => ['required', 'string', 'max:100000']])->all(),
        ]);
        try {
            foreach (KnowledgeArticleVersionPublisher::ARRAY_FIELDS as $field) {
                $decoded = json_decode($data[$field], true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || ! array_is_list($decoded)) {
                    throw new RuntimeException("{$field} must be a JSON array.");
                }
                $data[$field] = $decoded;
            }
            $summary = $data['change_summary'];
            unset($data['change_summary']);
            $version = $publisher->createDraft($knowledgeArticle, $data, $summary, Admin::user()->id);
        } catch (JsonException|RuntimeException $exception) {
            return back()->withErrors(['policy' => $exception->getMessage()])->withInput();
        }
        admin_toastr("Policy Draft v{$version->version} 已创建，尚未影响在线 Draft。", 'success');

        return back();
    }

    public function storeBinding(Request $request, KnowledgeArticle $knowledgeArticle): RedirectResponse
    {
        $data = $request->validate([
            'consumer' => ['required', Rule::in(array_keys(KnowledgeArticleBinding::CONSUMERS))],
            'intent_key' => ['required', 'string', 'max:120', 'regex:/^(\*|[a-z0-9_\-]+)$/'],
            'usage' => ['required', Rule::in(array_keys(KnowledgeArticleBinding::USAGES))],
            'priority' => ['required', 'integer', 'between:0,1000'],
        ]);
        $knowledgeArticle->bindings()->updateOrCreate(
            [
                'consumer' => $data['consumer'],
                'intent_key' => $data['intent_key'],
                'usage' => $data['usage'],
            ],
            ['priority' => $data['priority'], 'enabled' => true],
        );
        admin_toastr('知识消费绑定已保存。', 'success');

        return back();
    }

    public function toggleBinding(
        KnowledgeArticle $knowledgeArticle,
        KnowledgeArticleBinding $binding,
    ): RedirectResponse {
        abort_unless($binding->knowledge_article_id === $knowledgeArticle->id, 404);
        $binding->update(['enabled' => ! $binding->enabled]);
        admin_toastr($binding->enabled ? '知识消费绑定已启用。' : '知识消费绑定已停用。', 'success');

        return back();
    }

    public function publish(
        KnowledgeArticle $knowledgeArticle,
        KnowledgeArticleVersion $version,
        KnowledgeArticleVersionPublisher $publisher,
    ): RedirectResponse {
        abort_unless($version->knowledge_article_id === $knowledgeArticle->id, 404);
        try {
            $publisher->publish($version, Admin::user()->id);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['policy' => $exception->getMessage()]);
        }
        admin_toastr("Policy v{$version->version} 已发布；后续 Draft 输入会使用新版本。", 'success');

        return back();
    }

    public function reject(
        KnowledgeArticle $knowledgeArticle,
        KnowledgeArticleVersion $version,
        KnowledgeArticleVersionPublisher $publisher,
    ): RedirectResponse {
        abort_unless($version->knowledge_article_id === $knowledgeArticle->id, 404);
        try {
            $publisher->reject($version, Admin::user()->id);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['policy' => $exception->getMessage()]);
        }
        admin_toastr("Policy Draft v{$version->version} 已驳回。", 'success');

        return back();
    }
}
