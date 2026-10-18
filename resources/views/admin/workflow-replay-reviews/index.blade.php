<div class="callout callout-info">
    <h4>人工发布门禁</h4>
    <p>这里的事实错误与 Policy 违规必须由人工逐条判断。AI Reviewer 的结果不会计入人工审核覆盖率。</p>
    <p><code>php artisan product-questions:import-draft-replay-review &lt;predictions.json&gt; --workflow-version=1.2.0</code></p>
</div>

<div class="box box-primary"><div class="box-body table-responsive no-padding"><table class="table table-hover">
    <thead><tr><th>Workflow</th><th>导入时间</th><th>审核进度</th><th>状态</th><th>发布门禁</th></tr></thead>
    <tbody>@forelse($runs as $run)<tr>
        <td><a href="{{ admin_url('workflow-replay-reviews/'.$run->id) }}">{{ $run->workflow->workflow_key }} @ {{ $run->workflow->workflow_version }}</a></td>
        <td>{{ $run->imported_at }}</td>
        <td>{{ $run->reviewed_count }} / {{ $run->case_count }}</td>
        <td>{{ $run->status }}</td>
        <td>@if($run->release_report)<span class="label label-{{ data_get($run->release_report, 'passed') ? 'success' : 'danger' }}">{{ data_get($run->release_report, 'passed') ? '通过' : '未通过' }}</span>@else—@endif</td>
    </tr>@empty<tr><td colspan="5" class="text-center text-muted" style="padding:40px">尚未导入真实 Workflow 回放。</td></tr>@endforelse</tbody>
</table></div>@if($runs->hasPages())<div class="box-footer">{{ $runs->links() }}</div>@endif</div>
