<?php

namespace Tests\Unit;

use App\Services\ProductQuestions\ProductQuestionReplyFormatter;
use Tests\TestCase;

class ProductQuestionReplyFormatterTest extends TestCase
{
    public function test_links_and_paragraphs_become_html_without_changing_text(): void
    {
        $formatter = new ProductQuestionReplyFormatter;
        $input = "Please see [Return Policy](https://example.com/returns?a=1&b=2).\n\nFirst line.\nSecond line.";
        $expected = 'Please see <a href="https://example.com/returns?a=1&amp;b=2">Return Policy</a>.<br><br>First line.<br>Second line.';
        $this->assertSame($expected, $formatter->format($input));
        $this->assertSame($expected, $formatter->format($expected));
    }

    public function test_bare_urls_and_existing_html_links_are_not_nested(): void
    {
        $formatter = new ProductQuestionReplyFormatter;
        $this->assertSame('See <a href="https://example.com/returns">https://example.com/returns</a>.', $formatter->format('See https://example.com/returns.'));
        $this->assertSame('<a href="https://example.com">Policy</a><br><br>谢谢。', $formatter->format('<p><a href="https://example.com" target="_blank">Policy</a></p><p>谢谢。</p>'));
    }

    public function test_email_links_survive_repeated_formatting_and_empty_anchors_are_removed(): void
    {
        $formatter = new ProductQuestionReplyFormatter;
        $answer = 'Please email <a href="mailto:support@example.com">support@example.com</a>.';
        $this->assertSame($answer, $formatter->format($answer));
        $this->assertSame($answer, $formatter->format($formatter->format($answer)));
        $this->assertSame($answer, $formatter->format('Please email <a href="mailto:support@example.com"></a><a href="mailto:support@example.com">support@example.com</a>.'));
    }

    public function test_only_safe_html_and_link_protocols_are_rendered(): void
    {
        $formatter = new ProductQuestionReplyFormatter;
        $this->assertSame('Hello bad&lt;script&gt;alert(1)&lt;/script&gt;', $formatter->format('Hello <a href="javascript:alert(1)" onclick="alert(1)">bad</a><script>alert(1)</script><img src=x onerror=alert(1)>'));
        $this->assertSame('A &amp; B', $formatter->format('A & B'));
        $this->assertSame('', $formatter->format(''));
    }
}
