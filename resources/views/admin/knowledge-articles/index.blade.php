<div class="callout callout-info">
    <h4>统一知识库</h4>
    <p>客服 Ticket 与商品公开问答共用同一套 KnowledgeArticle。业务内容只维护一次，通过消费绑定声明可供哪个渠道和 Intent 使用；修改先保存为不可变 Draft 版本，人工检查后再发布。</p>
</div>

<div class="box box-solid"><div class="box-body table-responsive no-padding"><table class="table table-hover">
    <thead><tr><th>Key</th><th>标题</th><th>范围</th><th>消费渠道</th><th>风险层级</th><th>当前版本</th><th>待审核 Draft</th></tr></thead>
    <tbody>@forelse($articles as $article)<tr>
        <td><a href="{{ admin_url('knowledge-articles/'.$article->id) }}">{{ $article->key }}</a></td>
        <td>{{ $article->title }}</td>
        <td>{{ $article->site_key ?: 'global' }}</td>
        <td>@forelse($article->bindings as $binding)<span class="label label-{{ $binding->enabled ? 'success' : 'default' }}">{{ \App\Models\KnowledgeArticleBinding::CONSUMERS[$binding->consumer] ?? $binding->consumer }} / {{ $binding->intent_key }}</span> @empty<span class="text-muted">未绑定</span>@endforelse</td>
        <td>{{ $article->automation_tier }}</td>
        <td>v{{ $article->version }} · {{ $article->status }}</td>
        <td>{{ $article->draft_versions_count }}</td>
    </tr>@empty<tr><td colspan="7" class="text-center text-muted" style="padding:40px">暂无知识。</td></tr>@endforelse</tbody>
</table></div>@if($articles->hasPages())<div class="box-footer">{{ $articles->links() }}</div>@endif</div>
