<?php

namespace Modules\AiChatPanel\Services\Markdown;

/**
 * What "safe HTML" means, per destination.
 *
 * There are two destinations and they have different ceilings:
 *
 *   - TARGET_PANEL — the chat panel's own bubbles. Nothing but a browser ever
 *     sees this, so <code>, <hr> and <del> are fine and <img> is not (an image
 *     URL in model output is a request from the agent's browser to an arbitrary
 *     host, i.e. a tracking pixel at best).
 *
 *   - TARGET_EDITOR — a thread body. Everything stored in threads.body is run
 *     through \Helper::purifyHtml() again when it is displayed and when it is
 *     rendered into outgoing mail, so core/config/purifier.php is a hard
 *     ceiling. That list has no <code>, no <hr> and no <del>, and keeps `class`
 *     only on <table>. Anything outside it is silently dropped, which is how
 *     inserted answers currently lose their inline code and rules.
 *
 * The editor profile is a strict subset of core's, which gives the invariant
 * the tests are built on: \Helper::purifyHtml($html) === $html.
 */
class EditorHtmlProfile
{
    const TARGET_EDITOR = 'editor';
    const TARGET_PANEL = 'panel';

    /**
     * Core's own CSS ceiling, copied verbatim from core/config/purifier.php.
     *
     * Copied rather than read with config('purifier.settings.default...') on
     * purpose: if core widens its list, ours must be reviewed, not silently
     * widened with it.
     *
     * @var string
     */
    const EDITOR_CSS_PROPERTIES = 'display,overflow,border-radius,letter-spacing,white-space,font-size,margin,margin-top,margin-right,margin-bottom,margin-left,background,text-transform,max-width,max-height,width,height,font,padding,padding-top,padding-right,padding-bottom,padding-left,font-family,border-color,font-weight,font-style,text-decoration,color,background-color,text-align,border,border-top,border-left,border-bottom,border-right';

    /** @var array Target => self */
    protected static $instances = [];

    /** @var array Target => \HTMLPurifier */
    protected static $purifiers = [];

    /** @var string */
    protected $target;

    /**
     * Inline styles for the editor target.
     *
     * Every declaration here is in core's CSS.AllowedProperties; every element
     * they are attached to is in core's HTML.Allowed. Inline rather than a
     * stylesheet because this HTML ends up in an email, where no stylesheet
     * follows it.
     *
     * The values are FreeScout's own, from core/public/css/bootstrap.css — its
     * customised Bootstrap 3.3.7 build, not the stock one, so the blockquote
     * rule and the heading scale differ from what upstream Bootstrap documents.
     *
     * Matching them is what makes an assistant-written block and a hand-written
     * one look the same: the editor's own buttons produce bare elements and let
     * that stylesheet style them, while we have to inline everything because
     * the same HTML is also the email, where no stylesheet follows it. Same
     * numbers, two delivery mechanisms.
     *
     * @var array
     */
    protected static $editor_styles = [
        // bootstrap.css:1224-1292 — h1 36 / h2 24 / h3 16 / h4 15 / h5 14 / h6 12,
        // margin-top 20 for h1-h3 and 10 for h4-h6, margin-bottom 10 throughout.
        'h1'         => 'font-size:36px; font-weight:bold; margin:20px 0 10px 0;',
        'h2'         => 'font-size:24px; font-weight:bold; margin:20px 0 10px 0;',
        'h3'         => 'font-size:16px; font-weight:bold; margin:20px 0 10px 0;',
        'h4'         => 'font-size:15px; font-weight:bold; margin:10px 0 10px 0;',
        'h5'         => 'font-size:14px; font-weight:bold; margin:10px 0 10px 0;',
        'h6'         => 'font-size:12px; font-weight:bold; margin:10px 0 10px 0;',
        // bootstrap.css:1420 — lists carry margin-bottom 10 and no top margin.
        // padding-left is the browser default made explicit for mail clients.
        'ul'         => 'margin:0 0 10px 0; padding-left:40px;',
        'ol'         => 'margin:0 0 10px 0; padding-left:40px;',
        'li'         => '',
        // bootstrap.css:1488 — FreeScout overrides stock Bootstrap here.
        'blockquote' => 'margin:0; padding:0 13px; border-left:2px solid #e3e8eb;',
        // bootstrap.css:1151 — hr is margin 20px 0 and a 1px #eee top border.
        // <hr> itself is not in core's whitelist, so it is a div: font-size:0 +
        // height:1px + overflow:hidden collapse it onto the border.
        'hr'         => 'border-top:1px solid #eeeeee; height:1px; font-size:0; overflow:hidden; margin:20px 0;',
        // bootstrap.css:1546 — the same pink <code> the chat panel shows, so an
        // answer looks the same in the panel and in the editor.
        'code'       => 'font-family:monospace; font-size:90%; color:#c7254e; background-color:#f9f2f4; padding:2px 4px; border-radius:4px;',
        // bootstrap.css:1569.
        'pre'        => 'font-family:monospace; font-size:13px; color:#333333; background-color:#f5f5f5; padding:9.5px; border:1px solid #cccccc; border-radius:4px; white-space:pre-wrap; margin:0 0 10px 0;',
        // bootstrap.css:2283 (.table) — the class does this in the app; the
        // inline copy is for the email.
        'table'      => 'width:100%; max-width:100%; margin:0 0 20px 0;',
        // bootstrap.css:2288/2325 (.table-bordered) — 8px cells, #ddd borders,
        // and a 2px bottom border under the header row.
        'td'         => 'padding:8px; border:1px solid #dddddd;',
        // No vertical-align: it is not in core's CSS.AllowedProperties, so it
        // would be stripped and break the "purifier changes nothing" invariant.
        'th'         => 'padding:8px; border:1px solid #dddddd; border-bottom:2px solid #dddddd;',
    ];

    /**
     * @param string $target
     */
    protected function __construct($target)
    {
        $this->target = $target;
    }

    /**
     * @return self
     */
    public static function editor()
    {
        return self::make(self::TARGET_EDITOR);
    }

    /**
     * @return self
     */
    public static function panel()
    {
        return self::make(self::TARGET_PANEL);
    }

    /**
     * @param string $target
     *
     * @return self
     */
    public static function make($target)
    {
        $target = $target === self::TARGET_EDITOR ? self::TARGET_EDITOR : self::TARGET_PANEL;

        if (!isset(self::$instances[$target])) {
            self::$instances[$target] = new self($target);
        }

        return self::$instances[$target];
    }

    /**
     * @return string
     */
    public function target()
    {
        return $this->target;
    }

    /**
     * Whether Parsedown's canonical output has to be rewritten before it is
     * sanitised. False for the panel, whose output is Parsedown's as-is.
     *
     * @return bool
     */
    public function retargets()
    {
        return $this->target === self::TARGET_EDITOR;
    }

    /**
     * @return bool
     */
    public function allowsImages()
    {
        return false;
    }

    /**
     * Parsedown's line-break setting. On for both: a model writes one newline
     * where it means one line break.
     *
     * @return bool
     */
    public function breaksEnabled()
    {
        return true;
    }

    /**
     * The block element a paragraph becomes.
     *
     * Summernote's emptyPara and formatPara are patched to DIV in core
     * (core/public/js/summernote/summernote.js), so a <div> is what the editor
     * itself produces and what its empty-editor sentinel looks like.
     *
     * @return string
     */
    public function blockTag()
    {
        return $this->target === self::TARGET_EDITOR ? 'div' : 'p';
    }

    /**
     * A named inline-style bundle. Empty string for the panel, which is styled
     * by Public/css/module.css instead.
     *
     * @param string $key
     *
     * @return string
     */
    public function style($key)
    {
        if ($this->target !== self::TARGET_EDITOR) {
            return '';
        }

        return isset(self::$editor_styles[$key]) ? self::$editor_styles[$key] : '';
    }

    /**
     * Table attributes.
     *
     * Two audiences, one element:
     *
     *   - `class` is what Summernote's own table button produces
     *     (tableClassName: 'table table-bordered' — summernote.js:7238, MIT,
     *     vendored in core), which is how a hand-inserted table gets its look.
     *     <table> is the one element where core's purifier keeps a class, so
     *     ours carries it too and the two are indistinguishable in the
     *     conversation view. It also means Summernote's table controls treat
     *     our tables exactly as they treat their own.
     *   - the border/cellspacing/cellpadding attributes and the inline styles
     *     are for the email, where there is no stylesheet. border-collapse is
     *     not in core's CSS.AllowedProperties, so attribute borders are the
     *     only ones that survive — and they are what mail clients honour
     *     anyway.
     *
     * In the app the stylesheet and the inline styles agree, so there is no
     * double border.
     *
     * @return array
     */
    public function tableAttributes()
    {
        if ($this->target !== self::TARGET_EDITOR) {
            return [];
        }

        return [
            'class'       => 'table table-bordered',
            'border'      => '1',
            'cellspacing' => '0',
            'cellpadding' => '8',
            'width'       => '100%',
            'style'       => $this->style('table'),
        ];
    }

    /**
     * @return array
     */
    public function allowedTags()
    {
        if ($this->target === self::TARGET_EDITOR) {
            return [
                'div[style]', 'br', 'span[style]',
                'b[style]', 'strong[style]', 'i[style]', 'em[style]', 'u[style]', 's[style]',
                'a[href|title|style]',
                'ul[style]', 'ol[style]', 'li[style]',
                'blockquote[style]', 'pre[style]',
                'h1[style]', 'h2[style]', 'h3[style]', 'h4[style]', 'h5[style]', 'h6[style]',
                // class only on <table> — the one element where core keeps it,
                // and where Summernote's own table button relies on it.
                'table[style|border|cellspacing|cellpadding|width|class]',
                'thead', 'tbody', 'tr[style]',
                'td[style|colspan|rowspan|width|align]', 'th[style|colspan|rowspan]',
            ];
        }

        return [
            'p', 'br', 'strong', 'em', 'b', 'i', 'del', 'code', 'pre',
            'ul', 'ol', 'li', 'blockquote',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr',
            'a[href|title]',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
        ];
    }

    /**
     * @param string $html
     *
     * @return string
     */
    public function purify($html)
    {
        try {
            return $this->purifier()->purify((string) $html);
            // \Throwable for the same reason as MarkdownToHtml::convert():
            // this is the last line before untrusted markup would be returned
            // unsanitised, so it must not be possible to skip it.
        } catch (\Throwable $e) {
            \Helper::logException($e, '[AiChatPanel] Sanitising model output failed: ');

            // Never return unsanitised HTML on the error path.
            return $this->blockTag() === 'div'
                ? '<div>'.htmlspecialchars(strip_tags((string) $html), ENT_QUOTES, 'UTF-8').'</div>'
                : '<p>'.htmlspecialchars(strip_tags((string) $html), ENT_QUOTES, 'UTF-8').'</p>';
        }
    }

    /**
     * Remove every newline outside <pre>.
     *
     * Core's purifier has AutoFormat.AutoParagraph on, and HTMLPurifier's
     * AutoParagraph injector only splits text that contains a blank line. Flat
     * HTML therefore makes core's pass provably inert, which is what keeps
     * stray <p> out of our <li> and <div> elements.
     *
     * @param string $html
     *
     * @return string
     */
    public function flatten($html)
    {
        $html = (string) $html;

        if ($this->target !== self::TARGET_EDITOR || $html === '') {
            return $html;
        }

        $segments = preg_split('#(<pre\b[^>]*>.*?</pre>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($segments === false) {
            return $html;
        }

        $out = '';

        foreach ($segments as $segment) {
            if (stripos($segment, '<pre') === 0) {
                $out .= $segment;
                continue;
            }

            // A newline next to a tag is layout and goes away; one in the
            // middle of a sentence is a space and has to stay one.
            $segment = preg_replace('/>[\r\n]+/', '>', $segment);
            $segment = preg_replace('/[\r\n]+</', '<', $segment);
            $segment = str_replace(["\r\n", "\r", "\n"], ' ', $segment);

            $out .= $segment;
        }

        return $out;
    }

    /**
     * What to show when conversion itself failed. Escaped plain text beats
     * showing nothing, and beats showing unsanitised markup.
     *
     * @param string $markdown
     *
     * @return string
     */
    public function fallback($markdown)
    {
        $tag = $this->blockTag();

        return '<'.$tag.'>'.nl2br(htmlspecialchars((string) $markdown, ENT_QUOTES, 'UTF-8')).'</'.$tag.'>';
    }

    /**
     * @return \HTMLPurifier
     */
    protected function purifier()
    {
        if (isset(self::$purifiers[$this->target])) {
            return self::$purifiers[$this->target];
        }

        $config = \HTMLPurifier_Config::createDefault();

        $config->set('HTML.Allowed', implode(',', $this->allowedTags()));
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('AutoFormat.RemoveEmpty', true);

        if ($this->target === self::TARGET_EDITOR) {
            // Match core's doctype so both passes serialise the same way —
            // <br> rather than <br /> — and reserialise CSS identically.
            $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
            $config->set('CSS.AllowedProperties', self::EDITOR_CSS_PROPERTIES);
            $config->set('CSS.Proprietary', true);
            $config->set('CSS.AllowTricky', true);
            $config->set('AutoFormat.AutoParagraph', false);
            $config->set('URI.AllowedSchemes', [
                'http'   => true,
                'https'  => true,
                'mailto' => true,
                'tel'    => true,
            ]);
            // Deliberately no HTML.TargetBlank / HTML.Nofollow: core strips
            // rel, and a link in an email has no target to open in.
        } else {
            $config->set('URI.AllowedSchemes', [
                'http'   => true,
                'https'  => true,
                'mailto' => true,
            ]);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('HTML.TargetBlank', true);
            $config->set('HTML.Nofollow', true);
        }

        // Reuse the cache directory core's purifier already maintains; fall
        // back to no definition cache rather than failing on a read-only disk.
        $cache_path = config('purifier.cachePath');

        if ($cache_path && is_dir($cache_path) && is_writable($cache_path)) {
            $config->set('Cache.SerializerPath', $cache_path);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }

        self::$purifiers[$this->target] = new \HTMLPurifier($config);

        return self::$purifiers[$this->target];
    }
}
