<?php

namespace Modules\AiChatPanel\Services;

use Modules\AiChatPanel\Services\Markdown\EditorHtmlProfile;
use Modules\AiChatPanel\Services\Markdown\MarkdownToHtml;

/**
 * Renders model output to safe HTML for the chat panel.
 *
 * Model output is untrusted — the model is influenced by customer-written
 * thread content, so "the model would not do that" is not a security argument.
 * Everything it produces goes through Parsedown and then HTMLPurifier with our
 * own allowlist. Parsedown's own escaping is deliberately not relied on: 1.7.2
 * has known holes, and it happily passes raw HTML through.
 *
 * The allowlist is stricter than core's \Helper::purifyHtml():
 *   - no <img>: an image URL in model output is a request to an arbitrary host
 *     from the agent's browser, i.e. a tracking pixel at best;
 *   - <code> survives inside <pre>, which core's config strips.
 *
 * Both libraries already ship in core/vendor, so this costs no dependency.
 *
 * The rules themselves live in Markdown\EditorHtmlProfile now, because the same
 * model output also has to become HTML for the reply editor, where core's much
 * narrower whitelist applies. This class is the panel-shaped view of that: same
 * configuration, same output as before.
 */
class MarkdownRenderer
{
    /**
     * Markdown to sanitised HTML.
     *
     * @param string $markdown
     *
     * @return string
     */
    public static function render($markdown)
    {
        return MarkdownToHtml::convert($markdown, EditorHtmlProfile::panel());
    }

    /**
     * Sanitise HTML with the chat allowlist.
     *
     * @param string $html
     *
     * @return string
     */
    public static function purify($html)
    {
        return EditorHtmlProfile::panel()->purify($html);
    }

    /**
     * The allowlist, as a plain array so the JS side can be kept in step.
     *
     * @return array
     */
    public static function allowedTags()
    {
        return EditorHtmlProfile::panel()->allowedTags();
    }
}
