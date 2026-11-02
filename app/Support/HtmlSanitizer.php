<?php

namespace App\Support;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'del',
        'ul', 'ol', 'li', 'blockquote', 'code', 'pre',
        'h1', 'h2', 'h3', 'h4',
        'a', 'span', 'div', 'section', 'article', 'header', 'footer',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
    ];

    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'link', 'meta',
        'form', 'input', 'button', 'textarea', 'select', 'option',
        'svg', 'math', 'noscript', 'template',
    ];

    private const ALLOWED_STYLES = [
        'max-width', 'min-width', 'width', 'height', 'min-height', 'max-height',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'background', 'background-color', 'background-image',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-color', 'border-width', 'border-style', 'border-radius', 'box-shadow',
        'overflow', 'overflow-x', 'overflow-y',
        'position', 'top', 'right', 'bottom', 'left', 'z-index',
        'color', 'opacity',
        'font-family', 'font-size', 'font-weight', 'font-style', 'letter-spacing',
        'line-height', 'text-align', 'text-decoration', 'text-transform',
        'white-space', 'word-break', 'overflow-wrap', 'word-wrap',
        'display', 'flex', 'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink',
        'flex-basis', 'justify-content', 'align-items', 'align-self', 'align-content',
        'gap', 'row-gap', 'column-gap', 'order',
        'vertical-align', 'list-style', 'list-style-type', 'list-style-position',
    ];

    private const DANGEROUS_STYLE = '/url\s*\(|expression\s*\(|javascript:|vbscript:|@import|behavior\s*:|binding\s*:|image-set|cross-fade|\\\\|\/\*|\*\/|[\x00-\x08\x0b\x0c\x0e-\x1f]/i';

    private const HTML_PATTERN = '/<\s*\/?\s*(?:p|br|hr|strong|b|em|i|u|s|del|ul|ol|li|blockquote|code|pre|h[1-4]|a|table|thead|tbody|tfoot|tr|th|td|caption|span|div|section|article|header|footer)\b[^>]*>/i';

    public static function isHtml(string $content): bool
    {
        return (bool) preg_match(self::HTML_PATTERN, $content);
    }

    public static function normalize(string $content): string
    {
        return self::isHtml($content) ? self::clean($content) : trim($content);
    }

    public static function toHtml(string $content): string
    {
        if (! self::isHtml($content)) {
            return nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        }

        return self::clean($content);
    }

    public static function toText(string $content): string
    {
        if (self::isHtml($content)) {
            $content = preg_replace(
                '#<\s*(?:br|hr)\s*/?>|<\s*/\s*(?:p|div|section|article|header|footer|li|h[1-4]|tr|td|th|blockquote|pre)\s*>#i',
                ' ',
                $content
            ) ?? $content;
            $content = strip_tags($content);
        }

        $text = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public static function clean(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="__announcement_root">'.$content.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $xpath = new DOMXPath($document);
        $root = $xpath->query('//div[@id="__announcement_root"]')->item(0);
        if (! $root instanceof DOMElement) {
            return '';
        }

        self::sanitizeChildren($root);

        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return trim($html);
    }

    private static function sanitizeChildren(DOMNode $node): void
    {
        $child = $node->firstChild;

        while ($child) {
            $next = $child->nextSibling;

            if ($child instanceof DOMElement) {
                self::sanitizeElement($child, $node);
            } elseif ($child instanceof DOMComment) {
                $node->removeChild($child);
            }

            $child = $next;
        }
    }

    private static function sanitizeElement(DOMElement $element, DOMNode $parent): void
    {
        $tag = strtolower($element->tagName);

        if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
            $parent->removeChild($element);

            return;
        }

        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            $anchor = $element->nextSibling;
            $children = [];
            while ($element->firstChild) {
                $children[] = $element->removeChild($element->firstChild);
            }
            $parent->removeChild($element);

            foreach ($children as $child) {
                $parent->insertBefore($child, $anchor);
                if ($child instanceof DOMElement) {
                    self::sanitizeElement($child, $parent);
                }
            }

            return;
        }

        self::sanitizeAttributes($element, $tag);
        self::sanitizeChildren($element);
    }

    private static function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $allowed = false;

            if ($name === 'style') {
                $style = self::sanitizeStyle($attribute->value);
                if ($style !== '') {
                    $element->setAttribute('style', $style);
                } else {
                    $element->removeAttribute('style');
                }

                continue;
            }

            if ($tag === 'a' && in_array($name, ['href', 'title'], true)) {
                $allowed = true;
                if ($name === 'href' && ! self::isSafeHref($attribute->value)) {
                    $element->removeAttribute($attribute->name);

                    continue;
                }
            } elseif (in_array($tag, ['td', 'th'], true) && in_array($name, ['colspan', 'rowspan'], true)) {
                $allowed = ctype_digit(trim($attribute->value)) && (int) $attribute->value <= 100;
            }

            if (! $allowed) {
                $element->removeAttribute($attribute->name);
            }
        }

        if ($tag === 'a') {
            $href = $element->getAttribute('href');
            if ($href !== '') {
                $element->setAttribute('rel', 'noopener noreferrer');
                if (preg_match('/^https?:\/\//i', $href)) {
                    $element->setAttribute('target', '_blank');
                }
            }
        }
    }

    private static function sanitizeStyle(string $style): string
    {
        $declarations = [];

        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $property = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if ($property === '' || $value === '') {
                continue;
            }
            if (! in_array($property, self::ALLOWED_STYLES, true)) {
                continue;
            }
            if (strlen($value) > 300) {
                continue;
            }
            if (preg_match('/[<>{};\\\\]/', $value) || preg_match(self::DANGEROUS_STYLE, $value)) {
                continue;
            }

            $lower = strtolower($value);

            if ($property === 'position' && ! in_array($lower, ['relative', 'absolute'], true)) {
                continue;
            }
            if ($property === 'display' && ! in_array($lower, ['flex', 'block', 'inline', 'inline-block', 'none', 'grid'], true)) {
                continue;
            }
            if (($property === 'overflow' || str_starts_with($property, 'overflow-'))
                && ! in_array($lower, ['visible', 'hidden', 'auto', 'scroll'], true)) {
                continue;
            }
            if ($property === 'z-index' && ! preg_match('/^-?\d{1,3}$/', $value)) {
                continue;
            }

            $declarations[] = $property.':'.$value;
        }

        return implode(';', $declarations);
    }

    private static function isSafeHref(string $href): bool
    {
        $compact = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $href));

        return (bool) preg_match('/^(https?:\/\/|mailto:|\/|#)/', $compact);
    }
}
