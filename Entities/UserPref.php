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

    protected $table = 'aichatpanel_user_prefs';

    protected $fillable = [
        'user_id',
        'panel_open',
        'panel_width',
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
}
