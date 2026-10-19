<?php

namespace App\Services\ProductQuestions;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Str;

class ProductQuestionReplyFormatter
{
    public function format(string $answer): string
    {
        if (trim($answer) === '') {
            return '';
        }

        // Keep existing anchors out of Markdown autolinking, especially email labels.
        $anchors = [];
        $prefix = 'REPLYANCHOR'.bin2hex(random_bytes(8));
        $answer = preg_replace_callback('~<a\b[^>]*>.*?</a>~is', function (array $match) use (&$anchors, $prefix): string {
            $key = $prefix.count($anchors).'END';
            $anchors[$key] = $match[0];

            return $key;
        }, $answer) ?? $answer;
        $html = strtr(Str::markdown(trim($answer), ['html_input' => 'allow', 'allow_unsafe_links' => false]), $anchors);
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><div id="reply-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->getElementById('reply-root');
        $result = $root === null ? '' : $this->children($root);

        return preg_replace('/^(?:<br>)+|(?:<br>)+$/', '', trim($result)) ?? '';
    }

    private function children(DOMNode $node, bool $insideLink = false): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = str_replace(["\r\n", "\r"], "\n", $child->textContent);
                if (trim($text) === '' && str_contains($text, "\n")) {
                    continue;
                }
                $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                if (! $insideLink) {
                    $text = preg_replace_callback('~https?://[^\s<>]+~u', function (array $match): string {
                        $url = rtrim($match[0], '.,;:!?)');

                        return '<a href="'.$url.'">'.$url.'</a>'.substr($match[0], strlen($url));
                    }, $text) ?? $text;
                }
                $html .= str_replace("\n", '<br>', $text);
            } elseif ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'svg', 'img'], true)) {
                    continue;
                }
                $content = $this->children($child, $insideLink || $tag === 'a');
                if ($tag === 'a') {
                    $url = trim($child->getAttribute('href'));
                    if ($content === '') {
                        continue;
                    }
                    $html .= preg_match('~^(?:https?://|mailto:)[^\s<>]+$~iu', $url)
                        ? '<a href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$content.'</a>'
                        : $content;
                } elseif ($tag === 'br') {
                    $html .= '<br>';
                } elseif (in_array($tag, ['p', 'div'], true)) {
                    $html .= $content.'<br><br>';
                } elseif (in_array($tag, ['strong', 'em', 'ul', 'ol', 'li'], true)) {
                    $html .= '<'.$tag.'>'.$content.'</'.$tag.'>';
                } else {
                    $html .= $content;
                }
            }
        }

        return $html;
    }
}
