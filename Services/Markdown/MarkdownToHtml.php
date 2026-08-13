<?php

namespace Modules\AiChatPanel\Services\Markdown;

/**
 * Markdown to HTML, for one of the two destinations in EditorHtmlProfile.
 *
 * Parsedown produces canonical HTML — <p>, <code>, <pre><code>, <hr>, <del>.
 * That is exactly right for the chat panel and wrong for a thread body, where
 * core's purifier drops <code>, <hr> and <del> outright. So for the editor
 * target the canonical output is rewritten in the DOM first, into elements core
 * keeps, carrying the inline styles that make them look like what they replaced
 * in both Summernote and a mail client.
 *
 * Model output is untrusted — the model reads customer-written thread content,
 * so "the model would not do that" is not a security argument. Parsedown's own
 * escaping is deliberately not relied on: 1.7.2 has known holes and it happily
 * passes raw HTML through. HTMLPurifier is the trust boundary.
 *
 * Nothing here throws. A conversion bug must degrade a draft, never break the
 * panel.
 */
class MarkdownToHtml
{
    /**
     * Markdown to HTML that Summernote edits, core's purifier passes through
     * unchanged, and a mail client renders.
     *
     * @param string $markdown
     *
     * @return string
     */
    public static function toEditorHtml($markdown)
    {
        return self::convert($markdown, EditorHtmlProfile::editor());
    }

    /**
     * Markdown to HTML for a chat bubble.
     *
     * @param string $markdown
     *
     * @return string
     */
    public static function toPanelHtml($markdown)
    {
        return self::convert($markdown, EditorHtmlProfile::panel());
    }

    /**
     * @param string             $markdown
     * @param EditorHtmlProfile  $profile
     *
     * @return string
     */
    public static function convert($markdown, EditorHtmlProfile $profile)
    {
        $markdown = (string) $markdown;

        if (trim($markdown) === '') {
            return '';
        }

        try {
            $parsedown = new \Parsedown();
            // Do not let Parsedown decide what is safe; HTMLPurifier does that.
            $parsedown->setBreaksEnabled($profile->breaksEnabled());

            $html = $parsedown->text($markdown);

            if ($profile->retargets()) {
                $html = self::retarget($html, $profile);
            }
        } catch (\Exception $e) {
            \Helper::logException($e, '[AiChatPanel] Markdown rendering failed: ');

            // Fall back to escaped plain text rather than showing nothing.
            return $profile->fallback($markdown);
        }

        return $profile->flatten($profile->purify($html));
    }

    /**
     * Rewrite Parsedown's canonical HTML into the profile's element set.
     *
     * @param string            $html
     * @param EditorHtmlProfile $profile
     *
     * @return string
     */
    protected static function retarget($html, EditorHtmlProfile $profile)
    {
        $document = Dom::load($html);

        if (!$document) {
            return $html;
        }

        $body = Dom::body($document);

        if (!$body) {
            return $html;
        }

        foreach (iterator_to_array($body->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                self::rewriteNode($child, $profile);
            }
        }

        return Dom::serialise($body);
    }

    /**
     * @param \DOMElement       $element
     * @param EditorHtmlProfile $profile
     *
     * @return void
     */
    protected static function rewriteNode(\DOMElement $element, EditorHtmlProfile $profile)
    {
        $tag = strtolower($element->nodeName);
        $node = $element;

        switch ($tag) {
            case 'p':
                $node = Dom::replace($element, $profile->blockTag());
                break;

            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
            case 'ul':
            case 'ol':
            case 'li':
            case 'blockquote':
                self::addStyle($element, $profile->style($tag));
                break;

            case 'hr':
                // <hr> is not in core's whitelist. The &nbsp; is not decorative:
                // AutoFormat.RemoveEmpty deletes an element whose content is
                // whitespace, and a non-breaking space is not whitespace.
                $node = Dom::replace($element, 'div', ['style' => $profile->style('hr')]);
                $node->appendChild($node->ownerDocument->createTextNode("\xC2\xA0"));

                return;

            case 'del':
                // core allows <s>, not <del>.
                $node = Dom::replace($element, 's');
                break;

            case 'img':
                // See EditorHtmlProfile: no images in a thread body. A base64
                // data URI would be stored verbatim in threads.body — core only
                // rewrites those for user-submitted replies.
                $element->parentNode->removeChild($element);

                return;

            case 'pre':
                self::addStyle($element, $profile->style('pre'));

                // <code> inside <pre> is dropped by core, taking its content
                // with it in some purifier configurations. Unwrap it here so
                // the code itself is plain text inside a styled <pre>.
                foreach (iterator_to_array($element->childNodes) as $child) {
                    if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'code') {
                        Dom::unwrap($child);
                    }
                }
                break;

            case 'code':
                $node = Dom::replace($element, 'span', ['style' => $profile->style('code')]);
                break;

            case 'table':
                foreach ($profile->tableAttributes() as $name => $value) {
                    if ($value !== '') {
                        $element->setAttribute($name, $value);
                    }
                }
                break;

            case 'td':
            case 'th':
                // Parsedown may already have put text-align here. The cell
                // borders and padding come next, matching what .table-bordered
                // gives a table an agent inserts with the editor's own button.
                self::addStyle($element, $profile->style($tag));
                break;

            case 'a':
                // Meaningless in an email, and core strips rel, which would
                // make our output differ from core's re-purified copy.
                $element->removeAttribute('target');
                $element->removeAttribute('rel');
                break;
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                self::rewriteNode($child, $profile);
            }
        }
    }

    /**
     * Append declarations to an element's style attribute.
     *
     * @param \DOMElement $element
     * @param string      $style
     *
     * @return void
     */
    protected static function addStyle(\DOMElement $element, $style)
    {
        if ($style === '') {
            return;
        }

        $existing = trim($element->getAttribute('style'));

        if ($existing !== '' && substr($existing, -1) !== ';') {
            $existing .= ';';
        }

        $element->setAttribute('style', trim($existing.' '.$style));
    }
}
