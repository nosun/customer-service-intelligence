<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updatePolicy('knowledge_articles', 'key', 'qa_global_request_custom_size', [
            'policy_rules' => [
                '产品问答已经绑定具体商品，回复不复述完整商品标题；需要帮助识别商品时可保留 SN。',
            ],
            'prohibited_claims' => [
                '不得在回复开头复述完整商品标题。',
            ],
        ]);
        $this->updatePolicy('product_question_policy_candidates', 'scenario_key', 'request_custom_size', [
            'policy_rules' => [
                '产品问答已经绑定具体商品，回复不复述完整商品标题；需要帮助识别商品时可保留 SN。',
            ],
            'prohibited_claims' => [
                '不得在回复开头复述完整商品标题。',
            ],
        ]);

        $deliveryRules = [
            '公开产品问答通常不具备连续追问能力；缺少目的国时，不要求客户回复国家，改为引导其选择商品选项并进入结账流程，在提交订单前查看当前预计到达时间，再与活动日期比较。',
            '产品问答已经绑定具体商品，回复不复述完整商品标题；需要帮助识别商品时可保留 SN。',
        ];
        $deliveryProhibitions = [
            '当结账流程可以让客户自行查看目的国 ETA 时，不得把“请回复配送国家”作为公开问答的结尾。',
            '不得在回复开头复述完整商品标题。',
        ];
        $this->updatePolicy('knowledge_articles', 'key', 'qa_global_ask_delivery_time', [
            'policy_rules' => $deliveryRules,
            'prohibited_claims' => $deliveryProhibitions,
            'reply_templates' => [[
                'conditions' => ['客户提供活动日期但未提供目的国'],
                'body' => 'For item {{ product.sn }}, select the product options and continue through checkout to review the current estimated arrival for your destination before submitting the order. Compare that estimate with your event date and allow a reasonable buffer, as the displayed arrival date is an estimate rather than a guarantee.',
            ]],
        ]);
        $this->updatePolicy('product_question_policy_candidates', 'scenario_key', 'ask_delivery_time', [
            'policy_rules' => $deliveryRules,
            'prohibited_claims' => $deliveryProhibitions,
            'reply_templates' => [[
                'conditions' => ['客户提供活动日期但未提供目的国'],
                'body' => 'For item {{ product.sn }}, select the product options and continue through checkout to review the current estimated arrival for your destination before submitting the order. Compare that estimate with your event date and allow a reasonable buffer, as the displayed arrival date is an estimate rather than a guarantee.',
            ]],
        ]);
    }

    public function down(): void
    {
        // Reviewed policy decisions are not reconstructed automatically.
    }

    /** @param array<string, list<mixed>> $changes */
    private function updatePolicy(string $table, string $keyColumn, string $key, array $changes): void
    {
        $record = DB::table($table)->where($keyColumn, $key)->first();
        if ($record === null) {
            return;
        }

        $updates = [];
        foreach ($changes as $field => $items) {
            $current = json_decode((string) ($record->{$field} ?? '[]'), true);
            $updates[$field] = json_encode(
                array_values(array_merge(is_array($current) ? $current : [], $items)),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        }
        $updates['updated_at'] = now();
        if ($table === 'knowledge_articles') {
            $updates['version'] = ((int) $record->version) + 1;
        }

        DB::table($table)->where('id', $record->id)->update($updates);
    }
};
