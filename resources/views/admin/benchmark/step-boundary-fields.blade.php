@php
    $prefix = $editingStep ? 'edit-'.$editingStep->id.'-' : '';
    $selectedReplies = old('observed_reply_event_ids', $editingStep?->observed_reply_event_ids ?? []);
@endphp

<div class="form-group">
    <label for="{{ $prefix }}title">阶段名称 *</label>
    <input id="{{ $prefix }}title" class="form-control" name="title" required value="{{ old('title', $editingStep?->title) }}" placeholder="例如：下单后补充实际尺寸">
</div>
<div class="form-group">
    <label for="{{ $prefix }}trigger">触发客户消息 *</label>
    <select id="{{ $prefix }}trigger" class="form-control" name="trigger_event_id" required>
        <option value="">请选择</option>
        @foreach ($ticket->events->whereIn('author_type', ['client', 'customer']) as $event)
            <option value="{{ $event->id }}" @selected((int) old('trigger_event_id', $editingStep?->trigger_event_id) === $event->id)>#{{ $event->id }} · {{ Illuminate\Support\Str::limit($event->body_text ?: $event->status_value, 70) }}</option>
        @endforeach
    </select>
    <p class="help-block">阶段上下文截止到这条消息，后续客服回复不会在盲标时显示。</p>
</div>
<div class="form-group">
    <label for="{{ $prefix }}start">上下文起点</label>
    <select id="{{ $prefix }}start" class="form-control" name="context_start_event_id">
        @foreach ($ticket->events as $event)
            <option value="{{ $event->id }}" @selected((int) old('context_start_event_id', $editingStep?->context_start_event_id ?? $ticket->events->first()?->id) === $event->id)>#{{ $event->id }} · {{ $event->author_type }} · {{ Illuminate\Support\Str::limit($event->body_text ?: $event->status_value, 55) }}</option>
        @endforeach
    </select>
</div>
<div class="form-group">
    <label>触发后的历史客服回复</label>
    <div class="checkbox-list">
        @foreach ($ticket->events->where('author_type', 'agent')->filter(fn ($event) => filled($event->body_text)) as $event)
            <div class="checkbox"><label><input type="checkbox" name="observed_reply_event_ids[]" value="{{ $event->id }}" @checked(in_array($event->id, $selectedReplies, true))> #{{ $event->id }} · {{ Illuminate\Support\Str::limit($event->body_text, 80) }}</label></div>
        @endforeach
    </div>
</div>
<input type="hidden" name="requires_response" value="0">
<div class="checkbox"><label><input type="checkbox" name="requires_response" value="1" @checked(old('requires_response', $editingStep?->requires_response ?? true))> 这个阶段需要客服回复</label></div>
<div class="form-group"><label for="{{ $prefix }}end_reason">阶段结束原因</label><input id="{{ $prefix }}end_reason" class="form-control" name="stage_end_reason" value="{{ old('stage_end_reason', $editingStep?->stage_end_reason) }}" placeholder="例如：客户完成下单，目标发生变化"></div>
