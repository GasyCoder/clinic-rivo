<?php

namespace App\Services\Medicine;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Keeps clinical notes deliberately small and predictable: formatting only,
 * with no links, media, styles, attributes or executable markup.
 */
class ClinicalRichTextSanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'u', 'mark', 'ul', 'ol', 'li'];

    /** @var list<string> */
    private const REMOVED_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'];

    public function sanitize(string $value): string
    {
        $value = trim($value);

        // Preserve legacy/plain notes byte-for-byte (apart from surrounding
        // whitespace). They are escaped only when projected as display HTML.
        if ($value === '' || ! str_contains($value, '<')) {
            return $value;
        }

        $document = $this->document($value);
        $root = $document->getElementById('clinical-rich-text-root');

        if (! $root) {
            return '';
        }

        foreach (iterator_to_array($root->childNodes) as $child) {
            $this->cleanNode($child);
        }

        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return trim($html);
    }

    public function toSafeHtml(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '<')) {
            // Canonicalise entities emitted by contenteditable without
            // double-encoding them on every read/save cycle.
            $plainText = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return nl2br(htmlspecialchars($plainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        return $this->sanitize($value);
    }

    public function plainText(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || ! str_contains($value, '<')) {
            return $value;
        }

        $document = $this->document($this->sanitize($value));
        $root = $document->getElementById('clinical-rich-text-root');

        return trim(html_entity_decode($root?->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function cleanNode(DOMNode $node): void
    {
        if ($node->nodeType === XML_COMMENT_NODE) {
            $node->parentNode?->removeChild($node);

            return;
        }

        if (! $node instanceof DOMElement) {
            return;
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::REMOVED_WITH_CONTENT, true)) {
            $node->parentNode?->removeChild($node);

            return;
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->cleanNode($child);
        }

        if ($tag === 'b' || $tag === 'i') {
            $node = $this->replaceTag($node, $tag === 'b' ? 'strong' : 'em');
            $tag = strtolower($node->tagName);
        } elseif ($tag === 'div') {
            $node = $this->replaceTag($node, 'p');
            $tag = 'p';
        } elseif ($tag === 'span') {
            $style = strtolower($node->getAttribute('style'));
            if (str_contains($style, 'background')) {
                $node = $this->replaceTag($node, 'mark');
                $tag = 'mark';
            } else {
                $this->unwrap($node);

                return;
            }
        }

        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            $this->unwrap($node);

            return;
        }

        while ($node->attributes->length > 0) {
            $node->removeAttributeNode($node->attributes->item(0));
        }
    }

    private function replaceTag(DOMElement $node, string $tag): DOMElement
    {
        $replacement = $node->ownerDocument->createElement($tag);

        foreach (iterator_to_array($node->attributes) as $attribute) {
            $replacement->setAttribute($attribute->name, $attribute->value);
        }

        while ($node->firstChild) {
            $replacement->appendChild($node->firstChild);
        }

        $node->parentNode?->replaceChild($replacement, $node);

        return $replacement;
    }

    private function unwrap(DOMElement $node): void
    {
        $parent = $node->parentNode;
        if (! $parent) {
            return;
        }

        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }

    private function document(string $value): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="clinical-rich-text-root">'.$value.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }
}
