<?php

namespace Modules\AiChatPanel\Tests;

/**
 * Guards the shortcut buttons above the chat input.
 *
 * They are the one part of the panel whose labels come out of a setting rather
 * than out of the template, which is how they stayed English in a German
 * interface while everything around them was translated: the view echoed the
 * stored value straight out. Passing it through __() fixes that for the shipped
 * defaults without touching what an admin typed themselves, so both halves are
 * worth pinning down.
 */
class PromptShortcutsTest extends AiChatPanelTestCase
{
    /**
     * Every shortcut this module ships must have a German translation, or the
     * fix above silently does nothing for the defaults it was written for.
     *
     * @return void
     */
    public function testTheShippedShortcutsAreTranslated()
    {
        $de = json_decode(file_get_contents(__DIR__.'/../Resources/lang/de.json'), true);

        $this->assertIsArray($de, 'Resources/lang/de.json is not valid JSON.');

        foreach (config('aichatpanel.options.prompt_shortcuts.default') as $shortcut) {
            $this->assertArrayHasKey(
                $shortcut,
                $de,
                'The default shortcut "'.$shortcut.'" has no German translation, so it stays '
                    .'English in a German interface.'
            );
        }
    }

    /**
     * The rendered button, and the prompt it prefills, both follow the locale.
     *
     * The prompt matters as much as the label: a German agent who presses a
     * German button expects a German answer back.
     *
     * @return void
     */
    public function testTheRenderedShortcutsFollowTheLocale()
    {
        $original = app()->getLocale();

        try {
            app()->setLocale('de');

            $html = $this->renderPanel(['Draft a reply to the latest customer message.']);

            $this->assertStringNotContainsString(
                'Draft a reply to the latest customer message.',
                $html,
                'The panel still renders the English source string for a shipped shortcut.'
            );

            $this->assertStringContainsString(
                'Eine Antwort auf die letzte Kundennachricht entwerfen.',
                $html,
                'The shortcut label was not translated.'
            );

            $this->assertStringContainsString(
                'data-prompt="Eine Antwort auf die letzte Kundennachricht entwerfen."',
                $html,
                'The label was translated but the prompt the button prefills was not, so the '
                    .'assistant is asked in English.'
            );
        } finally {
            app()->setLocale($original);
        }
    }

    /**
     * A shortcut an admin typed has no translation and must survive verbatim
     * rather than being mangled or dropped.
     *
     * @return void
     */
    public function testAnAdminsOwnShortcutIsLeftAlone()
    {
        $original = app()->getLocale();

        try {
            app()->setLocale('de');

            $html = $this->renderPanel(['Prüfe die Bestellnummer im letzten Beitrag.']);

            $this->assertStringContainsString(
                'data-prompt="Prüfe die Bestellnummer im letzten Beitrag."',
                $html,
                'A shortcut with no translation entry no longer passes through unchanged.'
            );
        } finally {
            app()->setLocale($original);
        }
    }

    /**
     * The strip must not grow its own scrollbar.
     *
     * A capped, scrolling strip hid the later shortcuts behind a scrollbar as
     * soon as the list needed more rows than the cap allowed — which the
     * shipped default list of five already does.
     *
     * @return void
     */
    public function testTheShortcutStripDoesNotScroll()
    {
        $css = file_get_contents(__DIR__.'/../Public/css/module.css');

        $this->assertMatchesRegularExpression(
            '~\.aicp-shortcuts\s*\{[^}]*\}~',
            $css,
            'The .aicp-shortcuts rule is gone.'
        );

        preg_match('~\.aicp-shortcuts\s*\{([^}]*)\}~', $css, $m);

        $this->assertStringNotContainsString(
            'max-height',
            $m[1],
            'The shortcut strip is capped again, which puts a scrollbar next to the buttons and '
                .'hides the ones past the cap.'
        );

        $this->assertStringNotContainsString(
            'overflow',
            $m[1],
            'The shortcut strip scrolls again instead of taking the rows it needs.'
        );
    }

    /**
     * Clicking a shortcut sends it rather than parking it in the input.
     *
     * It goes through sendMessage() rather than posting directly, because that
     * is where the busy and pending-confirmation guards live: when either one
     * refuses, the prompt has already been written into the input and stays
     * there for the user to send by hand.
     *
     * @return void
     */
    public function testClickingAShortcutSendsIt()
    {
        $handler = $this->shortcutClickHandler();

        $this->assertStringContainsString(
            'sendMessage();',
            $handler,
            'The shortcut click handler no longer sends, so a shortcut only prefills the input '
                .'again and the user has to press send.'
        );

        $this->assertStringContainsString(
            'panel.$input.val(',
            $handler,
            'The handler no longer writes the prompt into the input, so a send refused by the '
                .'busy or pending guard loses the click entirely.'
        );
    }

    /**
     * The body of the .aicp-shortcut click handler, and nothing else.
     *
     * Sliced rather than matched with one regular expression: a pattern loose
     * enough to span the handler is also loose enough to run past its closing
     * brace and find sendMessage() in some later function, which passes while
     * the handler itself does nothing.
     *
     * @return string
     */
    protected function shortcutClickHandler()
    {
        $js = file_get_contents(__DIR__.'/../Public/js/module.js');

        $start = strpos($js, "'.aicp-shortcut'");
        $this->assertNotFalse($start, 'There is no .aicp-shortcut click handler in module.js at all.');

        // The handler is bound at one indent level inside its function, so its
        // closing brace is the first one at that exact column.
        $end = strpos($js, "\n        });", $start);
        $this->assertNotFalse($end, 'The .aicp-shortcut handler is no longer a single bound function.');

        return substr($js, $start, $end - $start);
    }

    /**
     * @param array $shortcuts
     *
     * @return string
     */
    protected function renderPanel(array $shortcuts)
    {
        return \View::make(AICHATPANEL_MODULE.'::panel', [
            'conversation' => $this->conversation,
            'shortcuts'    => $shortcuts,
            'prefs'        => ['open' => true, 'width' => 400],
            'timezone'     => 'Europe/Berlin',
        ])->render();
    }
}
