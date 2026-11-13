<?php

namespace App\Services\CustomerContacts;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class MultiDressTemplate
{
    public const VERSION = 'multi_dress.first_round.v1';

    public function __construct(private readonly MultiDressPolicy $policy) {}

    public function render(array $snapshot, array $analysis): array
    {
        $i = $this->policy->identity($snapshot);
        Validator::make($analysis, [
            'question_module' => 'required|in:purpose_and_keep_plan,quantity_and_purpose,keep_plan_only,selection_help',
        ])->validate();
        $site = config('customer_contacts.sites.'.$i['site_key']);
        if (($analysis['question_module'] === 'keep_plan_only' && ! ($snapshot['purpose_known'] ?? false))
            || ($analysis['question_module'] === 'selection_help' && ! ($snapshot['partial_keep_known'] ?? false))) {
            throw new InvalidArgumentException('Question module requires explicit confirmed customer intent.');
        }
        if (! ($snapshot['order']['on_hold'] ?? false) || ! ($snapshot['policy']['verified'] ?? false)
            || ($snapshot['policy']['url'] ?? '') !== $site['policy_url']) {
            throw new InvalidArgumentException('Hold or site policy has not been verified.');
        }
        $lines = [];
        foreach ($this->policy->dresses($snapshot) as $item) {
            foreach (['item_code', 'color', 'size'] as $key) {
                if (blank($item[$key] ?? null) || preg_match('/[\r\n]/', $item[$key])) {
                    throw new InvalidArgumentException('Missing or invalid item attribute.');
                }
            }
            $lines[] = "Item {$item['item_code']} — Color: {$item['color']}, Size: {$item['size']}, Quantity: {$item['quantity']}";
        }
        $question = match ($analysis['question_module']) {
            'quantity_and_purpose' => 'Could you confirm that the quantity is intentional, and let us know whether the dresses are for different people or uses?',
            'keep_plan_only' => '',
            'selection_help' => 'Which items and quantities would you like to keep? If you are still deciding, please let us know what you need help with.',
            default => 'Are these dresses for different people or occasions, or are you planning to compare styles or sizes after they arrive?',
        };
        $choices = $analysis['question_module'] === 'selection_help'
            ? 'Please identify the items and quantities you are considering keeping so our team can help you review your options before production.'
            : "Please reply to let us know your plans:\n\n- If you plan to keep all the dresses, please confirm this.\n- If you would like to keep only some, please identify the items and quantities.\n- If you are still deciding on a style or size, let us know what you are unsure about so our team can help you review the options before production.";
        $mail = ['subject' => 'Please confirm the dresses in your order '.$i['order_number'],
            'body' => "Hi there,\n\nThank you for your order with {$site['brand']}. Your order is currently on hold while we confirm your selection. Please reply to this email within 48 hours to let us know which dresses you would like to keep.\n\nYour order includes the following dresses:\n\n".implode("\n", $lines)
                ."\n\nOur return policy does not support ordering multiple dresses with the intention of keeping only one and returning the rest. Any return request remains subject to the conditions and exclusions in our return policy:\n{$site['policy_url']}\n\n".($question === '' ? '' : $question."\n\n").$choices."\n\nBest regards,\n{$site['brand']} Customer Service\n",
            'template_version' => self::VERSION];
        $mail['content_hash'] = self::contentHash($mail);

        return $mail;
    }

    public static function contentHash(array $mail): string
    {
        return hash('sha256', json_encode([$mail['subject'], $mail['body']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
