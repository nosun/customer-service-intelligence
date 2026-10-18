<?php

namespace App\Services\ProductQuestions;

use App\Models\CommerceSite;
use App\Models\ProductQuestionFactoryCapability;
use App\Models\ProductQuestionPolicyCandidate;
use App\Models\ProductQuestionPolicyRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductQuestionFactoryCapabilityPolicyImporter
{
    private const INTENTS = [
        'custom_size' => 'request_custom_size',
        'custom_color' => 'request_custom_color',
        'custom_length' => 'request_custom_length',
        'add_pockets' => 'request_add_pockets',
        'add_sleeves' => 'request_add_sleeves',
        'remove_sleeves' => 'request_remove_sleeves',
        'change_neckline' => 'request_change_neckline',
        'change_back' => 'request_change_back',
        'remove_train' => 'request_remove_train',
        'add_train' => 'request_add_train',
        'mix_design_elements' => 'request_mix_designs',
        'change_fabric' => 'request_other_customization',
        'change_lining' => 'request_other_customization',
        'change_embellishment' => 'request_other_customization',
        'rush_production' => 'request_rush_order',
        'other_customization' => 'request_other_customization',
    ];

    public function import(CommerceSite $site): ProductQuestionPolicyRun
    {
        $capabilities = ProductQuestionFactoryCapability::query()
            ->where('commerce_site_id', $site->id)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ProductQuestionFactoryCapability $capability): string => $this->intent($capability->capability_key));

        $run = ProductQuestionPolicyRun::firstOrNew([
            'batch_key' => 'factory-capability-policy-review-'.$site->site_key,
        ]);
        $now = now();
        $run->fill([
            'commerce_site_id' => $site->id,
            'window_start' => $now,
            'window_end' => $now,
            'sample_limit' => $capabilities->flatten(1)->count(),
            'selection_seed' => 'factory-capability-policy-review',
            'source_assumption' => 'Factory capability cards are evidence and review inputs, not verified policy until a human confirms the resulting candidate.',
            'status' => 'completed',
            'selected_count' => $capabilities->flatten(1)->sum('question_count'),
            'analyzed_count' => $capabilities->flatten(1)->sum('question_count'),
            'failed_count' => 0,
            'provider' => 'factory_capability_aggregation',
            'model' => 'none',
            'prompt_version' => ProductQuestionPolicyPrompt::VERSION,
            'usage' => [],
            'started_at' => $run->started_at ?? $now,
            'finished_at' => $now,
        ])->save();

        foreach ($capabilities as $intent => $group) {
            if ($intent === 'request_add_pockets' && $this->mergePocketEvidence($run, $group)) {
                continue;
            }
            $candidate = ProductQuestionPolicyCandidate::firstOrNew([
                'product_question_policy_run_id' => $run->id,
                'scenario_key' => $intent,
            ]);
            if ($candidate->exists && $candidate->review_status !== 'candidate') {
                continue;
            }
            $candidate->fill($this->candidate($site, $group, $intent))->save();
        }

        $run->update(['candidate_count' => $run->candidates()->count()]);

        return $run->fresh();
    }

    /** @param Collection<int,ProductQuestionFactoryCapability> $capabilities */
    private function mergePocketEvidence(ProductQuestionPolicyRun $run, Collection $capabilities): bool
    {
        $target = ProductQuestionPolicyCandidate::query()
            ->where('scenario_key', 'ask_pockets')
            ->where('review_status', 'confirmed')
            ->whereNotNull('knowledge_article_id')
            ->latest('id')
            ->first();
        if ($target === null) {
            return false;
        }

        $evidenceIds = collect($target->evidence_question_ids)
            ->merge($capabilities->flatMap(fn (ProductQuestionFactoryCapability $item): array => $item->evidence_question_ids ?: []))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $target->update(['evidence_question_ids' => $evidenceIds]);

        foreach ($evidenceIds as $questionId) {
            DB::table('product_question_strategy_evidence')->updateOrInsert(
                [
                    'knowledge_article_id' => $target->knowledge_article_id,
                    'product_question_id' => $questionId,
                ],
                [
                    'product_question_analysis_id' => DB::table('product_question_analyses')
                        ->where('product_question_id', $questionId)
                        ->value('id'),
                    'evidence_role' => 'historical_example',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $duplicate = ProductQuestionPolicyCandidate::query()->firstOrNew([
            'product_question_policy_run_id' => $run->id,
            'scenario_key' => 'request_add_pockets',
        ]);
        if ($duplicate->exists && $duplicate->review_status === 'candidate') {
            $duplicate->update([
                'review_status' => 'rejected',
                'review_notes' => 'merged_into_policy_candidate:'.$target->id,
                'open_questions' => [],
                'reviewed_at' => now(),
            ]);
        }

        return true;
    }

    public function intent(string $capabilityKey): string
    {
        return self::INTENTS[$capabilityKey] ?? 'request_other_customization';
    }

    /** @param Collection<int,ProductQuestionFactoryCapability> $capabilities
     * @return array<string,mixed>
     */
    private function candidate(CommerceSite $site, Collection $capabilities, string $intent): array
    {
        $titles = $capabilities->pluck('title')->unique()->values();
        $evidenceIds = $capabilities->flatMap(fn (ProductQuestionFactoryCapability $item): array => $item->evidence_question_ids ?: [])
            ->unique()->values()->all();
        $openQuestions = $capabilities->flatMap(fn (ProductQuestionFactoryCapability $item): array => $this->openQuestions($item))
            ->unique()->values()->all();

        $candidate = [
            'commerce_site_id' => $site->id,
            'scope' => 'site',
            'category' => $intent === 'request_rush_order' ? 'production_time' : 'customization',
            'title' => $titles->count() === 1 ? $titles->first().'能力与回复规则' : $titles->implode('、').'能力与回复规则',
            'definition' => '客户询问工厂是否支持'.$titles->implode('、').'，以及适用范围、例外、费用和制作周期影响。',
            'applicability' => $this->joined($capabilities, 'applicability')
                ?: '适用于 '.$site->name.' 客户提出的'.$titles->implode('、').'请求；具体商品结构和客户要求仍需逐项核对。',
            'required_facts' => ['当前商品的结构和属性', '客户要求的具体修改内容', '工厂确认的适用范围和例外', '费用规则', '对制作周期的影响'],
            'policy_rules' => $capabilities->flatMap(fn (ProductQuestionFactoryCapability $item): array => $this->rules($item))->values()->all(),
            'decision_tree' => $capabilities->map(fn (ProductQuestionFactoryCapability $item): array => $this->decision($item))->values()->all(),
            'reply_strategy' => [[
                'stage' => 'answer',
                'goal' => '根据已确认的工厂能力给出条件式答复',
                'content_requirements' => ['明确是否支持', '说明适用范围和例外', '说明费用和交期影响', '事实不足时不虚构后续工厂动作'],
            ]],
            'reply_templates' => [],
            'action_steps' => ['核对商品结构和客户具体要求', '匹配已确认的工厂能力范围', '应用费用和交期规则', '存在例外或未确认事项时保持人工审核'],
            'prohibited_claims' => ['工厂未确认前不得承诺可定制', '不得把某一款商品的历史答复推广到所有商品', '不得遗漏费用或交期影响', '不得承诺团队或工厂稍后联系客户'],
            'escalation_rules' => ['工厂能力仍待确认时转人工', '商品结构落入例外或逐款确认范围时转人工', '费用或交期规则缺失时转人工'],
            'evidence_question_ids' => $evidenceIds,
            'conflicts' => [],
            'open_questions' => $openQuestions,
            'confidence' => round((float) $capabilities->avg('confidence'), 4),
            'automation_tier' => 'draft_sensitive',
            'review_status' => 'candidate',
        ];

        return $intent === 'request_custom_size'
            ? array_replace($candidate, $this->customSizePolicy())
            : $candidate;
    }

    /** @return array<string,mixed> */
    private function customSizePolicy(): array
    {
        return [
            'scope' => 'global',
            'title' => 'Custom Size 定制尺码规则',
            'definition' => '客户询问商品是否支持 Custom Size、填写入口、费用、制作周期以及退换货限制。',
            'applicability' => '适用于所有站点的 dress 商品；仅当当前商品 customizable=1 时支持 Custom Size。',
            'required_facts' => [
                'site.site_key',
                'product.type',
                'product.customizable',
                'product.custom_size_entry_method',
                '客户填写的 bust、waist 和 hips（仅在判断超大尺码费用时需要）',
            ],
            'policy_rules' => [
                '仅 dress 类型且 customizable=1 的商品支持 Custom Size；customizable 不为 1 时不得承诺支持。',
                '支持时，客户在商品页 Size 选项中选择 Custom Size，并按页面字段填写准确测量值。',
                'DressAfford 和 EllenBridals 站点不收取 Custom Size 费用。',
                'DorrisWedding 和 DorrisBridal 站点中，bust、waist 或 hips 任一测量值超过 56 英寸时，会因额外面料和裁剪需求收取少量附加费；具体金额以商品页 Custom Size 选项显示为准。',
                'Custom Size 本身不增加正常制作周期；涉及活动日期时仍须使用当前商品和目的国的实时 ETA 判断，不承诺固定到货日期。',
                'Custom Size 商品不支持退货或换货，回复中必须简洁说明该限制。',
                'Custom Size 使用客户填写的准确测量值制作，并不提供独立 Petite 尺码。',
                '客户只询问填写入口时直接给操作步骤，不要求其在公开问答中提交测量值，也不默认转客服。',
            ],
            'decision_tree' => [
                ['condition' => '商品 type=dress 且 customizable=1', 'outcome' => '确认支持 Custom Size，并引导 Size → Custom Size → 填写页面测量字段。'],
                ['condition' => '商品 customizable 不为 1', 'outcome' => '明确说明当前商品不支持 Custom Size。'],
                ['condition' => 'DressAfford 或 EllenBridals 商品支持 Custom Size', 'outcome' => '说明 Custom Size 不另收费，且不增加正常制作周期，但不可退换。'],
                ['condition' => 'DorrisWedding 或 DorrisBridal 商品支持 Custom Size，且 bust、waist、hips 均不超过 56 英寸', 'outcome' => '不声称存在超大尺码附加费；以页面 Custom Size 选项显示为准。'],
                ['condition' => 'DorrisWedding 或 DorrisBridal 商品支持 Custom Size，且 bust、waist 或 hips 任一超过 56 英寸', 'outcome' => '说明会有少量附加费，准确金额以页面 Custom Size 选项显示为准。'],
                ['condition' => 'Custom Size 选项未显示、页面报错或商品事实不完整', 'outcome' => '不猜测支持情况或费用，进入人工审核。'],
            ],
            'reply_strategy' => [[
                'stage' => 'answer',
                'goal' => '根据当前商品和站点直接说明 Custom Size 能力与限制',
                'content_requirements' => ['先说明是否支持', '按站点说明费用', '说明不增加制作周期', '说明不可退换', '需要时给出页面填写入口'],
            ]],
            'reply_templates' => [
                [
                    'conditions' => ['dress、customizable=1、DressAfford 或 EllenBridals'],
                    'body' => 'Yes, this dress supports Custom Size at no extra charge. Select “Custom Size” under the Size options and enter your measurements. Custom sizing does not add to the normal production time. Please note that custom-size dresses cannot be returned or exchanged.',
                ],
                [
                    'conditions' => ['dress、customizable=1、DorrisWedding 或 DorrisBridal，未确认超过 56 英寸'],
                    'body' => 'Yes, this dress supports Custom Size. Select “Custom Size” under the Size options and enter your measurements. A small additional fee applies if any of your bust, waist, or hip measurements exceed 56 inches; the exact amount is shown in the Custom Size selector. Custom sizing does not add to the normal production time, and custom-size dresses cannot be returned or exchanged.',
                ],
                [
                    'conditions' => ['customizable 不为 1'],
                    'body' => 'This dress does not support Custom Size. Please choose from the standard sizes currently listed on the product page.',
                ],
            ],
            'action_steps' => ['读取当前商品 type 与 customizable', '读取当前 site.site_key', '确认 Custom Size 页面入口', '按站点和 56 英寸阈值说明费用', '说明制作周期不增加和不可退换限制'],
            'prohibited_claims' => ['不得在 customizable 不为 1 时承诺支持 Custom Size', '不得把 DorrisWedding 或 DorrisBridal 的超大尺码费用套用到 DressAfford 或 EllenBridals', '不得猜测或硬编码页面未提供的具体附加费金额', '不得声称 Custom Size 可以退货或换货', '不得承诺固定制作完成或到货日期', '不得要求客户在公开问答中提交身体测量值'],
            'escalation_rules' => ['商品 type、customizable 或站点无法确定', 'Custom Size 入口未出现或页面报错', '客户要求页面未提供的结构修改', '页面显示费用与本策略冲突'],
            'conflicts' => [],
            'open_questions' => [],
            'automation_tier' => 'draft_sensitive',
        ];
    }

    /** @return list<string> */
    private function rules(ProductQuestionFactoryCapability $capability): array
    {
        $prefix = $capability->title.'：';
        if ($capability->review_status === 'rejected') {
            return [$prefix.'工厂已确认不支持；回复必须明确说明不可提供。'];
        }
        if ($capability->review_status === 'needs_detail') {
            return [$prefix.'必须逐款确认，不得作为所有商品通用能力。'];
        }
        if ($capability->review_status !== 'confirmed') {
            return [$prefix.'当前仅为历史问答聚合，尚未由工厂确认，不得作为已验证能力使用。'];
        }

        return array_values(array_filter([
            $prefix.trim((string) $capability->factory_answer),
            filled($capability->exceptions) ? '例外和限制：'.trim($capability->exceptions) : null,
            filled($capability->fee_policy) ? '费用规则：'.trim($capability->fee_policy) : null,
            filled($capability->lead_time_policy) ? '交期影响：'.trim($capability->lead_time_policy) : null,
        ]));
    }

    /** @return array{condition:string,outcome:string} */
    private function decision(ProductQuestionFactoryCapability $capability): array
    {
        return match ($capability->review_status) {
            'confirmed' => ['condition' => $capability->title.'请求符合已确认适用范围且不属于例外', 'outcome' => '按已确认的费用和交期规则回复'],
            'rejected' => ['condition' => '客户提出'.$capability->title.'请求', 'outcome' => '明确说明工厂不支持，不提供虚构替代承诺'],
            'needs_detail' => ['condition' => '客户提出'.$capability->title.'请求', 'outcome' => '按当前商品结构逐款人工确认'],
            default => ['condition' => '客户提出'.$capability->title.'请求', 'outcome' => '能力尚未确认，保持 blocked 并等待人工审核'],
        };
    }

    /** @return list<string> */
    private function openQuestions(ProductQuestionFactoryCapability $capability): array
    {
        $prefix = '['.$capability->title.'] ';
        $questions = [];
        if ($capability->review_status === 'pending') {
            $questions[] = $prefix.$capability->factory_question;
        }
        foreach ([
            'applicability' => '适用于哪些商品、结构或请求？',
            'exceptions' => '有哪些例外、限制或必须逐款确认的情况？',
            'fee_policy' => '是否收费，费用如何计算？',
            'lead_time_policy' => '是否影响制作周期，如何计算？',
        ] as $field => $question) {
            if (blank($capability->{$field})) {
                $questions[] = $prefix.$question;
            }
        }

        return $questions;
    }

    private function joined(Collection $capabilities, string $field): string
    {
        return $capabilities->pluck($field)->filter()->unique()->implode("\n");
    }
}
