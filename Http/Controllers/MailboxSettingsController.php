<?php

namespace Modules\AiChatPanel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mailbox;
use Illuminate\Http\Request;
use Modules\AiChatPanel\Services\Context\ProviderRegistry;
use Modules\AiChatPanel\Services\Settings;
use Modules\AiChatPanel\Services\Tools\Tool;
use Modules\AiChatPanel\Services\Tools\ToolRegistry;

/**
 * Per-mailbox overrides.
 *
 * A page of its own rather than extra fields on Edit Mailbox: there are more
 * than a handful of settings, and MailboxesController::updateSave() whitelists
 * its fields anyway (MailboxesController.php:222), so a module cannot add a
 * column-backed field to that form.
 *
 * Values live in the mailbox's meta JSON column.
 */
class MailboxSettingsController extends Controller
{
    /**
     * @param Request $request
     * @param int     $mailbox_id
     *
     * @return \Illuminate\View\View
     */
    public function view(Request $request, $mailbox_id)
    {
        $mailbox = Mailbox::findOrFail($mailbox_id);

        // Same gate core uses for its own mailbox settings pages.
        $this->authorize('update', $mailbox);

        return view(AICHATPANEL_MODULE.'::mailbox_settings', [
            'mailbox'            => $mailbox,
            'meta'               => Settings::mailboxMeta($mailbox),
            'tool_catalogue'     => ToolRegistry::catalogue(),
            'provider_catalogue' => ProviderRegistry::catalogue(),
            'globals'            => [
                'enabled'           => (bool) Settings::get('enabled'),
                'include_notes'     => (bool) Settings::get('include_notes'),
                'tools_enabled'     => (array) Settings::get('tools_enabled'),
                'context_providers' => (array) Settings::get('context_providers'),
            ],
        ]);
    }

    /**
     * @param Request $request
     * @param int     $mailbox_id
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function save(Request $request, $mailbox_id)
    {
        $mailbox = Mailbox::findOrFail($mailbox_id);

        $this->authorize('update', $mailbox);

        $meta = Settings::mailboxMeta($mailbox);

        // A tri-state per setting: inherit (absent), on, off. "inherit" has to
        // be a real option, otherwise saving this page would silently pin every
        // mailbox to whatever the global defaults happened to be that day.
        foreach (['enabled', 'include_notes', 'include_signature'] as $flag) {
            $value = $request->input($flag, 'inherit');

            if ($value === 'inherit') {
                unset($meta[$flag]);
            } else {
                $meta[$flag] = (bool) $value;
            }
        }

        foreach (['system_prompt_addition', 'reply_language', 'reply_tone'] as $text) {
            $value = trim((string) $request->input($text, ''));

            if ($value === '') {
                unset($meta[$text]);
            } else {
                $meta[$text] = mb_substr($value, 0, 5000);
            }
        }

        // Lists: an explicit empty list is meaningful ("no tools here"), so the
        // inherit case is signalled by a separate radio rather than by absence.
        foreach (['tools_enabled' => 'tools_mode', 'context_providers' => 'providers_mode'] as $list => $mode_field) {
            if ($request->input($mode_field) === 'inherit') {
                unset($meta[$list]);
                continue;
            }

            $values = (array) $request->input($list, []);
            $allowed = $list === 'tools_enabled'
                ? array_keys(ToolRegistry::catalogue())
                : array_keys(ProviderRegistry::catalogue());

            // Never store a name that is not a registered tool or provider.
            $meta[$list] = array_values(array_intersect($values, $allowed));
        }

        Settings::setMailboxMeta($mailbox, $meta);

        \Session::flash('flash_success_floating', __('Settings saved'));

        return redirect()->route('aichatpanel.mailbox.settings', ['mailbox_id' => $mailbox->id]);
    }
}
