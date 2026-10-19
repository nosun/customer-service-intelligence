<?php

namespace Tests\Unit;

use App\Models\ProductQuestion;
use App\Services\ProductQuestions\N8nProductQuestionDraftService;
use ReflectionMethod;
use Tests\TestCase;

class ProductQuestionDraftLinkSanitizationTest extends TestCase
{
    public function test_removing_product_urls_keeps_link_labels_and_other_policy_links(): void
    {
        $method = new ReflectionMethod(N8nProductQuestionDraftService::class, 'sanitizeProductIdentity');
        $question = new ProductQuestion(['sn' => 'QA-901']);
        $url = 'https://dressafford.test/product.html';
        foreach (['('.$url.')', '(<'.$url.'>)'] as $target) {
            $answer = $method->invoke(app(N8nProductQuestionDraftService::class),
                'See the [product page]'.$target.' and [return policy](https://dressafford.test/returns).',
                ['product' => ['product_url' => $url]], $question);
            $this->assertSame('See the product page and [return policy](https://dressafford.test/returns).', $answer);
        }
    }
}
