<?php

namespace Modules\AiChatPanel\Services\Agent;

/**
 * The result of one run of the agent loop.
 *
 * The loop does not touch the chat tables itself: it hands back the turns it
 * produced and the caller persists them. That keeps the loop testable without
 * a database and, more importantly, keeps "what happened" and "what we stored"
 * from drifting apart when a run is interrupted by a confirmation.
 */
class AgentOutcome
{
    /** Ran to a final answer. */
    const STATUS_COMPLETE = 'complete';
    /** Stopped on a write tool that needs the user to approve it. */
    const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';
    /** Gave up. $error holds a message already fit for the panel. */
    const STATUS_ERROR = 'error';

    /** @var string */
    public $status = self::STATUS_COMPLETE;

    /**
     * Turns to persist, oldest first. Each is an array shaped for
     * Entities\Message: role, body, reasoning, tool_calls, tool_call_id,
     * tool_name, status, meta.
     *
     * @var array
     */
    public $turns = [];

    /**
     * The write tool waiting on the user, when status is awaiting_confirmation.
     *
     * @var PendingWrite|null
     */
    public $pending = null;

    /** @var string Ready-to-display message when status is error. */
    public $error = '';

    /** @var string Machine-readable error type, from LlmException. */
    public $error_type = '';

    /**
     * Things the user should know that are not failures: context truncated,
     * tools disabled for this model, iteration cap reached.
     *
     * @var string[]
     */
    public $notices = [];

    /** @var array Usage totals summed over every request in the run. */
    public $usage = [];

    /** @var float Seconds. */
    public $duration = 0.0;

    /** @var string */
    public $model = '';

    /** @var int How many completions the run needed. */
    public $iterations = 0;

    /**
     * @param string $notice
     *
     * @return void
     */
    public function notice($notice)
    {
        if ($notice && !in_array($notice, $this->notices)) {
            $this->notices[] = $notice;
        }
    }

    /**
     * The last assistant turn, i.e. what the panel shows as the answer.
     *
     * @return array|null
     */
    public function finalTurn()
    {
        for ($i = count($this->turns) - 1; $i >= 0; $i--) {
            if ($this->turns[$i]['role'] === \Modules\AiChatPanel\Entities\Message::ROLE_ASSISTANT) {
                return $this->turns[$i];
            }
        }

        return null;
    }
}
