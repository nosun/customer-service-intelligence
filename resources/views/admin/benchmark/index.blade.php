@php
    $statusLabels = ['pending' => '待标注', 'annotated' => '已标注', 'adjudicated' => '已裁决'];
    $statusColors = ['pending' => 'warning', 'annotated' => 'info', 'adjudicated' => 'success'];
@endphp

<style>
    .benchmark-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
    .benchmark-stat { background: #fff; border-top: 3px solid #d2d6de; box-shadow: 0 1px 1px rgba(0,0,0,.1); padding: 14px 16px; }
    .benchmark-stat strong { display: block; font-size: 24px; }
    .benchmark-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
    .benchmark-filters .form-group { margin-bottom: 0; min-width: 180px; }
    .benchmark-table td { vertical-align: middle !important; }
    @media (max-width: 700px) { .benchmark-stats { grid-template-columns: 1fr; } }
</style>

<div class="benchmark-stats">
    @foreach ($statusLabels as $status => $label)
        <div class="benchmark-stat">
            <span class="text-muted">{{ $label }}</span>
            <strong>{{ (int) ($counts[$status] ?? 0) }}</strong>
        </div>
    @endforeach
</div>

<div class="box box-primary">
    <div class="box-header with-border"><h3 class="box-title">筛选案例</h3></div>
    <div class="box-body">
        <form method="get" action="{{ admin_url('benchmark-cases') }}" class="benchmark-filters">
            <div class="form-group">
                <label for="dataset_version">数据集版本</label>
                <select id="dataset_version" class="form-control" name="dataset_version">
                    <option value="">全部版本</option>
                    @foreach ($datasets as $dataset)
                        <option value="{{ $dataset }}" @selected(($filters['dataset_version'] ?? '') === $dataset)>{{ $dataset }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label for="status">标注状态</label>
                <select id="status" class="form-control" name="status">
                    <option value="">全部状态</option>
                    @foreach ($statusLabels as $status => $label)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-primary"><i class="fa fa-filter"></i> 筛选</button>
            <a class="btn btn-default" href="{{ admin_url('benchmark-cases') }}">清除</a>
        </form>
    </div>
</div>

<div class="box box-solid">
    <div class="box-header with-border">
        <h3 class="box-title">案例列表</h3>
        <span class="pull-right text-muted">共 {{ $cases->total() }} 条</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover benchmark-table">
            <thead><tr><th>#</th><th>Ticket</th><th>主题</th><th>Ticket 状态</th><th>版本 / 分区</th><th>标注状态</th><th>阶段</th><th>标注</th><th></th></tr></thead>
            <tbody>
            @forelse ($cases as $case)
                <tr>
                    <td>{{ $case->id }}</td>
                    <td>{{ $case->conversation?->external_short_id ?: $case->conversation?->external_id }}</td>
                    <td>{{ Illuminate\Support\Str::limit($case->conversation?->subject ?: '无主题', 70) }}</td>
                    <td>{{ $case->conversation?->status ?: 'unknown' }}</td>
                    <td>{{ $case->dataset_version }} / {{ $case->split }}</td>
                    <td><span class="label label-{{ $statusColors[$case->status] ?? 'default' }}">{{ $statusLabels[$case->status] ?? $case->status }}</span></td>
                    <td>{{ $case->steps_count }}</td>
                    <td>{{ $case->annotations_count }}</td>
                    <td><a class="btn btn-xs btn-primary" href="{{ admin_url('benchmark-cases/'.$case->id) }}">切分/标注</a></td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted">暂无基准案例。请先运行 benchmark:sample 命令。</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="box-footer clearfix">{{ $cases->links() }}</div>
</div>
