<?php

namespace Modules\AiChatPanel\Services;

use App\User;
use Carbon\Carbon;

/**
 * Every timestamp the model is shown goes through here.
 *
 * FreeScout stores dates in the application timezone and renders them in the
 * viewing user's, so a raw `->toDateTimeString()` in the prompt is a different
 * time from the one on the agent's screen — seven hours out for a Tokyo agent
 * on a European install. The model then answers "the customer wrote at 10:10"
 * about a message the agent is looking at timestamped 17:10, and every "how
 * long ago" is wrong by the offset. One helper, used by the prompt builder, the
 * tools and the context providers, keeps all of it on the agent's clock.
 *
 * Deliberately not \App\User::dateFormat(): that ends in formatLocalized() and
 * IntlDateFormatter (core/app/User.php), so month and weekday names come out in
 * the viewer's locale. The model wants one stable, sortable format regardless
 * of who is logged in — the same reasoning as Entities\Message::dayKey().
 */
class Clock
{
    /** Sortable, unambiguous, and cheap in tokens. */
    const FORMAT_DATE_TIME = 'Y-m-d H:i';

    const FORMAT_DATE = 'Y-m-d';

    /**
     * The timezone every date shown to the model is rendered in.
     *
     * @param User|null $user
     *
     * @return string
     */
    public static function timezone($user = null)
    {
        if ($user && $user->timezone) {
            return $user->timezone;
        }

        return config('app.timezone') ?: 'UTC';
    }

    /**
     * Now, on the agent's clock.
     *
     * @param User|null $user
     *
     * @return Carbon
     */
    public static function now($user = null)
    {
        return Carbon::now()->setTimezone(self::timezone($user));
    }

    /**
     * '+02:00' — stated once in the prompt so a model that needs to compare
     * against something outside FreeScout can.
     *
     * @param User|null $user
     *
     * @return string
     */
    public static function offset($user = null)
    {
        return self::now($user)->format('P');
    }

    /**
     * @param Carbon|string|null $date
     * @param User|null          $user
     *
     * @return string Empty when there is no date, never a misleading fallback.
     */
    public static function dateTime($date, $user = null)
    {
        $date = self::toUserZone($date, $user);

        return $date ? $date->format(self::FORMAT_DATE_TIME) : '';
    }

    /**
     * Day granularity. Still timezone-converted: the day boundary moves too,
     * and a conversation opened at 23:30 UTC belongs to the next day in Berlin.
     *
     * @param Carbon|string|null $date
     * @param User|null          $user
     *
     * @return string
     */
    public static function date($date, $user = null)
    {
        $date = self::toUserZone($date, $user);

        return $date ? $date->format(self::FORMAT_DATE) : '';
    }

    /**
     * How long ago, in words: "3 days", "2 hours", "just now".
     *
     * Hand-rolled rather than Carbon's diffForHumans() on purpose. Carbon is
     * 1.35.1 here, which has no per-instance locale, so diffForHumans() reads
     * the global translator locale — the one core's Localize middleware sets
     * from the logged-in user. A German agent would get "vor 3 Tagen" embedded
     * in an otherwise English prompt, and the phrasing of a tool result would
     * change with who is looking at it.
     *
     * @param Carbon|string|null $date
     * @param User|null          $user
     *
     * @return string Empty when there is no date.
     */
    public static function humanDiff($date, $user = null)
    {
        $date = self::toUserZone($date, $user);

        if (!$date) {
            return '';
        }

        $now = self::now($user);

        $minutes = $date->diffInMinutes($now);

        if ($minutes < 1) {
            return 'just now';
        }

        if ($minutes < 60) {
            return self::plural($minutes, 'minute');
        }

        $hours = $date->diffInHours($now);

        if ($hours < 24) {
            return self::plural($hours, 'hour');
        }

        $days = $date->diffInDays($now);

        if ($days < 31) {
            return self::plural($days, 'day');
        }

        $months = $date->diffInMonths($now);

        if ($months < 12) {
            return self::plural($months, 'month');
        }

        return self::plural($date->diffInYears($now), 'year');
    }

    /**
     * @param Carbon|string|null $date
     * @param User|null          $user
     *
     * @return Carbon|null
     */
    protected static function toUserZone($date, $user = null)
    {
        if (!$date) {
            return null;
        }

        if (is_string($date)) {
            try {
                $date = Carbon::parse($date);
            } catch (\Exception $e) {
                return null;
            }
        }

        if (!$date instanceof Carbon) {
            return null;
        }

        // copy() is load-bearing: setTimezone() mutates the instance, and the
        // instance handed in is usually a model's own created_at attribute.
        // Converting it in place would silently change the date for everything
        // else holding that model in this request.
        return $date->copy()->setTimezone(self::timezone($user));
    }

    /**
     * @param int    $count
     * @param string $unit
     *
     * @return string
     */
    protected static function plural($count, $unit)
    {
        return $count.' '.$unit.($count === 1 ? '' : 's');
    }
}
