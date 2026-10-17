<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeArticle extends Model
{
    public const STATUSES = [
        'observed' => '历史案例观察，待确认',
        'verified' => '已验证，可供 AI 使用',
        'conflicted' => '存在冲突，禁止自动使用',
        'deprecated' => '已废弃',
    ];

    public const TYPES = [
        'policy' => '政策',
        'process' => '处理流程',
        'fact' => '业务事实',
    ];

    protected $guarded = [];

    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeArticleVersion::class);
    }

    protected function casts(): array
    {
        return [
            'required_facts' => 'array',
            'policy_rules' => 'array',
            'decision_tree' => 'array',
            'reply_strategy' => 'array',
            'reply_templates' => 'array',
            'action_steps' => 'array',
            'prohibited_claims' => 'array',
            'escalation_rules' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
