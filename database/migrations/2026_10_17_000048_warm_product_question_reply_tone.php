<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $replacements = [
            'This dress does not come with pockets by default, but we’d be happy to add them at no extra charge. Simply write “Please add pockets” in the Order Notes section at checkout.' => 'This dress does not come with pockets by default, but we’d be happy to add them for you at no extra charge. Simply write “Please add pockets” in the Order Notes section at checkout, and our tailors will take care of it.',
            'This dress does not come with pockets by default. Its fitted Mermaid/Trumpet silhouette is not suitable for adding them.' => 'This dress does not come with pockets by default. Please note that its fitted Mermaid/Trumpet silhouette is not suitable for adding them.',
            'This dress is available in standard US Size {{ requested_size }}, but we don’t offer a separate petite Size {{ requested_size }}. Our dresses are made according to the Size Chart, so please compare your bust, waist, hips, and hollow-to-floor measurements. For petite proportions, choose Custom Size and enter your exact measurements. Please note that custom-size dresses cannot be returned or exchanged.' => 'While we don’t offer a separate petite Size {{ requested_size }}, this dress is available in standard US Size {{ requested_size }}. We recommend comparing your bust, waist, hips, and hollow-to-floor measurements with our Size Chart. If you’d prefer a closer fit for petite proportions, you’re welcome to choose Custom Size and enter your exact measurements. Please note that custom-size dresses cannot be returned or exchanged.',
            'On the product page, open the Size options and select Custom Size. The measurement fields will then appear for you to complete. Enter the requested measurements, choose the other product options, and continue to add the dress to your cart.' => 'You can enter your measurements by opening the Size options and selecting Custom Size. Complete the measurement fields that appear, choose the other product options, and continue with your order.',
            'For standard US Size {{ size }}, the {{ hemline }} hollow-to-hem measurement is approximately {{ value_cm }} cm ({{ value_in }} in). This is measured from the hollow at the center base of the neck, not from the shoulder.' => 'For standard US Size {{ size }}, the {{ hemline }} hollow-to-hem measurement is approximately {{ value_cm }} cm ({{ value_in }} in). Just a quick note: this is measured from the hollow at the center base of the neck rather than from the shoulder.',
        ];

        DB::table('knowledge_articles')
            ->whereIn('key', [
                'qa_global_ask_pockets',
                'qa_global_request_add_pockets',
                'qa_global_ask_size_recommendation',
                'qa_global_request_custom_size',
                'qa_global_ask_dress_length',
            ])
            ->orderBy('id')
            ->each(function (object $article) use ($replacements): void {
                $templates = json_decode((string) $article->reply_templates, true);
                if (! is_array($templates)) {
                    return;
                }
                $changed = false;
                foreach ($templates as &$template) {
                    $body = $template['body'] ?? null;
                    if (is_string($body) && isset($replacements[$body])) {
                        $template['body'] = $replacements[$body];
                        $changed = true;
                    }
                }
                unset($template);
                if (! $changed) {
                    return;
                }
                DB::table('knowledge_articles')->where('id', $article->id)->update([
                    'reply_templates' => json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'version' => ((int) $article->version) + 1,
                    'source_notes' => trim((string) $article->source_notes.' 客服语气规范：保持事实与边界不变，使用温暖、礼貌、自然且简洁的表达。'),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Reviewed tone refinements are not reconstructed automatically.
    }
};
