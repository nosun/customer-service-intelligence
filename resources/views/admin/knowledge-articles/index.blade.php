<div class="callout callout-info">
    <h4>版本化 Policy</h4>
    <p>这里编辑的是已发布问答 Policy。修改先保存为不可变 Draft 版本，人工检查后再发布；创建 Draft 不会影响当前 n8n 回复。</p>
</div>

<div class="box box-solid"><div class="box-body table-responsive no-padding"><table class="table table-hover">
    <thead><tr><th>Key</th><th>标题</th><th>范围</th><th>风险层级</th><th>当前版本</th><th>待审核 Draft</th></tr></thead>
    <tbody>@forelse($articles as $article)<tr>
        <td><a href="{{ admin_url('knowledge-articles/'.$article->id) }}">{{ $article->key }}</a></td>
        <td>{{ $article->title }}</td>
        <td>{{ $article->site_key ?: 'global' }}</td>
        <td>{{ $article->automation_tier }}</td>
        <td>v{{ $article->version }} · {{ $article->status }}</td>
        <td>{{ $article->draft_versions_count }}</td>
    </tr>@empty<tr><td colspan="6" class="text-center text-muted" style="padding:40px">暂无问答 Policy。</td></tr>@endforelse</tbody>
</table></div>@if($articles->hasPages())<div class="box-footer">{{ $articles->links() }}</div>@endif</div>
