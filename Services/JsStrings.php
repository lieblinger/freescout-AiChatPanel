<?php

namespace Modules\AiChatPanel\Services;

/**
 * Every string module.js renders on its own.
 *
 * These used to live in Resources/views/js/vars.blade.php, compiled by
 * `freescout:module-build` into Public/js/vars.js and read back through core's
 * Lang object. That is the mechanism the module generator sets up, and it does
 * not survive being installed: nothing in core ever calls freescout:module-build
 * — not the Modules screen, which runs freescout:module-install, and not
 * module-install itself, which only migrates and creates the Public symlink. A
 * module installed from its zip therefore had no vars.js at all, every key fell
 * back to the English literal in module.js, and a German panel rendered its
 * server-side half in German and its client-side half in English.
 *
 * So the strings travel the way everything else the panel needs travels: as a
 * data- attribute, rendered by Blade at request time. That means no build step
 * to forget, nothing to 404, and one locale on the wire instead of the 31 a
 * built vars.js carries.
 *
 * Keys here and the first argument of every t() call in module.js are the same
 * set, and JsStringsTest fails if they ever drift apart.
 */
class JsStrings
{
    /**
     * @return array
     */
    public static function all()
    {
        return [
            // -- Settings page -------------------------------------------------
            'testing'            => __('Testing…'),
            'loading'            => __('Loading…'),
            'test_failed'        => __('The connection test failed.'),
            'test_http_error'    => __('The connection test request failed (HTTP :code).'),
            'probe_models'       => __('Model list'),
            'probe_completion'   => __('Completion'),
            'probe_tools'        => __('Tool calling'),
            'models_failed'      => __('Could not load the model list.'),
            'models_empty'       => __('The endpoint did not report any models.'),
            'models_loaded'      => __('Loaded :count model(s).'),

            // -- Panel ---------------------------------------------------------
            'empty_title'        => __('Ask about this conversation, or pick one of the shortcuts below.'),
            'empty_hint'         => __('Nothing you write here is sent to the customer. Drafts are inserted into the reply editor for you to review.'),
            'load_failed'        => __('Could not load the chat.'),
            'reset_failed'       => __('Could not start a new chat.'),
            'resolve_pending'    => __('Approve or reject the pending action first.'),
            'forbidden'          => __('You do not have access to this conversation.'),
            'rate_limited'       => __('You are sending messages too quickly. Wait a moment and try again.'),
            'http_error'         => __('The request failed (HTTP :code).'),
            'stream_failed'      => __('The connection to the assistant was interrupted.'),
            'thinking'           => __('Thinking…'),
            'running_tool'       => __('Running :tool…'),
            'tools_unsupported'  => __('The selected model cannot use tools, so they are disabled.'),
            'model_no_tools'     => __('(no tools)'),
            'show_reasoning'     => __('Show reasoning'),
            'reasoning_only'     => __('The model put its whole answer into its reasoning and returned nothing. Open “Show reasoning” to read it, or ask again.'),
            'answer_truncated'   => __('The model used its whole response budget before writing an answer. Raise “Max response tokens” in the settings, then ask again.'),
            'tool_ran'           => __('Ran :tool'),
            'tool_failed'        => __(':tool failed'),
            'day_today'          => __('Today'),
            'day_yesterday'      => __('Yesterday'),

            // -- Panel shape ---------------------------------------------------
            'dock'               => __('Dock'),
            'undock'             => __('Undock'),

            // -- Message actions -----------------------------------------------
            'insert_reply'       => __('Insert into reply'),
            'insert_reply_short' => __('Reply'),
            'insert_note'        => __('Insert as internal note'),
            'insert_note_short'  => __('Note'),
            'copy'               => __('Copy'),
            'copied'             => __('Copied to clipboard'),
            'copy_failed'        => __('Could not copy.'),
            'inserted'           => __('Inserted into the editor. Review it before sending.'),
            'no_editor'          => __('The reply editor is not available on this page.'),
            'open_draft'         => __('Open in editor'),
            'close_reply_first'  => __('Close the open reply first.'),
            'close_note_first'   => __('Close the open note first, then insert as a reply.'),

            // -- Write confirmation --------------------------------------------
            'confirm_title'      => __('The assistant wants to make a change'),
            'approve'            => __('Approve'),
            'reject'             => __('Reject'),
        ];
    }

    /**
     * The map as the value of a data- attribute.
     *
     * Blade escapes it on output, so this is plain JSON: no manual escaping
     * here, and never interpolated into a script tag — the CSP is
     * script-src 'self'.
     *
     * @return string
     */
    public static function json()
    {
        return \Helper::jsonEncodeUtf8(self::all());
    }
}
