<?php

namespace Modules\AiChatPanel\Tests;

use Modules\AiChatPanel\Entities\UserPref;

/**
 * Guards the band between a phone and a column.
 *
 * A tablet has no room for a third column — isOverlay() is true at 1024px and
 * at 768px alike — but it has ample room for a window, and the two questions
 * are not the same one. Until 1.3.4 they were asked with the same predicate,
 * which cost every tablet its pin button and its resize grip on the grounds
 * that a column would not fit.
 *
 * So the shapes below the switch are now the drawer and the window, chosen with
 * the pin, and all three drags accept a finger. Both halves are easy to undo by
 * accident and neither is exercised by anything else in this suite: the CSS and
 * the JS are read off disk the way ResponsiveLayoutTest reads them, because
 * there is no browser here to ask.
 */
class TabletPanelTest extends AiChatPanelTestCase
{
    /**
     * The floor of the band has to mean the same thing in both files.
     *
     * isPhone() decides whether the pin is offered; the stylesheet decides
     * whether it is drawn. Two files, one number — the same argument
     * ResponsiveLayoutTest makes about 1100px, and the same failure mode if
     * they drift: a button that is there but invisible, or visible but dead.
     *
     * @return void
     */
    public function testThePhoneBandMatchesTheStylesheet()
    {
        $this->assertMatchesRegularExpression(
            '~window\.matchMedia\(\s*.\(max-width: 767px\).\s*\)~',
            $this->js(),
            'isPhone() no longer reads 767px, so the band the JS offers the pin in and the '
                .'band the stylesheet draws it in have come apart.'
        );

        $this->assertMatchesRegularExpression(
            '~@media\s*\(max-width:\s*767px\)~',
            $this->css(),
            'The stylesheet no longer has a 767px block. isPhone() is derived from it — '
                .'re-derive it from whatever replaced it.'
        );
    }

    /**
     * A tablet keeps both handles. Only a phone loses them.
     *
     * The negative is the assertion that matters: hiding them under
     * .aicp-overlay is exactly what the old build did, and it reads as
     * perfectly reasonable until you notice that .aicp-overlay is set at
     * 1024px.
     *
     * @return void
     */
    public function testTheGripAndThePinSurviveAboveThePhoneBand()
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '~body\.aicp-phone\s+\.aicp-resizer\s*\{[^}]*display:\s*none~',
            $css,
            'The drawer grip is offered on a phone, where the drawer is already the whole '
                .'screen and there is nothing to resize it into.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '~body\.aicp-overlay\s+\.aicp-(pin|resizer)\s*\{[^}]*display:\s*none~',
            $css,
            'The pin or the grip is hidden for the whole overlay band again, which is every '
                .'tablet width there is. They belong to .aicp-phone.'
        );
    }

    /**
     * The drawer is one of two shapes now, not the only one below the switch.
     *
     * @return void
     */
    public function testTheDrawerIsTheShapeAWindowIsNot()
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            '~function\s+isDrawer\(\)\s*\{\s*return\s+isOverlay\(\)\s*&&\s*!isFloating\(\);~',
            $js,
            'isDrawer() is no longer isOverlay() minus the window, so the two body classes can '
                .'be set at once — and the drawer backdrop at z-index 1040 buries the window '
                .'at 1030.'
        );

        $this->assertMatchesRegularExpression(
            '~if\s*\(isDrawer\(\)\)\s*\{\s*\$\(.\.aicp-backdrop.\)\.removeClass\(.hidden.\)~',
            $js,
            'openPanel() dims the thread for anything below the switch again, so undocking on '
                .'a tablet paints a backdrop over the window the user just asked for.'
        );
    }

    /**
     * A tablet restores a window the user placed. It never seeds one.
     *
     * MODE_DEFAULT is MODE_FLOATING and the pin was hidden below the switch
     * until 1.3.4, so every existing tablet user is carrying a "floating"
     * nobody chose. Take the stored mode at face value there and they all lose
     * the drawer they have been using. The geometry columns are what tell the
     * two apart: null until the first undock.
     *
     * @return void
     */
    public function testATabletRestoresAWindowButNeverSeedsOne()
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            '~function\s+isFloating\(\)\s*\{.*?return\s+placed_float;~s',
            $js,
            'isFloating() no longer asks whether the user ever placed a window, so a tablet '
                .'user who has never pressed the pin gets one anyway — where they had a drawer.'
        );

        $this->assertMatchesRegularExpression(
            '~placed_float\s*=\s*panel\.float\s*!==\s*null~',
            $js,
            'initPanel() no longer reads the flag off the stored geometry, so it is false for '
                .'everyone and no tablet ever gets its window back.'
        );

        // seedFloat() fills panel.float in-session, so a flag read from it
        // later would be true for everyone the moment applyMode() had run once.
        $this->assertMatchesRegularExpression(
            '~placed_float\s*=\s*true;\s*\}\s*applyMode\(\);~s',
            $js,
            'togglePanelMode() sets the flag after applyMode() rather than before it. '
                .'applyMode() asks isFloating(), which asks the flag, so on a tablet the class '
                .'never lands and the pin button does nothing at all.'
        );
    }

    /**
     * Pinning changes the shape without a resize to notice it.
     *
     * applyLayoutMode() only acts on a transition, and it memoises the last one
     * it saw. togglePanelMode() moves isDrawer() under its feet, so unless the
     * memo is re-seeded by hand the next scroll frame reads a transition into
     * the drawer and closes the panel that was just pinned.
     *
     * @return void
     */
    public function testPinningReSeedsTheLayoutMemo()
    {
        $this->assertMatchesRegularExpression(
            '~applyDrawerClass\(\);\s*last_drawer\s*=\s*isDrawer\(\);~',
            $this->js(),
            'togglePanelMode() leaves the layout memo stale, so pinning a window into a drawer '
                .'on a tablet force-closes the panel one frame later.'
        );
    }

    /**
     * A drawer is capped by the screen, not by the thread beside it.
     *
     * maxPanelWidth() comes out around -450 on a tablet: below 992px core turns
     * .sidebar-2col into a full-width bar, so it subtracts the whole window
     * from itself. A drawer capped by that would be a sliver — and one capped
     * by nothing at all stores 900 into panel_width, which is the desktop
     * column's width.
     *
     * @return void
     */
    public function testTheDrawerIsCappedByTheScreen()
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            '~function\s+maxDrawerWidth\(\)\s*\{\s*return\s+Math\.max\(WIDTH_MIN,\s*Math\.min\(WIDTH_MAX,\s*\$\(window\)\.width\(\)\s*-\s*DRAWER_PEEK\)\)~',
            $js,
            'maxDrawerWidth() is gone or no longer leaves a strip of thread beside the drawer. '
                .'The backdrop is how the drawer is dismissed; a drawer that covers it leaves '
                .'the header ✕ as the only way out.'
        );

        $this->assertMatchesRegularExpression(
            '~if\s*\(isDrawer\(\)\)\s*\{\s*width\s*=\s*Math\.min\(width,\s*maxDrawerWidth\(\)\)~',
            $js,
            'applyWidth() no longer caps a drawer, so a width dragged out on a desktop '
                .'overflows the screen it is drawn on.'
        );

        $this->assertMatchesRegularExpression(
            '~chosen\s*=\s*Math\.min\(chosen,\s*maxDrawerWidth\(\)\)~',
            $js,
            'The drag itself no longer clamps, so an over-drag is hidden by max-width:100% and '
                .'stored anyway — and what is stored is the desktop column.'
        );
    }

    /**
     * Every drag is a pointer drag.
     *
     * jQuery mousedown does fire on a touch screen, but only for taps and only
     * after a delay: a finger dragging a mouse-only handler scrolls the page.
     * This is the assertion that says touch works at all.
     *
     * @return void
     */
    public function testEveryDragIsAPointerDrag()
    {
        $js = $this->js();

        $this->assertSame(
            3,
            preg_match_all('~bindPointerDrag\(panel\.~', $js),
            'There should be exactly three drags — the drawer/column grip, the window header '
                .'and the eight window grips — and all three must go through bindPointerDrag().'
        );

        $this->assertDoesNotMatchRegularExpression(
            '~\.on\(\s*.mousedown~',
            $js,
            'A mousedown drag handler is back. A finger cannot drive one: the emulated mouse '
                .'events a touch screen sends arrive late and only for taps.'
        );

        $this->assertMatchesRegularExpression(
            '~touch-action:\s*none~',
            $this->css(),
            'touch-action is gone from the grips. preventDefault() in pointerdown does not '
                .'stop a scroll — the compositor has already claimed the gesture by then.'
        );
    }

    /**
     * A captured drag has to be released, including when it is taken away.
     *
     * @return void
     */
    public function testEveryDragCapturesAndCancels()
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            '~setPointerCapture\(~',
            $js,
            'Without pointer capture a 6px grip stops receiving moves the moment the finger '
                .'leaves it, which is immediately.'
        );

        $this->assertMatchesRegularExpression(
            '~pointerup\.aicpdrag pointercancel\.aicpdrag~',
            $js,
            'pointercancel is unhandled, so a system gesture mid-drag — a rotation, the home '
                .'swipe — leaves the panel stuck to a finger that is no longer there.'
        );

        $this->assertMatchesRegularExpression(
            '~options\.end\(armed\s*&&\s*e\.type\s*===\s*.pointerup.\)~',
            $js,
            'A cancelled drag commits like a finished one, so a gesture the system took away '
                .'writes a position the user never chose.'
        );
    }

    /**
     * The second finger of a pinch must not start a drag of its own.
     *
     * e.which is populated by jQuery's mouse hooks and is not meaningful for a
     * pointer event, so the guard has to read the original event: button for
     * the mouse, isPrimary for everything else.
     *
     * @return void
     */
    public function testASecondFingerDoesNotStartASecondDrag()
    {
        $this->assertMatchesRegularExpression(
            '~if\s*\(oe\.button\s*\|\|\s*oe\.isPrimary\s*===\s*false\)~',
            $this->js(),
            'The drag no longer rejects a non-primary contact, so the other finger of a pinch '
                .'overwrites the first one\'s origin halfway through the gesture.'
        );
    }

    /**
     * A tap is not a drag, however still the hand holding it is.
     *
     * @return void
     */
    public function testATapDoesNotBecomeADrag()
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            '~var\s+DRAG_SLOP\s*=\s*\d+~',
            $js,
            'The movement threshold is gone, so a two-pixel wobble while tapping the window '
                .'header nudges the window and posts a preference for it.'
        );

        $this->assertMatchesRegularExpression(
            '~armed:\s*!oe\.pointerType\s*\|\|\s*oe\.pointerType\s*===\s*.mouse.~',
            $js,
            'The threshold now applies to the mouse as well. A mouse that moved meant to '
                .'move — the first pixel of a mouse drag must land.'
        );
    }

    /**
     * The grips have to be findable with a fingertip.
     *
     * @return void
     */
    public function testTheGripsAreReachableWithAFinger()
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '~@media\s*\(pointer:\s*coarse\)~',
            $css,
            'The coarse-pointer block is gone, so the resize grips are back to the 6px edges '
                .'and 14px corners that were drawn for a cursor.'
        );

        preg_match('~@media\s*\(pointer:\s*coarse\)\s*\{(.*)~s', $css, $block);

        $this->assertNotEmpty($block, 'The coarse-pointer block is gone from module.css.');

        $this->assertMatchesRegularExpression(
            '~\.aicp-resizer\s*\{[^}]*top:\s*53px~',
            $block[1],
            'The fattened drawer grip is not held clear of the header. At 20px wide it sits on '
                .'top of the window\'s own drag surface and swallows the left end of it.'
        );
    }

    /**
     * Only a fitted box is ever stored.
     *
     * A drag writes raw coordinates to panel.float, which go negative as soon
     * as the window is pushed off the top or the left. panel_float_x and
     * panel_float_y are unsignedSmallInteger: MySQL stores 0 for a negative in
     * non-strict mode and rejects the whole request in strict mode.
     *
     * @return void
     */
    public function testOnlyAFittedWindowIsEverStored()
    {
        $this->assertSame(
            2,
            preg_match_all('~panel\.float = fitFloat\(\);\s*savePrefs\(floatPrefs\(\)\);~', $this->js()),
            'A geometry save is no longer immediately preceded by fitFloat(), so a window '
                .'dragged past the top left corner posts a negative coordinate into an '
                .'unsigned column.'
        );
    }

    /**
     * Rotating a tablet is the transition this whole band exists for.
     *
     * @return void
     */
    public function testRotationIsWatched()
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            '~\$\(window\)\.on\(.scroll resize orientationchange.~',
            $js,
            'orientationchange is no longer watched, so a window placed in landscape stays '
                .'where it was in portrait — off screen — until something else fires a resize.'
        );

        $this->assertMatchesRegularExpression(
            '~function\s+viewportHeight\(\)\s*\{[^}]*window\.visualViewport\.height~',
            $js,
            'The window is measured against the layout viewport again. A software keyboard '
                .'does not change that one, so the composer ends up under the keys — while '
                .'you are typing in it.'
        );

        $this->assertMatchesRegularExpression(
            '~function\s+viewportHeight\(\)\s*\{\s*return\s+Math\.round\(~',
            $js,
            'viewportHeight() is not rounded. visualViewport reports fractions of a pixel and '
                .'the geometry made from it goes into an integer column, so the window ends up '
                .'drawn somewhere other than where the server was told it is.'
        );
    }

    /**
     * A width chosen on a tablet has to be a width the desktop can wear.
     *
     * The shape and the width are stored once, for the user, not once per
     * device — so anything maxDrawerWidth() can produce has to survive the trip
     * back to the column. It does, because it is clamped to WIDTH_MIN..WIDTH_MAX
     * before it goes anywhere.
     *
     * @return void
     */
    public function testATabletWidthIsALegalColumnWidth()
    {
        $this->assertEquals(720, UserPref::clampWidth(720));
        $this->assertEquals(UserPref::WIDTH_MAX, UserPref::clampWidth(1200));
        $this->assertEquals(UserPref::WIDTH_MIN, UserPref::clampWidth(120));
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
