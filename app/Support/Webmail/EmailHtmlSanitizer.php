<?php

namespace App\Support\Webmail;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * ADR-195 — le HTML d'un message, rendu sûr.
 *
 * Lecture (`forDisplay`) : un message reçu vient de n'importe qui. Il est affiché
 * dans un cadre isolé (iframe sandbox, sans script) ; ce nettoyage en est la
 * première barrière :
 *
 *   - retire script, style externe, cadres, objets, formulaires, méta-rafraîchissement ;
 *   - retire tout attribut `on…` et toute adresse `javascript:` / `vbscript:` ;
 *   - remplace les images `cid:` par l'image jointe (donnée en ligne) ;
 *   - bloque les images distantes (pixels de pistage) tant qu'on ne les demande pas ;
 *   - ouvre les liens dans un nouvel onglet, sans référent.
 *
 * Écriture (`forSending`) : le corps rédigé dans RIVO ne garde qu'un jeu fermé de
 * balises de mise en forme — le navigateur n'est jamais cru sur parole.
 */
final class EmailHtmlSanitizer
{
    /** Balises retirées avec leur contenu. */
    private const DROPPED = ['script', 'noscript', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'base', 'svg', 'math', 'template', 'audio', 'video', 'source', 'track', 'title', 'head'];

    /** Ce que le corps rédigé dans RIVO peut contenir. */
    private const WRITING_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'mark', 'span', 'div', 'hr', 'a', 'code', 'pre'];

    /**
     * @param  array<string, string>  $inlineImages  cid => data URI
     * @return array{html: string, blocked_images: int}
     */
    public static function forDisplay(string $html, array $inlineImages = [], bool $allowRemoteImages = false): array
    {
        $document = self::load($html);
        $xpath = new DOMXPath($document);
        $blocked = 0;

        foreach (self::DROPPED as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (iterator_to_array($xpath->query('//*') ?: []) as $element) {
            /** @var DOMElement $element */
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = trim($attribute->nodeValue ?? '');

                if (str_starts_with($name, 'on') || in_array($name, ['srcdoc', 'formaction', 'xmlns', 'ping'], true)) {
                    $element->removeAttribute($attribute->nodeName);

                    continue;
                }

                if (in_array($name, ['href', 'src', 'background', 'action', 'lowsrc', 'dynsrc', 'poster', 'xlink:href'], true)
                    && preg_match('/^\s*(javascript|vbscript|data:text\/html)/i', $value)) {
                    $element->removeAttribute($attribute->nodeName);

                    continue;
                }

                if ($name === 'style' && preg_match('/expression\s*\(|javascript:|behavior\s*:|-moz-binding|@import/i', $value)) {
                    $element->removeAttribute('style');

                    continue;
                }

                if ($name === 'style' && ! $allowRemoteImages && preg_match('/url\s*\(\s*[\'"]?\s*(https?:)?\/\//i', $value)) {
                    $element->setAttribute('style', preg_replace('/url\s*\([^)]*\)/i', 'none', $value) ?? '');
                    $blocked++;
                }
            }

            $tag = strtolower($element->nodeName);

            if ($tag === 'img') {
                $src = trim($element->getAttribute('src'));

                if (preg_match('/^cid:(.+)$/i', $src, $match)) {
                    $cid = trim($match[1], '<> ');
                    $element->setAttribute('src', $inlineImages[$cid] ?? '');
                } elseif (preg_match('/^(https?:)?\/\//i', $src) && ! $allowRemoteImages) {
                    $element->removeAttribute('src');
                    $element->removeAttribute('srcset');
                    $element->setAttribute('alt', $element->getAttribute('alt') ?: '');
                    $blocked++;
                } elseif ($src !== '' && ! preg_match('/^(https?:|data:image\/)/i', $src)) {
                    $element->removeAttribute('src');
                }
            }

            if ($tag === 'a') {
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer nofollow');
            }

            if ($tag === 'style') {
                $css = $element->textContent;
                if (preg_match('/expression\s*\(|javascript:|behavior\s*:|-moz-binding|@import/i', $css)) {
                    $element->parentNode?->removeChild($element);
                } elseif (! $allowRemoteImages && preg_match('/url\s*\(\s*[\'"]?\s*(https?:)?\/\//i', $css)) {
                    $element->textContent = preg_replace('/url\s*\([^)]*\)/i', 'none', $css) ?? '';
                    $blocked++;
                }
            }
        }

        return ['html' => self::body($document), 'blocked_images' => $blocked];
    }

    /** Le texte brut d'un message, présenté en HTML : échappé, retours à la ligne gardés, liens cliquables. */
    public static function fromText(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linked = preg_replace('~(https?://[^\s<]+)~i', '<a href="$1" target="_blank" rel="noopener noreferrer nofollow">$1</a>', $escaped) ?? $escaped;

        return '<div style="white-space: pre-wrap; font-family: inherit;">'.$linked.'</div>';
    }

    /** Le corps rédigé dans RIVO : un jeu fermé de balises, sans aucun attribut sauf le lien. */
    public static function forSending(string $html): string
    {
        $document = self::load($html);
        $xpath = new DOMXPath($document);

        foreach (iterator_to_array($xpath->query('//body//*') ?: []) as $element) {
            /** @var DOMElement $element */
            $tag = strtolower($element->nodeName);

            if (in_array($tag, self::DROPPED, true) || $tag === 'style') {
                $element->parentNode?->removeChild($element);

                continue;
            }

            if (! in_array($tag, self::WRITING_TAGS, true)) {
                self::unwrap($element);

                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $keep = ($tag === 'a' && $name === 'href' && preg_match('/^(https?:|mailto:)/i', trim($attribute->nodeValue ?? '')))
                    || ($name === 'style' && preg_match('/^\s*text-align\s*:\s*(left|right|center|justify)\s*;?\s*$/i', $attribute->nodeValue ?? ''));

                if (! $keep) {
                    $element->removeAttribute($attribute->nodeName);
                }
            }
        }

        return self::body($document);
    }

    /** Le texte d'un corps HTML, pour la partie texte du message et les citations. */
    public static function toText(string $html): string
    {
        $html = preg_replace('~<(br|/p|/div|/li|/h[1-6]|/blockquote|/tr)\b[^>]*>~i', "$0\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    private static function load(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // Le préfixe impose l'UTF-8 ; LIBXML_NONET interdit tout chargement externe.
        $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private static function body(DOMDocument $document): string
    {
        $body = $document->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return '';
        }

        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return trim($html);
    }

    private static function unwrap(DOMNode $node): void
    {
        $parent = $node->parentNode;

        if ($parent === null) {
            return;
        }

        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }
}
