<?php

namespace Modules\AiChatPanel\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * Panel state that should follow the user between browsers.
 */
class UserPref extends Model
{
    const WIDTH_MIN     = 300;
    const WIDTH_MAX     = 900;
    const WIDTH_DEFAULT = 380;

    /** Docked to the right edge, full height: the panel is a third column. */
    const MODE_DOCKED = 1;

    /** A window over the page, moved and sized by the user. */
    const MODE_FLOATING = 2;

    /**
     * The range the floating window may be dragged to.
     *
     * Wider than the docked range at both ends: a floating window does not have
     * to leave room for the thread beside it, and it does have to stay big
     * enough for the composer and a readable message.
     */
    const FLOAT_WIDTH_MIN  = 320;
    const FLOAT_WIDTH_MAX  = 1200;
    const FLOAT_HEIGHT_MIN = 300;
    const FLOAT_HEIGHT_MAX = 1200;

    /** Positions are clamped to the viewport client-side; this is only a sanity cap. */
    const FLOAT_POS_MAX = 10000;

    protected $table = 'aichatpanel_user_prefs';

    protected $fillable = [
        'user_id',
        'panel_open',
        'panel_width',
        'panel_mode',
        'panel_float_x',
        'panel_float_y',
        'panel_float_width',
        'panel_float_height',
        'last_model',
    ];

    protected $casts = [
        'panel_open' => 'boolean',
    ];

    /**
     * @param int $user_id
     *
     * @return static
     */
    public static function forUser($user_id)
    {
        $pref = self::where('user_id', $user_id)->first();

        if ($pref) {
            return $pref;
        }

        return self::create([
            'user_id'     => $user_id,
            'panel_open'  => false,
            'panel_width' => self::WIDTH_DEFAULT,
            'panel_mode'  => self::MODE_DOCKED,
        ]);
    }

    /**
     * Clamp to the range the CSS can actually lay out.
     *
     * @param mixed $width
     *
     * @return int
     */
    public static function clampWidth($width)
    {
        $width = (int) $width;

        if (!$width) {
            return self::WIDTH_DEFAULT;
        }

        return max(self::WIDTH_MIN, min(self::WIDTH_MAX, $width));
    }

    /**
     * Anything that is not a mode this module knows is the docked one.
     *
     * @param mixed $mode
     *
     * @return int
     */
    public static function clampMode($mode)
    {
        return (int) $mode === self::MODE_FLOATING ? self::MODE_FLOATING : self::MODE_DOCKED;
    }

    /**
     * Clamp one floating-window dimension.
     *
     * The browser clamps the same values against the actual viewport before it
     * uses them; this only keeps the stored row inside the column and inside
     * something the CSS can lay out.
     *
     * @param mixed $value
     * @param int   $min
     * @param int   $max
     *
     * @return int
     */
    public static function clampFloat($value, $min, $max)
    {
        return max($min, min($max, (int) $value));
    }
}
