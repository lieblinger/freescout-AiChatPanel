<?php

namespace Modules\AiChatPanel\Tests;

/**
 * Guards how the module's JavaScript reaches the page.
 *
 * Core hands everything in the `javascripts` filter to Minify::javascript(),
 * which concatenates the whole list into one file and runs JShrink over it.
 * JShrink cannot be trusted with third-party minified bundles: it read the
 * backticks in marked 15's inline `text` regex as template-literal delimiters
 * and stripped the literal spaces out of the rest of that regex, turning
 * `[^ ](?= {2,}\n)` into `[^](?={2,}\n)` — "Nothing to repeat". One SyntaxError
 * in a concatenated file means none of it parses, so jQuery, Bootstrap and
 * main.js died with it and the navbar stopped responding on every page.
 *
 * config/minify.config.php lists `local` under ignore_environments, so a dev
 * box serves each file separately and never shows this. These tests do not
 * depend on the environment.
 */
class AssetBundleTest extends AiChatPanelTestCase
{
    /**
     * Nothing pre-minified may enter the Minify bundle.
     *
     * The rule is deliberately broader than the one library that broke: any
     * vendored bundle is a candidate for the same class of failure, and a
     * vendored bundle is exactly what we cannot re-minify safely.
     *
     * @return void
     */
    public function testModuleAddsNoPreMinifiedFileToTheBundle()
    {
        $ours = $this->moduleJavascripts();

        $this->assertNotEmpty($ours, 'The module contributed nothing to the javascripts filter.');

        foreach ($ours as $path) {
            $this->assertStringNotContainsString(
                '/vendor/',
                $path,
                $path.' is a vendored library and must not go through Minify — load it in registerVendorScripts() instead.'
            );

            $this->assertStringEndsNotWith(
                '.min.js',
                $path,
                $path.' is already minified and must not be re-minified by JShrink — load it in registerVendorScripts() instead.'
            );
        }
    }

    /**
     * The renderer and the sanitiser still have to reach the page, as their own
     * script tags, once the panel has rendered.
     *
     * The two hooks are fired directly rather than through a request for the
     * conversation page: that page is core's, and rendering all of it here
     * would make this test fail for reasons that have nothing to do with
     * assets.
     *
     * @return void
     */
    public function testVendorScriptsAreEmittedOutsideTheBundle()
    {
        $html = $this->renderHooks();

        // The panel action swallows its own exceptions, so confirm it actually
        // rendered — otherwise the assertions below would pass on an empty
        // string the moment the panel broke for an unrelated reason.
        $this->assertStringContainsString('aicp-panel', $html, 'The panel did not render.');

        foreach (['marked.min.js', 'purify.min.js'] as $file) {
            $this->assertMatchesRegularExpression(
                '~<script[^>]+src="[^"]*/modules/aichatpanel/js/vendor/'.preg_quote($file, '~').'~',
                $html,
                $file.' is not emitted. module.js degrades to plain text without it.'
            );
        }
    }

    /**
     * They are 60 KB together and only the conversation page uses them.
     *
     * @return void
     */
    public function testVendorScriptsAreNotEmittedWhereThePanelIsAbsent()
    {
        ob_start();
        \Eventy::action('layout.body_bottom');
        $html = ob_get_clean();

        $this->assertStringNotContainsString('marked.min.js', $html);
        $this->assertStringNotContainsString('purify.min.js', $html);
    }

    /**
     * The whole scheme rests on core rendering layout.body_bottom above its
     * Minify::javascript() call — otherwise the globals arrive after the
     * module.js that wants them. Assert it, so an upstream reshuffle of the
     * layout is caught here rather than in the browser.
     *
     * @return void
     */
    public function testCoreFiresBodyBottomBeforeTheBundle()
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $hook = strpos($layout, "@action('layout.body_bottom')");
        $bundle = strpos($layout, 'Minify::javascript(');

        $this->assertNotFalse($hook, 'layout.body_bottom is gone from the layout.');
        $this->assertNotFalse($bundle, 'The layout no longer calls Minify::javascript().');

        $this->assertLessThan(
            $bundle,
            $hook,
            'layout.body_bottom now renders after the JS bundle, so marked and DOMPurify would load too late.'
        );
    }

    /**
     * Fire the panel hook, then the layout hook, in the order the layout does.
     *
     * @return string
     */
    protected function renderHooks()
    {
        // The panel action reads auth()->user() and renders nothing without it.
        $this->actingAs($this->agent);

        ob_start();
        \Eventy::action('conversation.after_customer_sidebar', $this->conversation);
        \Eventy::action('layout.body_bottom');

        return ob_get_clean();
    }

    /**
     * The module's own contribution to the `javascripts` filter.
     *
     * @return array
     */
    protected function moduleJavascripts()
    {
        $core = ['/js/jquery.js', '/js/main.js'];

        return array_values(array_filter(
            \Eventy::filter('javascripts', $core),
            function ($path) use ($core) {
                return !in_array($path, $core, true);
            }
        ));
    }
}
