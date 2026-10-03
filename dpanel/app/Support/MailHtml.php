<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Strips active content from an HTML mail body so it can be shown with its
 * own layout. The page also renders it in a sandboxed iframe without
 * scripts; this is the second layer.
 */
class MailHtml
{
    private const REMOVED_TAGS = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'link', 'meta', 'form', 'noscript', 'svg', 'math', 'template'];

    private const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'background', 'poster', 'xlink:href', 'srcset'];

    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        foreach (self::REMOVED_TAGS as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (iterator_to_array($xpath->query('//*')) as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = (string) $attribute->nodeValue;

                if (str_starts_with($name, 'on')) {
                    $element->removeAttribute($attribute->nodeName);
                } elseif (in_array($name, self::URL_ATTRIBUTES, true) && self::unsafeUrl($value)) {
                    $element->removeAttribute($attribute->nodeName);
                } elseif ($name === 'style' && preg_match('/expression\s*\(|javascript:|behavior\s*:|-moz-binding/i', $value)) {
                    $element->removeAttribute($attribute->nodeName);
                }
            }

            if (strtolower($element->tagName) === 'a' && $element->hasAttribute('href')) {
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }

        foreach (iterator_to_array($document->getElementsByTagName('style')) as $style) {
            $style->textContent = preg_replace('/expression\s*\(|javascript:|behavior\s*:|-moz-binding|@import[^;]*;?/i', '', $style->textContent) ?? '';
        }

        $head = '';
        foreach (iterator_to_array($document->getElementsByTagName('style')) as $style) {
            $head .= $document->saveHTML($style);
            $style->parentNode?->removeChild($style);
        }

        $body = $document->getElementsByTagName('body')->item(0);
        $content = '';
        if ($body !== null) {
            foreach ($body->childNodes as $child) {
                $content .= $document->saveHTML($child);
            }
        } else {
            $content = (string) $document->saveHTML();
        }

        return trim($head.$content);
    }

    private static function unsafeUrl(string $value): bool
    {
        $value = strtolower(preg_replace('/[\x00-\x20]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return str_starts_with($value, 'javascript:')
            || str_starts_with($value, 'vbscript:')
            || (str_starts_with($value, 'data:') && ! str_starts_with($value, 'data:image/'));
    }
}
