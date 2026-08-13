<?php

namespace Modules\AiChatPanel\Tests;

/**
 * Guards how the panel behaves when the window is too narrow for three columns.
 *
 * The panel is the third column of the conversation view, and core only lays
 * that view out in three columns above 1100px: at max-width:1100px it stops
 * floating #conv-layout-customer beside the thread and stacks it instead (see
 * the block commented "Without right sidebar" in core's style.css). Below that
 * line, shifting .content-2col by up to 900px leaves the thread unreadable, so
 * the panel becomes a drawer over it and no longer opens by itself.
 *
 * Two files have to agree on that number — module.css through a media query,
 * module.js through matchMedia — and neither is exercised by any other test in
 * this suite. These checks are static and read the shipped assets off disk.
 */
class ResponsiveLayoutTest extends AiChatPanelTestCase
{
    /**
     * In overlay mode the panel must not push the conversation aside.
     *
     * @return void
     */
    public function testTheLayoutIsNotShiftedInOverlayMode()
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '~body\.aicp-overlay\.aicp-open\s+\.content-2col\s*\{[^}]*margin-right:\s*0~',
            $css,
            'Overlay mode no longer resets margin-right on .content-2col, so an open panel '
                .'squeezes the thread on windows that have no room for a column.'
        );

        // The rule has to outrank the push-mode one it overrides, which it
        // only does by carrying both classes.
        $this->assertMatchesRegularExpression(
            '~body\.aicp-open\s+\.content-2col\s*\{[^}]*margin-right:\s*var\(--aicp-width\)~',
            $css,
            'The push-mode shift is gone, so the panel would overlap the conversation instead '
                .'of making room for itself.'
        );
    }

    /**
     * A drawer over the thread needs a dimmer that dismisses it, and a width
     * dragged out on a desktop must not overflow a narrower screen.
     *
     * @return void
     */
    public function testTheDrawerIsUsableInOverlayMode()
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '~body\.aicp-overlay\.aicp-open\s+\.aicp-backdrop\s*\{[^}]*display:\s*block~',
            $css,
            'The backdrop is never shown in overlay mode, so nothing dims the thread and tapping '
                .'outside the drawer does not close it.'
        );

        $this->assertMatchesRegularExpression(
            '~body\.aicp-overlay\s+\.aicp-panel\s*\{[^}]*max-width:\s*100%~',
            $css,
            'The drawer is not capped to the viewport, so a stored width of up to 900px '
                .'overflows a narrower screen.'
        );
    }

    /**
     * The stored preference belongs to the wide layout only.
     *
     * @return void
     */
    public function testThePanelDoesNotOpenByItselfBelowTheBreakpoint()
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            '~if\s*\(\s*panel\.pref_open\s*&&\s*!isOverlay\(\)\s*\)~',
            $js,
            'initPanel() opens the panel from the stored preference without checking the layout '
                .'mode, so a preference set on a desktop reopens the drawer on a phone.'
        );
    }

    /**
     * ...and must not be rewritten from a phone or a tablet.
     *
     * @return void
     */
    public function testTheOpenStateIsNotPersistedBelowTheBreakpoint()
    {
        $js = $this->js();

        $this->assertSame(
            2,
            preg_match_all('~persist\s*!==\s*false\s*&&\s*!isOverlay\(\)~', $js),
            'openPanel() and closePanel() must both gate savePrefs() on the layout mode, or '
                .'opening the drawer on a phone becomes the state the desktop comes back to.'
        );
    }

    /**
     * The cap has to be measured on the thread, not on the window.
     *
     * The window is not the conversation: core's left nav takes 260px of it
     * and the customer rail another 280px. Budgeting against the window is
     * what once left a 1133px window with a 225px thread, one word per line in
     * the subject — so both sidebars have to be subtracted before the floor.
     *
     * @return void
     */
    public function testTheCapIsMeasuredOnTheThreadAndNotTheWindow()
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            '~var\s+MIN_THREAD_WIDTH\s*=\s*\d+~',
            $js,
            'The floor the panel width is capped against is gone from module.js.'
        );

        $this->assertMatchesRegularExpression(
            '~\$\(window\)\.width\(\)\s*-\s*sidebar\s*-\s*rail\s*-\s*MIN_THREAD_WIDTH~',
            $js,
            'maxPanelWidth() no longer subtracts core\'s two sidebars, so the panel budgets '
                .'against space the conversation never gets.'
        );

        $this->assertMatchesRegularExpression(
            '~Math\.min\(\s*width\s*,\s*maxPanelWidth\(\)\s*\)~',
            $js,
            'applyWidth() no longer caps the stored width, so a panel dragged out on a wide '
                .'monitor leaves no readable thread on a narrow one.'
        );
    }

    /**
     * When even the narrowest panel would starve the thread, it has to stop
     * being a column rather than shrink into a sliver.
     *
     * @return void
     */
    public function testThePanelGivesUpItsColumnBeforeTheThreadBecomesUnreadable()
    {
        $this->assertMatchesRegularExpression(
            '~return\s+narrow\s*\|\|\s*maxPanelWidth\(\)\s*<\s*WIDTH_MIN~',
            $this->js(),
            'isOverlay() is back to a pure window-width test, so a window that clears 1100px '
                .'but has no room for a column will squeeze the thread instead of hiding the panel.'
        );
    }

    /**
     * The panel's floor has to be core's own.
     *
     * If upstream ever moves the breakpoint where it stops floating the
     * customer rail beside the thread, this fails here rather than as a panel
     * that overlaps a sidebar in the browser.
     *
     * @return void
     */
    public function testTheBreakpointMatchesCore()
    {
        $this->assertMatchesRegularExpression(
            '~window\.matchMedia\(\s*.\(max-width:\s*1100px\).\s*\)~',
            $this->js(),
            'module.js no longer falls back to core\'s 1100px breakpoint, so the panel can try '
                .'to be a column on a layout that has already collapsed.'
        );

        $core = file_get_contents(public_path('css/style.css'));

        $this->assertMatchesRegularExpression(
            '~@media\s*\(max-width:\s*1100px\)~',
            $core,
            'Core no longer collapses the conversation layout at 1100px. Re-derive the panel\'s '
                .'breakpoint from whatever it uses now.'
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
}
