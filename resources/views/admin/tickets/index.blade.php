@php
    $currentView = $filters['view'] ?? 'to_handle';
    $currentSilo = $filters['silo'] ?? null;
    $statusLabels = ['open' => 'Open', 'pending' => 'Pending', 'onhold' => 'On hold', 'solved' => 'Solved', 'closed' => 'Closed'];
    $caseStatusLabels = ['candidate' => '待审核', 'confirmed' => '已确认', 'corrected' => '已修正', 'rejected' => '已拒绝'];
    $syncTime = $lastSyncedAt ? \Illuminate\Support\Carbon::parse($lastSyncedAt) : null;
@endphp

<style>
    .ticket-workbench { display: grid; grid-template-columns: 210px minmax(0, 1fr); gap: 16px; }
    .ticket-workbench .box { margin-bottom: 16px; }
    .ticket-nav a { display: flex; justify-content: space-between; padding: 9px 12px; color: #444; border-radius: 4px; }
    .ticket-nav a:hover, .ticket-nav a.active { background: #e8f4fc; color: #1677a8; }
    .ticket-nav .nav-heading { padding: 15px 12px 5px; color: #999; font-size: 12px; text-transform: uppercase; }
    .ticket-subject { font-weight: 600; color: #333; }
    .ticket-preview { color: #777; margin-top: 4px; max-width: 600px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ticket-meta { color: #888; font-size: 12px; margin-top: 5px; }
    .ticket-table td { vertical-align: middle !important; }
    .filter-grid { display: grid; grid-template-columns: minmax(180px, 2fr) repeat(4, minmax(130px, 1fr)) auto; gap: 10px; align-items: end; }
    .filter-grid label { display: block; color: #777; font-size: 12px; }
    @media (max-width: 1100px) { .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 760px) { .ticket-workbench { grid-template-columns: 1fr; } .filter-grid { grid-template-columns: 1fr; } }
</style>

@if(in_array(data_get(\Illuminate\Support\Facades\Cache::get('helpdesk:sync-state'), 'status'), ['queued', 'running'], true))<meta http-equiv="refresh" content="5">@endif

<div class="ticket-workbench">
    <aside class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">队列</h3></div>
        <div class="box-body no-padding ticket-nav">
            <a href="{{ admin_url('conversations?view=recent') }}" class="{{ $currentView === 'recent' && !$currentSilo ? 'active' : '' }}">
                <span><i class="fa fa-inbox"></i> All recent tickets</span><span>{{ $counts['recent'] }}</span>
            </a>
            <a href="{{ admin_url('conversations?view=to_handle') }}" class="{{ $currentView === 'to_handle' ? 'active' : '' }}">
                <span><i class="fa fa-hand-paper-o"></i> Tickets to handle</span><span>{{ $counts['to_handle'] }}</span>
            </a>
            <a href="{{ admin_url('conversations?view=my_open') }}" class="{{ $currentView === 'my_open' ? 'active' : '' }}" title="{{ $currentAgentName ? 'HelpDesk agent: '.$currentAgentName : '请配置 HELPDESK_CURRENT_AGENT_ID' }}">
                <span><i class="fa fa-user"></i> My open tickets</span><span>{{ $counts['my_open'] }}</span>
            </a>

            <div class="nav-heading">本地分析</div>
            <a href="{{ admin_url('conversations?view=needs_review') }}" class="{{ $currentView === 'needs_review' ? 'active' : '' }}">
                <span><i class="fa fa-check-square-o"></i> 需要审核</span><span>{{ $counts['needs_review'] }}</span>
            </a>
            <a href="{{ admin_url('conversations?view=has_case') }}" class="{{ $currentView === 'has_case' ? 'active' : '' }}">
                <span><i class="fa fa-sitemap"></i> Decision Cases</span><span>{{ $counts['has_case'] }}</span>
            </a>
            <a href="{{ admin_url('conversations?view=high_risk') }}" class="{{ $currentView === 'high_risk' ? 'active' : '' }}">
                <span><i class="fa fa-warning"></i> 高风险</span><span>{{ $counts['high_risk'] }}</span>
            </a>

            <div class="nav-heading">Statuses</div>
            @foreach ($statusLabels as $status => $label)
                <a href="{{ admin_url('conversations?view=all&silo=tickets&status='.$status) }}" class="{{ ($filters['status'] ?? null) === $status ? 'active' : '' }}">
                    <span>{{ $label }}</span><span>{{ (int) ($counts['status'][$status] ?? 0) }}</span>
                </a>
            @endforeach

            <div class="nav-heading">Folders</div>
            @foreach (['archive' => 'Archive', 'spam' => 'Spam', 'trash' => 'Trash'] as $silo => $label)
                <a href="{{ admin_url('conversations?view=all&silo='.$silo) }}" class="{{ $currentSilo === $silo ? 'active' : '' }}">
                    <span>{{ $label }}</span><span>{{ (int) ($counts['silo'][$silo] ?? 0) }}</span>
                </a>
            @endforeach
        </div>
    </aside>

    <main>
        @php($syncState = \Illuminate\Support\Facades\Cache::get('helpdesk:sync-state', []))
        <div class="box box-solid"><div class="box-body">
            <form method="post" action="{{ admin_url('conversations/sync') }}" style="display:inline-block">@csrf
                <button class="btn btn-primary" @disabled(in_array($syncState['status'] ?? null, ['queued', 'running'], true))><i class="fa fa-refresh"></i> 立即同步 Ticket</button>
            </form>
            <span class="text-muted">{{ ['queued' => '等待同步', 'running' => '正在同步', 'completed' => '最近同步完成', 'failed' => '同步失败，请查看账户同步错误'][$syncState['status'] ?? ''] ?? '复用现有定时增量同步' }} · 不修改 HelpDesk</span>
        </div></div>
        <div class="box box-primary">
            <div class="box-body">
                <form method="get" action="{{ admin_url('conversations') }}" class="filter-grid">
                    <input type="hidden" name="view" value="{{ $currentView }}">
                    @if ($currentSilo)<input type="hidden" name="silo" value="{{ $currentSilo }}">@endif
                    <div><label>关键词</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="Ticket ID、主题或邮箱"></div>
                    <div><label>状态</label><select class="form-control" name="status"><option value="">全部</option>@foreach ($statusLabels as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div><label>站点</label><select class="form-control" name="site_key"><option value="">全部</option>@foreach ($sites as $site)<option value="{{ $site }}" @selected(($filters['site_key'] ?? '') === $site)>{{ $site }}</option>@endforeach</select></div>
                    <div><label>问题类型</label><select class="form-control" name="problem_type"><option value="">全部</option>@foreach ($problemTypes as $type)<option value="{{ $type }}" @selected(($filters['problem_type'] ?? '') === $type)>{{ $type }}</option>@endforeach</select></div>
                    <div><label>案例状态</label><select class="form-control" name="case_status"><option value="">全部</option>@foreach ($caseStatusLabels as $value => $label)<option value="{{ $value }}" @selected(($filters['case_status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div><button class="btn btn-primary"><i class="fa fa-search"></i> 筛选</button> <a class="btn btn-default" href="{{ admin_url('conversations?view='.$currentView.($currentSilo ? '&silo='.$currentSilo : '')) }}">清空</a></div>
                </form>
            </div>
        </div>

        <div class="box">
            <div class="box-header with-border">
                <h3 class="box-title">{{ number_format($tickets->total()) }} 条结果</h3>
                @if($syncTime)<span class="pull-right text-muted" title="{{ $syncTime->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}">HelpDesk checked {{ $syncTime->diffForHumans() }}</span>@endif
            </div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover ticket-table">
                    <thead><tr><th>Ticket</th><th>站点 / 分类</th><th>状态</th><th>Decision Case</th><th>智能辅助</th><th>更新时间</th></tr></thead>
                    <tbody>
                    @forelse ($tickets as $ticket)
                        <tr>
                            <td>
                                <a class="ticket-subject" href="{{ admin_url('conversations/'.$ticket->id) }}">{{ $ticket->subject ?: '（无主题）' }}</a>
                                <div class="ticket-preview">{{ $ticket->requester_email ?: '未知请求人' }}</div>
                                <div class="ticket-meta">#{{ $ticket->external_short_id ?: $ticket->external_id }} · {{ $ticket->events_count }} 条事件</div>
                            </td>
                            <td>{{ $ticket->site_key ?: '未识别' }}<div class="ticket-meta">{{ $ticket->analysis?->primary_problem_type ?: $ticket->analysis?->primary_category ?: '未分析' }}</div></td>
                            <td><span class="label label-{{ in_array($ticket->status, ['open', 'pending'], true) ? 'warning' : 'default' }}">{{ $ticket->status ?: 'unknown' }}</span><div class="ticket-meta">{{ $ticket->silo }}</div></td>
                            <td>
                                @if ($ticket->decisionCase)
                                    <span class="label label-{{ $ticket->decisionCase->review_status === 'candidate' ? 'warning' : ($ticket->decisionCase->review_status === 'rejected' ? 'danger' : 'success') }}">{{ $caseStatusLabels[$ticket->decisionCase->review_status] ?? $ticket->decisionCase->review_status }}</span>
                                    @if (in_array($ticket->decisionCase->case_risk, ['high', 'critical'], true))<span class="label label-danger">{{ $ticket->decisionCase->case_risk }}</span>@endif
                                @else
                                    <span class="text-muted">未生成</span>
                                @endif
                            </td>
                            <td>{{ app(\App\Services\TicketWork\TicketAssistanceView::class)->present($ticket, $ticket->runtimeCaseSource?->customerCase?->workItems->first())['label'] }}</td>
                            @php($lastActivity = $ticket->last_message_at ?: $ticket->updated_at_external)
                            <td>@if($lastActivity)<time datetime="{{ $lastActivity->toIso8601String() }}" title="{{ $lastActivity->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}">{{ $lastActivity->diffForHumans() }}</time>@else — @endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted" style="padding: 40px">没有符合条件的 Ticket</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($tickets->hasPages())<div class="box-footer clearfix">{{ $tickets->links() }}</div>@endif
        </div>
    </main>
</div>
