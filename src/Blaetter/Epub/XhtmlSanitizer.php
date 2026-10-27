<?php

namespace Drupal\blaetter_export_epub\Blaetter\Epub;

use Drupal\Component\Utility\Html;

/**
 * Converts the HTML of the articles into XHTML 1.1, as required by EPUB 2.
 *
 * The articles are written for the website, so they contain embedded videos, presentational attributes,
 * Word artefacts like duplicate footnote anchors and links or images that only work on the website.
 */
class XhtmlSanitizer
{
    /**
     * Elements that are removed with their content. iframes with a URL are replaced by a link, except players.
     */
    const REMOVED_ELEMENTS = [
        'script', 'style', 'iframe', 'object', 'embed', 'video', 'audio', 'source', 'track', 'form', 'input',
        'button', 'select', 'textarea', 'noscript', 'svg', 'canvas', 'map', 'area', 'param',
    ];

    /**
     * Hosts of embedded players that are left out completely, as a link to the player is of no use in an ePub.
     */
    const DROPPED_EMBED_HOSTS = ['detektor.fm'];

    /**
     * The parents of the players left out by the current run, removed as well if nothing else is left in them.
     *
     * @var \DOMNode[]
     */
    private $emptied_parents = [];

    /**
     * The elements of XHTML 1.1. Other elements, e.g. font or figure, are replaced by their content.
     */
    const ALLOWED_ELEMENTS = [
        'div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'address',
        'blockquote', 'pre', 'hr', 'br', 'span', 'a', 'em', 'strong', 'dfn', 'code', 'samp', 'kbd', 'var',
        'cite', 'abbr', 'acronym', 'q', 'sub', 'sup', 'b', 'i', 'tt', 'big', 'small', 'del', 'ins', 'img',
        'table', 'caption', 'thead', 'tfoot', 'tbody', 'colgroup', 'col', 'tr', 'th', 'td', 'bdo',
    ];

    /**
     * Attributes allowed on every element. dir is only kept with a valid value.
     */
    const GLOBAL_ATTRIBUTES = ['id', 'class', 'style', 'title', 'lang', 'xml:lang', 'dir'];

    /**
     * Further attributes allowed on single elements.
     */
    const ELEMENT_ATTRIBUTES = [
        'a' => ['href', 'hreflang', 'type', 'charset', 'rel', 'rev'],
        'img' => ['src', 'alt', 'width', 'height', 'longdesc'],
        'blockquote' => ['cite'],
        'q' => ['cite'],
        'del' => ['cite', 'datetime'],
        'ins' => ['cite', 'datetime'],
        'table' => ['summary', 'width', 'border', 'frame', 'rules', 'cellspacing', 'cellpadding'],
        'col' => ['span', 'width', 'align', 'valign', 'char', 'charoff'],
        'colgroup' => ['span', 'width', 'align', 'valign', 'char', 'charoff'],
        'thead' => ['align', 'valign', 'char', 'charoff'],
        'tbody' => ['align', 'valign', 'char', 'charoff'],
        'tfoot' => ['align', 'valign', 'char', 'charoff'],
        'tr' => ['align', 'valign', 'char', 'charoff'],
        'th' => ['abbr', 'axis', 'headers', 'scope', 'rowspan', 'colspan', 'align', 'valign', 'char', 'charoff'],
        'td' => ['abbr', 'axis', 'headers', 'scope', 'rowspan', 'colspan', 'align', 'valign', 'char', 'charoff'],
    ];

    /**
     * The scheme and host of the website, e.g. https://www.blaetter.de, used for links relative to the website.
     *
     * @var string
     */
    private $base_url;

    /**
     * Resolves images of the website to files within the epub.
     *
     * Gets the src of the image and returns the path within the epub or null, if the image is not available.
     *
     * @var callable|null
     */
    private $image_resolver;

    /**
     * @param string $base_url The scheme and host of the website, empty to remove links relative to the website
     * @param callable|null $image_resolver Resolves images of the website, null to remove them
     */
    public function __construct($base_url = '', ?callable $image_resolver = null)
    {
        $this->base_url = rtrim((string) $base_url, '/');
        $this->image_resolver = $image_resolver;
    }

    /**
     * Converts the given HTML fragment into XHTML 1.1.
     *
     * @param string $html The HTML fragment, e.g. the body of an article
     * @return string The XHTML fragment
     */
    public function sanitize($html)
    {
        if ('' === trim((string) $html)) {
            return '';
        }
        $document = Html::load($html);
        $body = $document->getElementsByTagName('body')->item(0);

        $this->emptied_parents = [];
        $this->cleanNode($body);
        foreach ($this->emptied_parents as $parent) {
            if ($parent->parentNode && $this->isEmptyBlock($parent)) {
                $parent->parentNode->removeChild($parent);
            }
        }
        $this->collectFootnotes($document);
        $this->collapseNestedSup($document);
        $this->cleanIds($document);
        $this->cleanFragmentLinks($document);

        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= $document->saveXML($child);
        }
        // Carriage returns of the original HTML are serialized as entity, they are not needed.
        return str_replace('&#13;', '', $output);
    }

    /**
     * Returns a valid absolute URL for a link or null, if the link is not usable within the epub.
     *
     * @param string $url The URL, e.g. from an href attribute or an image credit
     * @return string|null
     */
    public function normalizeUrl($url)
    {
        $url = trim(html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ('' === $url) {
            return null;
        }
        if (preg_match('/^www\./i', $url)) {
            $url = 'https://' . $url;
        } elseif ('/' === $url[0] && '/' !== ($url[1] ?? '') && '' !== $this->base_url) {
            $url = $this->base_url . $url;
        }
        if (!preg_match('/^(https?:\/\/|mailto:)/i', $url)) {
            return null;
        }
        // encode characters that are not allowed in URLs, e.g. spaces in links to Wikimedia Commons
        $url = preg_replace_callback(
            '/[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]/u',
            fn ($match) => rawurlencode($match[0]),
            $url
        );
        return filter_var($url, FILTER_VALIDATE_URL) || 0 === stripos($url, 'mailto:') ? $url : null;
    }

    /**
     * Cleans the children of the given node recursively.
     */
    private function cleanNode(\DOMNode $node)
    {
        // copy the list, as it changes while removing nodes
        $children = iterator_to_array($node->childNodes);
        foreach ($children as $child) {
            if ($child instanceof \DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $name = strtolower($child->nodeName);

            if (in_array($name, self::REMOVED_ELEMENTS, true)) {
                $this->removeElement($child);
                continue;
            }

            $this->cleanNode($child);

            if (!in_array($name, self::ALLOWED_ELEMENTS, true)) {
                $this->unwrap($child);
                continue;
            }

            $this->cleanAttributes($child, $name);

            if ('a' === $name) {
                $this->cleanLink($child);
            } elseif ('img' === $name) {
                $this->cleanImage($child);
            }
        }
    }

    /**
     * Removes an element, an iframe with a URL is replaced by a link to it, e.g. a video, unless it is a player.
     */
    private function removeElement(\DOMElement $element)
    {
        if ('iframe' === strtolower($element->nodeName)
            && null !== ($url = $this->normalizeUrl($element->getAttribute('src')))
        ) {
            if ($this->isDroppedEmbed($url)) {
                $this->emptied_parents[] = $element->parentNode;
                $element->parentNode->removeChild($element);
                return;
            }
            $link = $element->ownerDocument->createElement('a');
            $link->setAttribute('href', $url);
            $link->appendChild($element->ownerDocument->createTextNode($url));
            $element->parentNode->replaceChild($link, $element);
            return;
        }
        $element->parentNode->removeChild($element);
    }

    /**
     * Whether the URL of an iframe belongs to a player that is left out, e.g. a podcast of detektor.fm.
     */
    private function isDroppedEmbed($url)
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::DROPPED_EMBED_HOSTS as $dropped) {
            if ($host === $dropped || str_ends_with($host, '.' . $dropped)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Replaces an element by its children.
     */
    private function unwrap(\DOMElement $element)
    {
        while ($element->firstChild) {
            $element->parentNode->insertBefore($element->firstChild, $element);
        }
        $element->parentNode->removeChild($element);
    }

    /**
     * Removes the attributes, that are not allowed on the element.
     */
    private function cleanAttributes(\DOMElement $element, $name)
    {
        $allowed = array_merge(self::GLOBAL_ATTRIBUTES, self::ELEMENT_ATTRIBUTES[$name] ?? []);
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $attribute_name = strtolower($attribute->nodeName);
            if (!in_array($attribute_name, $allowed, true)
                || ('dir' === $attribute_name && !in_array($attribute->value, ['ltr', 'rtl'], true))
            ) {
                $element->removeAttributeNode($attribute);
            }
        }
    }

    /**
     * Links to the web or to footnotes are kept, all other links are replaced by their text.
     */
    private function cleanLink(\DOMElement $link)
    {
        if (!$link->hasAttribute('href')) {
            return;
        }
        $href = trim($link->getAttribute('href'));
        if ('#' === ($href[0] ?? '')) {
            // the target is checked by cleanFragmentLinks(), when all ids are known
            return;
        }
        $url = $this->normalizeUrl($href);
        if (null === $url) {
            $this->unwrap($link);
            return;
        }
        $link->setAttribute('href', $url);
    }

    /**
     * Images of the website are added to the epub, images that are not available are removed.
     */
    private function cleanImage(\DOMElement $image)
    {
        $src = trim($image->getAttribute('src'));
        if (!preg_match('/^https?:\/\//i', $src)) {
            $resolved = '' !== $src && null !== $this->image_resolver
                ? call_user_func($this->image_resolver, $src)
                : null;
            if (null === $resolved) {
                $image->parentNode->removeChild($image);
                return;
            }
            $image->setAttribute('src', $resolved);
        }
        if (!$image->hasAttribute('alt')) {
            $image->setAttribute('alt', '');
        }
    }

    /**
     * Moves the footnotes of an article into one block "Ebook-Fussnoten", one after the other.
     *
     * Word (div#ftn1, anchors #_ftn1) and LibreOffice (div#sdfootnote1) footnotes arrive with unclosed divs, so each
     * footnote is nested within the previous one. The block replaces this nesting and gets its separator from the
     * styles; empty paragraphs right before it, which only served as spacing, are removed. Ids and links stay.
     */
    private function collectFootnotes(\DOMDocument $document)
    {
        $xpath = new \DOMXPath($document);
        $footnotes = [];
        foreach ($xpath->query('//body//div[@id]') as $div) {
            if (preg_match('/^(ftn|sdfootnote)\d+$/', $div->getAttribute('id'))) {
                $footnotes[] = $div;
            }
        }
        if (!$footnotes) {
            // Word footnotes without their div: the paragraph of the anchor is the footnote
            foreach ($xpath->query('//body//a[starts-with(@id, "_ftn")]') as $anchor) {
                $block = $anchor->parentNode;
                while ($block && !in_array(strtolower($block->nodeName), ['p', 'div', 'body'], true)) {
                    $block = $block->parentNode;
                }
                if (preg_match('/^_ftn\d+$/', $anchor->getAttribute('id')) && $block
                    && 'body' !== strtolower($block->nodeName) && !in_array($block, $footnotes, true)
                ) {
                    $footnotes[] = $block;
                }
            }
        }
        if (!$footnotes) {
            return;
        }

        $block = $document->createElement('div');
        $block->setAttribute('class', 'Ebook-Fussnoten');
        $footnotes[0]->parentNode->insertBefore($block, $footnotes[0]);
        // in document order: appending a footnote moves it out of the one it was nested in
        foreach ($footnotes as $footnote) {
            $block->appendChild($footnote);
        }

        // the block draws the separating line itself, so the line Word puts above the footnotes goes as well
        while (($previous = $block->previousSibling)
            && ($this->isEmptyBlock($previous) || 'hr' === strtolower($previous->nodeName))
        ) {
            $previous->parentNode->removeChild($previous);
        }
    }

    /**
     * Whether a node is whitespace or an empty paragraph, which only served as spacing.
     */
    private function isEmptyBlock(\DOMNode $node)
    {
        if (XML_TEXT_NODE === $node->nodeType) {
            return '' === trim($node->textContent, " \t\n\r\0\x0B\u{A0}");
        }
        return XML_ELEMENT_NODE === $node->nodeType && 'p' === strtolower($node->nodeName)
            && '' === trim($node->textContent, " \t\n\r\0\x0B\u{A0}")
            && 0 === $node->getElementsByTagName('img')->length;
    }

    /**
     * Collapses superscripts within superscripts (<sup><sup>[1]</sup></sup>, from Word footnotes) into one.
     */
    private function collapseNestedSup(\DOMDocument $document)
    {
        $xpath = new \DOMXPath($document);
        foreach (iterator_to_array($xpath->query('//body//sup[sup]')) as $outer) {
            $elements = 0;
            foreach ($outer->childNodes as $child) {
                if (XML_ELEMENT_NODE === $child->nodeType) {
                    $elements++;
                } elseif ('' !== trim($child->textContent)) {
                    // text next to the inner superscript: keep both
                    continue 2;
                }
            }
            if (1 === $elements) {
                $this->unwrap($outer);
            }
        }
    }

    /**
     * Ids have to be valid XML names and unique within the chapter, e.g. Word creates two anchors per footnote.
     */
    private function cleanIds(\DOMDocument $document)
    {
        $ids = [];
        foreach ((new \DOMXPath($document))->query('//body//*[@id]') as $element) {
            $id = $element->getAttribute('id');
            if (isset($ids[$id]) || !preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*$/', $id)) {
                $element->removeAttribute('id');
                // remove empty anchors, that only existed for the duplicate id
                if ('a' === strtolower($element->nodeName) && '' === trim($element->textContent)
                    && !$element->hasChildNodes()
                ) {
                    $element->parentNode->removeChild($element);
                }
                continue;
            }
            $ids[$id] = true;
        }
    }

    /**
     * Links to fragments, that do not exist within the chapter, are replaced by their text.
     */
    private function cleanFragmentLinks(\DOMDocument $document)
    {
        $xpath = new \DOMXPath($document);
        $links = iterator_to_array($xpath->query('//body//a[starts-with(normalize-space(@href), "#")]'));
        foreach ($links as $link) {
            $fragment = substr(trim($link->getAttribute('href')), 1);
            if ('' === $fragment || 0 === $xpath->query('//*[@id="' . str_replace('"', '', $fragment) . '"]')->length) {
                $this->unwrap($link);
            }
        }
    }
}
