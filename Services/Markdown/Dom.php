<?php

namespace Modules\AiChatPanel\Services\Markdown;

/**
 * The small DOM helpers both converters need.
 *
 * libxml is an HTML 4 parser. It mis-nests HTML5 sectioning elements and treats
 * Word's <o:p> as an unknown element — both are fine here, because every
 * unknown element is unwrapped rather than trusted.
 */
class Dom
{
    /**
     * Block-level element names, for deciding where a line break belongs.
     *
     * @var array
     */
    protected static $blocks = [
        'address', 'article', 'aside', 'blockquote', 'center', 'dd', 'div', 'dl',
        'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2',
        'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p',
        'pre', 'section', 'table', 'tbody', 'tfoot', 'thead', 'tr', 'ul',
    ];

    /**
     * Parse an HTML fragment.
     *
     * The charset is declared with a <meta> element rather than
     * mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), which is deprecated
     * on PHP 8.3. Without either, libxml assumes ISO-8859-1 and mangles every
     * non-ASCII character.
     *
     * @param string $html
     *
     * @return \DOMDocument|null Null when the fragment cannot be parsed at all.
     */
    public static function load($html)
    {
        $html = (string) $html;

        if (trim($html) === '') {
            return null;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $document->preserveWhiteSpace = true;

        $previous = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<!DOCTYPE html><html><head>'
            .'<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
            .'</head><body>'.$html.'</body></html>',
            LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        return $document;
    }

    /**
     * @param \DOMDocument $document
     *
     * @return \DOMElement|null
     */
    public static function body(\DOMDocument $document)
    {
        $body = $document->getElementsByTagName('body')->item(0);

        return $body instanceof \DOMElement ? $body : null;
    }

    /**
     * Serialise a container's children, without the container itself.
     *
     * saveHTML() on the document would emit the doctype and the <html><body>
     * wrapper load() added.
     *
     * @param \DOMNode $container
     *
     * @return string
     */
    public static function serialise(\DOMNode $container)
    {
        $document = $container->ownerDocument ?: $container;
        $html = '';

        foreach ($container->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return $html;
    }

    /**
     * Replace an element with a new one of a different name, keeping children.
     *
     * @param \DOMElement $element
     * @param string      $tag
     * @param array       $attributes
     *
     * @return \DOMElement The replacement.
     */
    public static function replace(\DOMElement $element, $tag, array $attributes = [])
    {
        $replacement = $element->ownerDocument->createElement($tag);

        foreach ($attributes as $name => $value) {
            if ($value !== null && $value !== '') {
                $replacement->setAttribute($name, $value);
            }
        }

        while ($element->firstChild) {
            $replacement->appendChild($element->firstChild);
        }

        $element->parentNode->replaceChild($replacement, $element);

        return $replacement;
    }

    /**
     * Drop an element but keep its children in place.
     *
     * @param \DOMNode $node
     *
     * @return void
     */
    public static function unwrap(\DOMNode $node)
    {
        if (!$node->parentNode) {
            return;
        }

        while ($node->firstChild) {
            $node->parentNode->insertBefore($node->firstChild, $node);
        }

        $node->parentNode->removeChild($node);
    }

    /**
     * One declaration out of an element's style attribute.
     *
     * @param \DOMElement $element
     * @param string      $property
     *
     * @return string Empty when the property is not set.
     */
    public static function styleOf(\DOMElement $element, $property)
    {
        $style = $element->getAttribute('style');

        if ($style === '') {
            return '';
        }

        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);

            if (count($parts) !== 2) {
                continue;
            }

            if (strtolower(trim($parts[0])) === strtolower($property)) {
                return trim($parts[1]);
            }
        }

        return '';
    }

    /**
     * @param string $tag
     *
     * @return bool
     */
    public static function isBlock($tag)
    {
        return in_array(strtolower((string) $tag), self::$blocks, true);
    }
}
