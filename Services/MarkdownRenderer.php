<?php

namespace Modules\AiChatPanel\Services;

/**
 * Renders model output to safe HTML.
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
 */
class MarkdownRenderer
{
    /** @var \HTMLPurifier|null */
    protected static $purifier = null;

    /**
     * Markdown to sanitised HTML.
     *
     * @param string $markdown
     *
     * @return string
     */
    public static function render($markdown)
    {
        $markdown = (string) $markdown;

        if (trim($markdown) === '') {
            return '';
        }

        try {
            $parsedown = new \Parsedown();
            // Do not let Parsedown decide what is safe; HTMLPurifier does that.
            $parsedown->setBreaksEnabled(true);

            $html = $parsedown->text($markdown);
        } catch (\Exception $e) {
            \Helper::logException($e, '[AiChatPanel] Markdown rendering failed: ');

            // Fall back to escaped plain text rather than showing nothing.
            return '<p>'.nl2br(htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8')).'</p>';
        }

        return self::purify($html);
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
        try {
            return self::purifier()->purify((string) $html);
        } catch (\Exception $e) {
            \Helper::logException($e, '[AiChatPanel] Sanitising model output failed: ');

            // Never return unsanitised HTML on the error path.
            return '<p>'.htmlspecialchars(strip_tags((string) $html), ENT_QUOTES, 'UTF-8').'</p>';
        }
    }

    /**
     * The allowlist, as a plain array so the JS side can be kept in step.
     *
     * @return array
     */
    public static function allowedTags()
    {
        return [
            'p', 'br', 'strong', 'em', 'b', 'i', 'del', 'code', 'pre',
            'ul', 'ol', 'li', 'blockquote',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr',
            'a[href|title]',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
        ];
    }

    /**
     * @return \HTMLPurifier
     */
    protected static function purifier()
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }

        $config = \HTMLPurifier_Config::createDefault();

        $config->set('HTML.Allowed', implode(',', self::allowedTags()));
        $config->set('URI.AllowedSchemes', [
            'http'   => true,
            'https'  => true,
            'mailto' => true,
        ]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', true);
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('AutoFormat.RemoveEmpty', true);

        // Reuse the cache directory core's purifier already maintains; fall
        // back to no definition cache rather than failing on a read-only disk.
        $cache_path = config('purifier.cachePath');

        if ($cache_path && is_dir($cache_path) && is_writable($cache_path)) {
            $config->set('Cache.SerializerPath', $cache_path);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }

        self::$purifier = new \HTMLPurifier($config);

        return self::$purifier;
    }
}
