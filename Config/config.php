<?php

/*
|--------------------------------------------------------------------------
| AiChatPanel configuration
|--------------------------------------------------------------------------
|
| Everything under 'options' feeds \Option::getDefault(), which resolves a
| module option key of the form "<alias>.<name>" against
| config('<alias>.options')[<name>]['default'] — see core/app/Option.php:102.
|
| Read these through \Modules\AiChatPanel\Services\Settings, never directly:
| the settings service also applies per-mailbox overrides and decrypts the key.
|
*/

return [
    'name' => 'AI Chat Panel',

    // Hard ceilings the admin UI cannot exceed. These exist so a mistyped
    // setting cannot turn one chat message into an unbounded agent run.
    'limits' => [
        'max_tool_iterations'  => 10,
        'max_tool_seconds'     => 120,
        'max_request_timeout'  => 600,
        'max_context_tokens'   => 1000000,
        'max_response_tokens'  => 100000,
    ],

    // Rough characters-per-token used by the budget estimator when the
    // endpoint gives us nothing better. Deliberately pessimistic.
    'chars_per_token' => 3.5,

    // Share of max_context_tokens the chat history may occupy before its oldest
    // turns are dropped. The rest is guaranteed to the system message and the
    // conversation itself, so a long chat can never crowd out the ticket it is
    // about. Clamped to [0.1, 0.9] on read — see HistoryWindow::share().
    'history_token_share' => 0.5,

    // Share of max_context_tokens the conversation's own messages are
    // guaranteed, taken off the top before the chat about them may reserve
    // anything. Without it the chat is guaranteed a share and the ticket is
    // guaranteed nothing, so a long enough chat empties the history block out
    // of the system message entirely — and the model then answers from its own
    // stale earlier turns. Clamped to [0.1, 0.6] on read; see
    // ContextBuilder::threadFloorShare().
    'thread_token_floor' => 0.25,

    'options' => [
        // -- Connection ----------------------------------------------------
        'enabled'             => ['default' => false],
        'base_url'            => ['default' => ''],
        'api_key'             => ['default' => ''],
        'request_timeout'     => ['default' => 120],
        'connect_timeout'     => ['default' => 10],

        // -- Model ---------------------------------------------------------
        'default_model'       => ['default' => ''],
        // Newline-separated allowlist. Empty means "whatever the endpoint lists".
        'allowed_models'      => ['default' => ''],
        // model name => true|false. Absent means "probe on first use".
        'model_tool_support'  => ['default' => []],
        'temperature'         => ['default' => 0.3],
        'max_response_tokens' => ['default' => 2048],

        // -- Context -------------------------------------------------------
        // Sized for the models this panel is actually pointed at rather than
        // for the small local ones it was first written against: anything
        // current carries 128k or more, and the fixed part of the prompt alone
        // is ~3k, which was a fifth of the old default before the conversation
        // got a single token. The floor above keeps a long chat off the
        // conversation's share whatever this is set to; a bigger budget just
        // means far fewer installs ever reach it.
        'max_context_tokens'  => ['default' => 64000],
        'system_prompt'       => ['default' => ''],
        'include_notes'       => ['default' => true],
        // Strip the mailbox signature from agent replies before they go into
        // the prompt. Per-mailbox overridable; detection is best-effort.
        'include_signature'   => ['default' => true],
        // Whether stored personal data — postal address, phone numbers, social
        // profiles, customer notes, the agent's own contact details — may be
        // sent to the endpoint. Not an access-control setting: all of it is
        // already visible in FreeScout to anyone who can open the conversation.
        // This is about what leaves the building to a third-party processor.
        'send_personal_data'  => ['default' => true],
        'context_providers'   => ['default' => ['aichatpanel.previous_conversations']],

        // -- Tools ---------------------------------------------------------
        'tools_enabled'       => ['default' => [
            'time_now',
            'conversation_list_customer_conversations',
            'conversation_get',
            'conversation_get_drafts',
            'customer_get',
        ]],
        'write_tools_enabled' => ['default' => false],
        // Individually named write tools allowed to run without confirmation.
        // There is deliberately no global "trust all" switch.
        'write_tools_autorun' => ['default' => []],
        'max_tool_iterations' => ['default' => 4],
        'max_tool_seconds'    => ['default' => 60],

        // -- Prompt shortcuts ----------------------------------------------
        // Rendered above the input. Clicking one sends it, so each has to be a
        // complete prompt rather than an opening the user finishes.
        'prompt_shortcuts'    => ['default' => [
            'Draft a reply to the latest customer message.',
            'Summarise this thread in five bullet points.',
            'Draft an internal note summarising what is still open.',
            'Make the draft more concise.',
            'Make the draft more formal.',
        ]],

        // -- Retention -----------------------------------------------------
        'retention_days'       => ['default' => 90],
        'audit_retention_days' => ['default' => 365],

        // -- Limits and logging --------------------------------------------
        'rate_limit_completions' => ['default' => 20],
        'rate_limit_tools'       => ['default' => 60],
        // Off by default: prompt bodies contain customer data.
        'log_prompts'            => ['default' => false],
    ],
];
