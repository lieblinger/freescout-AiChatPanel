<?php

namespace Modules\AiChatPanel\Tests;

use Modules\AiChatPanel\Services\JsStrings;

/**
 * Holds the JavaScript strings and their call sites together.
 *
 * The bug this replaces was not a missing translation — de.json had every
 * string — but a table that never reached the browser, because it was built by
 * a command no install path runs. The strings now travel as a data- attribute,
 * which removes that failure mode; what it does not remove is the other half,
 * a t() call whose key nobody put in the map. That is what these check.
 */
class JsStringsTest extends AiChatPanelTestCase
{
    /**
     * Every key module.js asks for exists, and every key we ship is asked for.
     *
     * @return void
     */
    public function testTheKeysMatchTheCallSites()
    {
        $used = $this->keysUsedInJs();
        $shipped = array_keys(JsStrings::all());

        sort($used);
        sort($shipped);

        $this->assertSame(
            [],
            array_values(array_diff($used, $shipped)),
            'module.js calls t() with keys that JsStrings does not ship, so they fall back to '
                .'their English literal in every language.'
        );

        $this->assertSame(
            [],
            array_values(array_diff($shipped, $used)),
            'JsStrings ships keys nothing asks for. Drop them, or the map slowly fills with '
                .'strings translators keep translating for nothing.'
        );
    }

    /**
     * The map follows the interface language.
     *
     * @return void
     */
    public function testTheStringsAreTranslated()
    {
        $original = app()->getLocale();

        try {
            app()->setLocale('de');
            $de = JsStrings::all();

            $this->assertSame('Heute', $de['day_today']);
            $this->assertSame(
                'Stellen Sie eine Frage zu diesem Gespräch oder wählen Sie unten ein Kürzel.',
                $de['empty_title']
            );
        } finally {
            app()->setLocale($original);
        }
    }

    /**
     * The panel carries the map, in the reader's language, as valid JSON.
     *
     * @return void
     */
    public function testThePanelCarriesTheStrings()
    {
        $original = app()->getLocale();

        try {
            app()->setLocale('de');

            $html = \View::make(AICHATPANEL_MODULE.'::panel', [
                'conversation' => $this->conversation,
                'shortcuts'    => [],
                'prefs'        => ['open' => true, 'width' => 400],
                'timezone'     => 'Europe/Berlin',
            ])->render();

            $this->assertLangAttribute($html);
        } finally {
            app()->setLocale($original);
        }
    }

    /**
     * There must be no generated translation table left to build.
     *
     * Its absence is the point of the change: a file that only
     * freescout:module-build produces is a file a zip install never has.
     *
     * @return void
     */
    public function testNoGeneratedStringTableIsExpected()
    {
        $this->assertFileDoesNotExist(
            __DIR__.'/../Resources/views/js/vars.blade.php',
            'The generated JS translation table is back. Nothing in core runs '
                .'freescout:module-build, so anything it produces is missing on a zip install.'
        );

        $this->assertStringNotContainsString(
            "'/js/vars.js'",
            file_get_contents(__DIR__.'/../Providers/AiChatPanelServiceProvider.php'),
            'The provider queues vars.js again, which only exists if someone ran a build command '
                .'by hand.'
        );
    }

    /**
     * @param string $html
     *
     * @return void
     */
    protected function assertLangAttribute($html)
    {
        $this->assertMatchesRegularExpression('~data-aicp-lang="[^"]+"~', $html, 'The panel carries no string map.');

        preg_match('~data-aicp-lang="([^"]+)"~', $html, $m);

        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);

        $this->assertIsArray($decoded, 'The string map on the panel is not valid JSON.');
        $this->assertSame('Heute', $decoded['day_today'] ?? null, 'The map is not in the reader\'s language.');
        $this->assertCount(count(JsStrings::all()), $decoded);
    }

    /**
     * The first argument of every t() call in module.js.
     *
     * @return array
     */
    protected function keysUsedInJs()
    {
        $js = file_get_contents(__DIR__.'/../Public/js/module.js');

        // \b before the t: without it, alert('success' and insertAt('x' match
        // as if they were translation calls. \s* after it: a call whose
        // arguments are long enough to wrap puts the key on the next line.
        preg_match_all('~\bt\(\s*\'([a-z_0-9]+)\'~', $js, $matches);

        return array_values(array_unique($matches[1]));
    }
}
