<?php

namespace Modules\AiChatPanel\Tests;

use Modules\AiChatPanel\Entities\UserPref;

/**
 * The panel's second desktop shape: a window the user moves and sizes.
 *
 * Two halves, tested two ways. The geometry itself lives in the shipped CSS and
 * JS, which no other test exercises, so it is checked statically the way
 * ResponsiveLayoutTest checks the drawer. What is stored comes back through the
 * prefs endpoint, so that half is a real request.
 */
class FloatingPanelTest extends AiChatPanelTestCase
{
    /**
     * A window is placed by all four sides, and gives the conversation its
     * width back while it is one.
     *
     * @return void
     */
    public function testTheWindowIsPlacedByItsOwnGeometry()
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '~body\.aicp-floating\s+\.aicp-panel\s*\{[^}]*left:\s*var\(--aicp-float-x\)~',
            $css,
            'The floating panel is no longer positioned from --aicp-float-x, so a window that '
                .'was dragged somewhere reopens glued to the right edge.'
        );

        foreach (['top: var(--aicp-float-y)', 'width: var(--aicp-float-w)', 'height: var(--aicp-float-h)'] as $declaration) {
            $this->assertStringContainsString(
                $declaration,
                $css,
                'The floating window no longer reads "'.$declaration.'", so part of its stored '
                    .'box has nowhere to land.'
            );
        }

        $this->assertMatchesRegularExpression(
            '~body\.aicp-floating\.aicp-open\s+\.content-2col\s*\{[^}]*margin-right:\s*0~',
            $css,
            'Undocking no longer un-shifts the conversation, so the layout keeps a column-wide '
                .'gap beside a panel that has left the column.'
        );
    }

    /**
     * A window needs somewhere to be. A phone is not somewhere.
     *
     * The floor moved down in 1.3.4: a tablet may have a window, because it has
     * room for one even where it has no room for a column. A phone still may
     * not — at 375px a window would be the screen — so the pin and the grip are
     * hidden there and isFloating() refuses outright.
     *
     * @return void
     */
    public function testTheWindowIsNotOfferedOnAPhone()
    {
        $this->assertMatchesRegularExpression(
            '~body\.aicp-phone\s+\.aicp-pin\s*\{[^}]*display:\s*none~',
            $this->css(),
            'The undock button is shown on a phone, where a floating window cannot be placed '
                .'or dragged.'
        );

        $this->assertMatchesRegularExpression(
            '~function\s+isFloating\(\)\s*\{\s*if\s*\(isPhone\(\)\)\s*\{\s*return\s+false;~',
            $this->js(),
            'isFloating() no longer refuses a phone first of all, so a shape chosen on a '
                .'desktop turns the drawer into a window on a 375px screen.'
        );
    }

    /**
     * A window that is off screen cannot be dragged back on.
     *
     * @return void
     */
    public function testTheWindowIsFittedToTheViewportBeforeItIsPublished()
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            '~function\s+applyFloat\(\)\s*\{.*?var\s+box\s*=\s*fitFloat\(\);~s',
            $js,
            'applyFloat() publishes the stored box as it stands, so a window saved on a large '
                .'monitor is partly off screen on a smaller one.'
        );

        $this->assertMatchesRegularExpression(
            '~box\.x\s*=\s*Math\.max\(0,\s*Math\.min\(box\.x,\s*\$\(window\)\.width\(\)\s*-\s*box\.w\)\)~',
            $js,
            'fitFloat() no longer keeps the whole window inside the viewport.'
        );
    }

    /**
     * Fitting the window to a smaller screen must not consume the geometry the
     * user chose on a bigger one — the same split applyWidth() makes.
     *
     * @return void
     */
    public function testAShrunkenViewportDoesNotRewriteTheStoredWindow()
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            '~function\s+fitFloat\(\)\s*\{\s*var\s+box\s*=\s*\{~',
            $js,
            'fitFloat() no longer works on a copy, so re-fitting the window to a smaller screen '
                .'overwrites the position it should come back to on a larger one.'
        );

        preg_match('~function\s+applyFloat\(\)\s*\{(.*?)\n    \}~s', $js, $body);

        $this->assertNotEmpty($body, 'applyFloat() is gone from module.js.');

        $this->assertDoesNotMatchRegularExpression(
            '~panel\.float(\.[a-z]+)?\s*=[^=]~',
            $body[1],
            'applyFloat() writes back to the stored geometry. It runs on every scroll and '
                .'resize frame, so the position the user chose would be ground down to '
                .'whatever the smallest window they ever opened could hold.'
        );
    }

    /**
     * Geometry is written on mouseup, not on every frame of a drag.
     *
     * @return void
     */
    public function testDraggingAndResizingPersistOnceEach()
    {
        $this->assertSame(
            2,
            preg_match_all('~savePrefs\(floatPrefs\(\)\);~', $this->js()),
            'The drag and the resize handlers must each write the geometry exactly once, on '
                .'mouseup. One call per mousemove would post a request per frame.'
        );
    }

    /**
     * The endpoint stores the shape and the box.
     *
     * @return void
     */
    public function testThePrefsEndpointStoresTheWindow()
    {
        $response = $this->actingAs($this->agent)->csrfPost('/aichatpanel/prefs', [
            'panel_mode'         => UserPref::MODE_FLOATING,
            'panel_float_x'      => 120,
            'panel_float_y'      => 80,
            'panel_float_width'  => 460,
            'panel_float_height' => 620,
        ]);

        $response->assertStatus(200);

        $pref = UserPref::forUser($this->agent->id);

        $this->assertEquals(UserPref::MODE_FLOATING, $pref->panel_mode);
        $this->assertEquals(120, $pref->panel_float_x);
        $this->assertEquals(80, $pref->panel_float_y);
        $this->assertEquals(460, $pref->panel_float_width);
        $this->assertEquals(620, $pref->panel_float_height);
    }

    /**
     * Nothing the browser sends is taken at face value.
     *
     * @return void
     */
    public function testTheStoredWindowIsClamped()
    {
        $this->actingAs($this->agent)->csrfPost('/aichatpanel/prefs', [
            'panel_mode'         => 99,
            'panel_float_width'  => 5000,
            'panel_float_height' => 10,
        ]);

        $pref = UserPref::forUser($this->agent->id);

        $this->assertEquals(
            UserPref::MODE_DOCKED,
            $pref->panel_mode,
            'A mode the module does not know must fall back to the docked column.'
        );
        $this->assertEquals(UserPref::FLOAT_WIDTH_MAX, $pref->panel_float_width);
        $this->assertEquals(UserPref::FLOAT_HEIGHT_MIN, $pref->panel_float_height);
    }

    /**
     * The rest of the row is left alone: the endpoint updates what it is sent
     * and nothing else.
     *
     * @return void
     */
    public function testStoringTheWindowLeavesTheOtherPreferences()
    {
        $pref = UserPref::forUser($this->agent->id);
        $pref->panel_open = true;
        $pref->panel_width = 500;
        $pref->save();

        $this->actingAs($this->agent)->csrfPost('/aichatpanel/prefs', [
            'panel_mode' => UserPref::MODE_FLOATING,
        ]);

        $pref = UserPref::forUser($this->agent->id);

        $this->assertTrue((bool) $pref->panel_open);
        $this->assertEquals(500, $pref->panel_width);
    }

    /**
     * A window that has never been placed has no geometry to restore, which is
     * what tells the panel to seed one.
     *
     * @return void
     */
    public function testAFreshUserHasNoStoredWindow()
    {
        $pref = UserPref::forUser($this->agent->id);

        $this->assertEquals(UserPref::MODE_FLOATING, $pref->panel_mode);
        $this->assertNull($pref->panel_float_x);
        $this->assertNull($pref->panel_float_width);
    }

    /**
     * The shape nobody has chosen is the window, and the panel is still shut
     * until it is asked for.
     *
     * @return void
     */
    public function testAFreshUserStartsClosedAndUndocked()
    {
        $pref = UserPref::forUser($this->agent->id);

        $this->assertFalse((bool) $pref->panel_open);
        $this->assertEquals(UserPref::MODE_FLOATING, UserPref::MODE_DEFAULT);
        $this->assertEquals(UserPref::MODE_DEFAULT, $pref->panel_mode);
    }

    /**
     * Docking is a choice, and a choice survives the default changing.
     *
     * @return void
     */
    public function testADockedPreferenceIsKept()
    {
        $pref = UserPref::forUser($this->agent->id);
        $pref->panel_mode = UserPref::MODE_DOCKED;
        $pref->save();

        $this->assertEquals(UserPref::MODE_DOCKED, UserPref::forUser($this->agent->id)->panel_mode);
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
