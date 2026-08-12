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
    'name' => 'AiChatPanel',

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
        'max_context_tokens'  => ['default' => 8000],
        'system_prompt'       => ['default' => ''],
        'include_notes'       => ['default' => true],
        // Strip the mailbox signature from agent replies before they go into
        // the prompt. Per-mailbox overridable; detection is best-effort.
        'include_signature'   => ['default' => true],
        'context_providers'   => ['default' => ['aichatpanel.previous_conversations']],

        // -- Tools ---------------------------------------------------------
        'tools_enabled'       => ['default' => [
            'conversation.list_customer_conversations',
            'conversation.get',
            'customer.get',
        ]],
        'write_tools_enabled' => ['default' => false],
        // Individually named write tools allowed to run without confirmation.
        // There is deliberately no global "trust all" switch.
        'write_tools_autorun' => ['default' => []],
        'max_tool_iterations' => ['default' => 4],
        'max_tool_seconds'    => ['default' => 60],

        // -- Prompt shortcuts ----------------------------------------------
        // Rendered above the input. They only prefill; the user still sends.
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
