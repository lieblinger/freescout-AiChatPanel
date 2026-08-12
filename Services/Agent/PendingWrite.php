<?php

namespace Modules\AiChatPanel\Services\Agent;

/**
 * A write tool the model asked for, paused until a human approves it.
 *
 * Everything needed to show the confirmation dialog and to resume afterwards.
 * The arguments here are the *validated* ones, so what the dialog shows is
 * exactly what would run — not what the model typed.
 */
class PendingWrite
{
    /** @var string The id the endpoint gave this tool call. */
    public $tool_call_id = '';

    /** @var string */
    public $tool = '';

    /** @var array Validated arguments. */
    public $arguments = [];

    /** @var string Translated sentence describing the effect. */
    public $label = '';

    /**
     * Tool calls in the same assistant turn that were already dealt with, so
     * resuming does not run them twice.
     *
     * Shape: [tool_call_id => tool message content]
     *
     * @var array
     */
    public $resolved = [];

    /**
     * Read tool calls in the same turn are executed before we pause, so their
     * results are already known. Anything still outstanding is listed here and
     * is executed on resume.
     *
     * @var array
     */
    public $outstanding = [];

    /**
     * @return array
     */
    public function toPanelArray()
    {
        return [
            'tool_call_id' => $this->tool_call_id,
            'tool'         => $this->tool,
            'arguments'    => $this->arguments,
            'label'        => $this->label,
        ];
    }
}
