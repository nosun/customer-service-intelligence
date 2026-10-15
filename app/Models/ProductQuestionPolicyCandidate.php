<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductQuestionPolicyCandidate extends Model
{
    protected $guarded = [];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductQuestionPolicyRun::class, 'product_question_policy_run_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(CommerceSite::class, 'commerce_site_id');
    }

    public function knowledgeArticle(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class);
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
            'evidence_question_ids' => 'array',
            'conflicts' => 'array',
            'open_questions' => 'array',
            'confidence' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }
}
