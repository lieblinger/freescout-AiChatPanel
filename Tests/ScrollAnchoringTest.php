<?php

namespace Modules\AiChatPanel\Tests;

/**
 * The message list follows new content only while the reader is at the bottom.
 *
 * Streaming appends on every token that arrives, so an unconditional scroll made
 * re-reading an earlier answer mid-turn impossible: the view snapped back a few
 * times a second, and again when the turn completed.
 *
 * All of this lives in the shipped CSS, JS and Blade, which no PHP test can
 * execute — there is no JS runner in this workspace. So it is checked statically,
 * the way ResponsiveLayoutTest checks the drawer and FloatingPanelTest checks the
 * window geometry.
 */
class ScrollAnchoringTest extends AiChatPanelTestCase
{
    /**
     * The one guard the whole feature rests on.
     *
     * @return void
     */
    public function testScrollingToTheBottomIsGuardedByTheStickFlag()
    {
        $this->assertMatchesRegularExpression(
            '~function scrollToBottom\(\)\s*\{\s*if \(!panel\.stick\)~',
            $this->js(),
            'scrollToBottom() no longer checks panel.stick, so every streamed token yanks the '
                .'list back down and reading an earlier message mid-turn is impossible again.'
        );
    }

    /**
     * What clears the flag: the reader scrolling away.
     *
     * @return void
     */
    public function testTheReadersOwnScrollingDecidesWhetherTheListFollows()
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            "~panel\.\\\$messages\.on\('scroll'~",
            $js,
            'Nothing listens to the message list scrolling any more, so panel.stick is never '
                .'cleared and the list follows new content whatever the reader is doing.'
        );

        $this->assertStringContainsString(
            'panel.stick = isAtBottom();',
            $js,
            'The scroll handler no longer derives panel.stick from the position. It must be '
                .'recomputed there: the jump is an instant scrollTop, so landing at the bottom '
                .'is what re-arms following.'
        );
    }

    /**
     * The jump must stay instant.
     *
     * Smooth scrolling fires dozens of intermediate scroll events that all read
     * as "not at bottom", so the handler above would fight the animation and
     * clear the flag it just set.
     *
     * @return void
     */
    public function testTheJumpDoesNotSmoothScroll()
    {
        $this->assertStringNotContainsString(
            "behavior: 'smooth'",
            $this->js(),
            'A smooth jump fires scroll events all the way down, each of which recomputes '
                .'panel.stick as false. Keep the instant scrollTop, or add a suppression flag.'
        );
    }

    /**
     * Sending is the deliberate "take me to the newest".
     *
     * @return void
     */
    public function testSendingReArmsFollowingBeforeTheEcho()
    {
        $this->assertMatchesRegularExpression(
            '~panel\.stick = true;\s*panel\.missed = 0;\s*appendMessage\(\{role: \'user\'~',
            $this->js(),
            'sendMessage() no longer re-arms before echoing the message, so sending while '
                .'scrolled up leaves your own message off screen.'
        );
    }

    /**
     * The badge counts finished entries, never the bubble being written.
     *
     * `done` discards the streaming bubble and appends the finished message
     * through appendMessage(), so counting both counts one turn twice.
     *
     * @return void
     */
    public function testTheStreamingBubbleIsNotCounted()
    {
        $js = $this->js();

        preg_match('~function renderStreamingBubble\(text\)\s*\{(.*?)\n    \}~s', $js, $matches);

        $this->assertNotEmpty($matches, 'renderStreamingBubble() was not found in the shipped JS.');

        $this->assertStringNotContainsString(
            'countMissed()',
            $matches[1],
            'renderStreamingBubble() counts itself towards the badge. The turn it is writing is '
                .'counted again by appendMessage() when `done` replaces it, so every answer '
                .'received while scrolled up counts twice.'
        );

        $this->assertStringContainsString(
            'countMissed();',
            $js,
            'Nothing counts missed entries any more, so the badge never shows a number.'
        );
    }

    /**
     * The button and its badge, hidden until there is a reason to show them.
     *
     * @return void
     */
    public function testTheJumpButtonIsRenderedInsideTheListWrapper()
    {
        $blade = $this->blade();

        $wrapper = strpos($blade, '<div class="aicp-messages-wrap">');
        $button = strpos($blade, 'aicp-jump hidden');
        $shortcuts = strpos($blade, 'aicp-shortcuts');

        $this->assertNotFalse($wrapper, '.aicp-messages-wrap is gone from the panel template.');
        $this->assertNotFalse($button, 'The jump button is gone from the panel template, or no longer starts hidden.');
        $this->assertNotFalse($shortcuts, 'The shortcut strip is gone; this test can no longer bound the wrapper.');

        // Ordering rather than a regex: the wrapper's own </div> is one of many
        // in the file, so a pattern spanning it would pass wherever the button
        // ended up. The shortcut strip is the next block after the wrapper.
        $this->assertGreaterThan(
            $wrapper,
            $button,
            'The jump button is rendered before .aicp-messages-wrap opens, so it is not inside it.'
        );

        $this->assertLessThan(
            $shortcuts,
            $button,
            'The jump button has escaped .aicp-messages-wrap. Outside it the button is positioned '
                .'against the panel, so it floats over the shortcuts and the composer instead of '
                .'over the bottom of the list.'
        );

        $this->assertStringContainsString(
            '<span class="badge aicp-jump-count hidden"',
            $blade,
            'The badge is gone, or no longer starts hidden — an empty badge would sit on the '
                .'button from the first render.'
        );
    }

    /**
     * The flex trap the wrapper introduced.
     *
     * A flex item's default min-height is auto, so without this the wrapper
     * refuses to shrink below its content: the list stops scrolling entirely and
     * grows the panel past the composer.
     *
     * @return void
     */
    public function testTheListWrapperCanShrinkBelowItsContent()
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '~\.aicp-messages-wrap\s*\{[^}]*min-height:\s*0~',
            $css,
            'min-height:0 is gone from .aicp-messages-wrap, so the message list stops scrolling '
                .'and the panel grows past its own composer.'
        );

        $this->assertMatchesRegularExpression(
            '~\.aicp-messages-wrap\s*\{[^}]*position:\s*relative~',
            $css,
            '.aicp-messages-wrap is no longer a containing block, so the absolutely positioned '
                .'jump button escapes to the panel and lands over the composer.'
        );

        $this->assertMatchesRegularExpression(
            '~\.aicp-messages-wrap \.aicp-jump\s*\{[^}]*position:\s*absolute~',
            $css,
            'The jump button is no longer taken out of flow, so it pushes the message list up '
                .'instead of floating over it.'
        );
    }

    /**
     * The button must out-specify .btn.
     *
     * Core puts the `stylesheets` filter's list before bootstrap.css and
     * style.css, so a single-class .aicp-jump rule loses every property .btn
     * also declares — on source order alone, at equal specificity. That is how
     * the pill first shipped as a 2px-cornered rectangle.
     *
     * @return void
     */
    public function testTheJumpButtonOutSpecifiesBootstrapsButtonRule()
    {
        $css = $this->css();

        $this->assertDoesNotMatchRegularExpression(
            '~^\.aicp-jump(-count)?\s*\{~m',
            $css,
            'A jump-button rule is selected by one class. module.css is loaded before '
                .'bootstrap.css, so .btn wins every property they share — border-radius and '
                .'padding among them. Scope it through .aicp-messages-wrap.'
        );

        $this->assertMatchesRegularExpression(
            '~\.aicp-messages-wrap \.aicp-jump\s*\{[^}]*border-radius:~',
            $css,
            'The pill shape is gone from the scoped rule, so the button falls back to '
                ."bootstrap's 2px corners."
        );
    }

    /**
     * @return string
     */
    protected function css()
    {
        return file_get_contents(__DIR__.'/../Public/css/module.css');
    }

    /**
     * @return string
     */
    protected function js()
    {
        return file_get_contents(__DIR__.'/../Public/js/module.js');
    }

    /**
     * @return string
     */
    protected function blade()
    {
        return file_get_contents(__DIR__.'/../Resources/views/panel.blade.php');
    }
}
