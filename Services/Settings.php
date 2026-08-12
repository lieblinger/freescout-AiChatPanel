<?php

namespace Modules\AiChatPanel\Services;

use App\Mailbox;

/**
 * Typed access to the module's settings.
 *
 * Two layers:
 *   - global, in the options table under "aichatpanel.<name>", defaults from
 *     Config/config.php (core resolves those itself, see core/app/Option.php:102);
 *   - per mailbox, in the mailbox's meta JSON column under "aichatpanel".
 *
 * Always read through here rather than calling \Option::get() directly, so the
 * mailbox override and the secret decryption stay in one place.
 */
class Settings
{
    /**
     * Rendered in place of a stored secret. Never a value a user could type by
     * accident, and never equal to an empty setting.
     */
    const MASK = '••••••••••••';

    /**
     * Option keys that hold encrypted values.
     */
    public static $encrypted = [
        'api_key',
    ];

    /**
     * Settings a mailbox may override. Anything not listed here is global only,
     * which is deliberate: connection and safety limits are not per-mailbox.
     */
    public static $mailbox_overridable = [
        'enabled',
        'system_prompt_addition',
        'reply_language',
        'reply_tone',
        'include_notes',
        'include_signature',
        'tools_enabled',
        'context_providers',
    ];

    /**
     * Read a setting, optionally resolving the mailbox override.
     *
     * @param string       $name
     * @param Mailbox|null $mailbox
     *
     * @return mixed
     */
    public static function get($name, $mailbox = null)
    {
        if ($mailbox && in_array($name, self::$mailbox_overridable)) {
            $meta = self::mailboxMeta($mailbox);
            if (array_key_exists($name, $meta) && $meta[$name] !== null && $meta[$name] !== '') {
                return $meta[$name];
            }
        }

        return \Option::get(AICHATPANEL_MODULE.'.'.$name, self::defaultOf($name));
    }

    /**
     * Write an option and keep core's in-process cache honest.
     *
     * \Option::set() updates the database but does NOT invalidate
     * \Option::$cache (core/app/Option.php:35), so a get() later in the same
     * request returns the value from before the write. Every write in this
     * module goes through here.
     *
     * @param string $name Un-prefixed option name.
     * @param mixed  $value
     *
     * @return void
     */
    public static function put($name, $value)
    {
        $key = AICHATPANEL_MODULE.'.'.$name;

        \Option::set($key, $value);

        unset(\Option::$cache[$key]);
    }

    /**
     * Default for an option, straight out of Config/config.php.
     *
     * @param string $name
     *
     * @return mixed
     */
    public static function defaultOf($name)
    {
        $options = \Config::get(AICHATPANEL_MODULE.'.options', []);

        return isset($options[$name]['default']) ? $options[$name]['default'] : null;
    }

    /**
     * A hard ceiling from Config/config.php that the admin UI cannot exceed.
     *
     * @param string $name
     * @param mixed  $fallback
     *
     * @return mixed
     */
    public static function limit($name, $fallback = null)
    {
        return \Config::get(AICHATPANEL_MODULE.'.limits.'.$name, $fallback);
    }

    /**
     * The decrypted API key. Never send this to the browser.
     *
     * @return string
     */
    public static function apiKey()
    {
        $stored = \Option::get(AICHATPANEL_MODULE.'.api_key', '');

        if (!$stored) {
            return '';
        }

        // decryptSoft returns the original value when it is not decryptable,
        // which covers a key written before encryption was in place.
        $value = \Helper::decryptSoft($stored);

        return is_string($value) ? $value : '';
    }

    /**
     * Whether the module is switched on and minimally configured.
     *
     * @param Mailbox|null $mailbox
     *
     * @return bool
     */
    public static function isUsable($mailbox = null)
    {
        if (!self::get('enabled')) {
            return false;
        }

        if (!self::baseUrl()) {
            return false;
        }

        if ($mailbox && !self::get('enabled', $mailbox)) {
            return false;
        }

        return true;
    }

    /**
     * Normalised base URL, without a trailing slash and without a trailing
     * /v1 (we append the versioned path ourselves).
     *
     * @return string
     */
    public static function baseUrl()
    {
        $url = trim((string) self::get('base_url'));

        if (!$url) {
            return '';
        }

        $url = rtrim($url, '/');
        $url = preg_replace('#/v1$#', '', $url);

        return rtrim($url, '/');
    }

    /**
     * The admin's model allowlist, one per line. An empty list means "anything
     * the endpoint offers".
     *
     * @return array
     */
    public static function allowedModels()
    {
        $raw = (string) self::get('allowed_models');

        $models = preg_split('/[\r\n,]+/', $raw);
        $models = array_map('trim', $models ?: []);

        return array_values(array_filter($models, function ($m) {
            return $m !== '';
        }));
    }

    /**
     * Whether a model name passes the allowlist.
     *
     * @param string $model
     *
     * @return bool
     */
    public static function modelAllowed($model)
    {
        $allowed = self::allowedModels();

        if (!$allowed) {
            return (bool) $model;
        }

        return in_array($model, $allowed);
    }

    /**
     * Per-model tool support: true, false, or null meaning "not probed yet".
     *
     * @param string $model
     *
     * @return bool|null
     */
    public static function modelSupportsTools($model)
    {
        $map = self::get('model_tool_support');

        if (!is_array($map) || !array_key_exists($model, $map)) {
            return null;
        }

        return (bool) $map[$model];
    }

    /**
     * Remember the outcome of a tool-support probe.
     *
     * @param string $model
     * @param bool   $supported
     *
     * @return void
     */
    public static function rememberModelToolSupport($model, $supported)
    {
        $map = self::get('model_tool_support');

        if (!is_array($map)) {
            $map = [];
        }

        $map[$model] = (bool) $supported;

        self::put('model_tool_support', $map);
    }

    // -----------------------------------------------------------------------
    // Per-mailbox
    // -----------------------------------------------------------------------

    /**
     * The mailbox's AiChatPanel meta block.
     *
     * @param Mailbox $mailbox
     *
     * @return array
     */
    public static function mailboxMeta($mailbox)
    {
        if (!$mailbox) {
            return [];
        }

        // Mailbox has setMetaParam()/removeMetaParam() but no getter; meta is
        // cast to array on the model (core/app/Mailbox.php:144), so read it
        // straight off the attribute.
        $meta = $mailbox->meta;

        if (!is_array($meta) || !isset($meta[AICHATPANEL_MODULE]) || !is_array($meta[AICHATPANEL_MODULE])) {
            return [];
        }

        return $meta[AICHATPANEL_MODULE];
    }

    /**
     * Replace the mailbox's AiChatPanel meta block.
     *
     * @param Mailbox $mailbox
     * @param array   $values
     *
     * @return void
     */
    public static function setMailboxMeta($mailbox, array $values)
    {
        $mailbox->setMetaParam(AICHATPANEL_MODULE, $values, true);
    }

    // -----------------------------------------------------------------------
    // Global settings page (core persists these for us)
    // -----------------------------------------------------------------------

    /**
     * The settings the section renders and core saves, as key => current value.
     *
     * @return array
     */
    public static function sectionSettings()
    {
        $settings = [];

        foreach (array_keys(\Config::get(AICHATPANEL_MODULE.'.options', [])) as $name) {
            $key = AICHATPANEL_MODULE.'.'.$name;

            $settings[$key] = in_array($name, self::$encrypted)
                ? (\Option::get($key) ? self::MASK : '')
                : \Option::get($key, self::defaultOf($name));
        }

        return $settings;
    }

    /**
     * Declare which settings are encrypted, plus their defaults so core knows
     * what to write when a checkbox comes back unchecked.
     *
     * @param array $params
     *
     * @return array
     */
    public static function sectionParams($params)
    {
        // Extra variables for the Blade view. The catalogues are listed with a
        // null context: the settings page is not about any one conversation.
        $params['template_vars'] = [
            'tool_catalogue'     => \Modules\AiChatPanel\Services\Tools\ToolRegistry::catalogue(),
            'provider_catalogue' => \Modules\AiChatPanel\Services\Context\ProviderRegistry::catalogue(),
        ];

        $params['settings'] = [];

        foreach (\Config::get(AICHATPANEL_MODULE.'.options', []) as $name => $meta) {
            $entry = [];

            if (isset($meta['default'])) {
                $entry['default'] = $meta['default'];
            }

            if (in_array($name, self::$encrypted)) {
                $entry['encrypt'] = true;
            }

            $params['settings'][AICHATPANEL_MODULE.'.'.$name] = $entry;
        }

        return $params;
    }

    /**
     * Keep a masked secret from overwriting the stored one, and clamp the
     * numeric settings to the ceilings in Config/config.php.
     *
     * Runs on the settings.before_save filter, i.e. before core writes anything.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Request
     */
    public static function preserveMaskedSecrets($request)
    {
        $settings = $request->settings;

        if (!is_array($settings)) {
            return $request;
        }

        foreach (self::$encrypted as $name) {
            $key = AICHATPANEL_MODULE.'.'.$name;

            if (!array_key_exists($key, $settings)) {
                continue;
            }

            // Unchanged mask: substitute the stored plaintext. Removing the key
            // instead would send core down the else branch of processSave()
            // (SettingsController.php:343), which deletes the option.
            if ($settings[$key] === self::MASK) {
                $settings[$key] = self::apiKey();
            }
        }

        $settings = self::clamp($settings);

        $request->merge(['settings' => $settings]);

        return $request;
    }

    /**
     * Clamp numeric settings into their allowed range. A mistyped iteration cap
     * must not turn one message into an unbounded agent run.
     *
     * @param array $settings
     *
     * @return array
     */
    protected static function clamp(array $settings)
    {
        $rules = [
            'max_tool_iterations' => [1, self::limit('max_tool_iterations', 10)],
            'max_tool_seconds'    => [5, self::limit('max_tool_seconds', 120)],
            'request_timeout'     => [5, self::limit('max_request_timeout', 600)],
            'connect_timeout'     => [1, 120],
            'max_context_tokens'  => [500, self::limit('max_context_tokens', 1000000)],
            'max_response_tokens' => [64, self::limit('max_response_tokens', 100000)],
            'retention_days'      => [0, 3650],
            'audit_retention_days' => [0, 3650],
            'rate_limit_completions' => [1, 10000],
            'rate_limit_tools'       => [1, 10000],
        ];

        foreach ($rules as $name => $range) {
            $key = AICHATPANEL_MODULE.'.'.$name;

            if (!array_key_exists($key, $settings)) {
                continue;
            }

            $value = (int) $settings[$key];
            $settings[$key] = max($range[0], min($range[1], $value));
        }

        $temperature_key = AICHATPANEL_MODULE.'.temperature';
        if (array_key_exists($temperature_key, $settings)) {
            $settings[$temperature_key] = max(0, min(2, (float) $settings[$temperature_key]));
        }

        // Textareas that model a list arrive as text and are stored as arrays.
        foreach (['prompt_shortcuts'] as $name) {
            $key = AICHATPANEL_MODULE.'.'.$name;

            if (array_key_exists($key, $settings) && is_string($settings[$key])) {
                $lines = preg_split('/[\r\n]+/', $settings[$key]);
                $lines = array_map('trim', $lines ?: []);
                $settings[$key] = array_values(array_filter($lines, function ($l) {
                    return $l !== '';
                }));
            }
        }

        return $settings;
    }
}
