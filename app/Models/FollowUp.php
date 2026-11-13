<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FollowUp extends Model
{
    public const TIMEZONE = 'Asia/Shanghai';

    public const STATUSES = ['open' => '待处理', 'active' => '处理中', 'waiting' => '等待中', 'closed' => '已结束'];

    public const FILTERS = ['open' => '未结束', 'due' => '已到期', 'today' => '今日待跟进', 'urgent' => '紧急', 'unscheduled' => '未安排时间', 'closed' => '已结束', 'all' => '全部'];

    protected $guarded = [];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FollowUpEntry::class);
    }

    protected function casts(): array
    {
        return ['urgent' => 'boolean', 'next_follow_up_at' => 'datetime', 'closed_at' => 'datetime', 'version' => 'integer'];
    }
}
