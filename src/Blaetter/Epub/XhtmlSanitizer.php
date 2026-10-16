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
     * Elements that are removed with their content. iframes with a URL are replaced by a link.
     */
    const REMOVED_ELEMENTS = [
        'script', 'style', 'iframe', 'object', 'embed', 'video', 'audio', 'source', 'track', 'form', 'input',
        'button', 'select', 'textarea', 'noscript', 'svg', 'canvas', 'map', 'area', 'param',
    ];

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

        $this->cleanNode($body);
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
     * Removes an element, an iframe with a URL is replaced by a link to it, e.g. a video.
     */
    private function removeElement(\DOMElement $element)
    {
        if ('iframe' === strtolower($element->nodeName)
            && null !== ($url = $this->normalizeUrl($element->getAttribute('src')))
        ) {
            $link = $element->ownerDocument->createElement('a');
            $link->setAttribute('href', $url);
            $link->appendChild($element->ownerDocument->createTextNode($url));
            $element->parentNode->replaceChild($link, $element);
            return;
        }
        $element->parentNode->removeChild($element);
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
