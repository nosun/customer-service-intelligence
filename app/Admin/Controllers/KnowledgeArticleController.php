<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeArticleVersion;
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
            ->where('key', 'like', 'qa_%')
            ->withCount(['versions as draft_versions_count' => fn ($query) => $query->where('status', 'draft')])
            ->orderBy('key')
            ->paginate(50);

        return $content->title('已发布问答 Policy')
            ->description('版本化编辑、审核与发布')
            ->body(view('admin.knowledge-articles.index', compact('articles')));
    }

    public function show(KnowledgeArticle $knowledgeArticle, Content $content): Content
    {
        abort_unless(str_starts_with($knowledgeArticle->key, 'qa_'), 404);
        $versions = $knowledgeArticle->versions()->latest('version')->get();

        return $content->title($knowledgeArticle->title)
            ->description($knowledgeArticle->key.' · published v'.$knowledgeArticle->version)
            ->body(view('admin.knowledge-articles.show', compact('knowledgeArticle', 'versions')));
    }

    public function storeDraft(
        Request $request,
        KnowledgeArticle $knowledgeArticle,
        KnowledgeArticleVersionPublisher $publisher,
    ): RedirectResponse {
        abort_unless(str_starts_with($knowledgeArticle->key, 'qa_'), 404);
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
