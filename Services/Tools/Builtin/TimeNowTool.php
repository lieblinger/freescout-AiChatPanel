<?php

namespace Modules\AiChatPanel\Services\Tools\Builtin;

use App\Thread;
use Modules\AiChatPanel\Services\Clock;
use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\ToolResult;

/**
 * Read tool: the current date and time, and how old this conversation is.
 *
 * The clock itself is already in the system message — it is a couple of dozen
 * tokens and has to work for models with tool calling switched off. What is
 * here and not there is the arithmetic: how long the conversation has been
 * open, and how long since anyone last wrote. Models are unreliable at
 * subtracting dates and give no sign when they get it wrong, so the answer is
 * computed in PHP and handed over as words.
 *
 * It lives behind a tool rather than in the prompt for the reason
 * ContextProvider documents: a block in the system message is paid for on every
 * single message, and most messages never ask what time it is.
 */
class TimeNowTool extends AbstractTool
{
    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return 'time_now';
    }

    /**
     * {@inheritdoc}
     */
    public function description()
    {
        return 'Get the current date and time, and how long the open conversation has been running. '
            .'Call this whenever the answer depends on when something happened — how old a ticket is, '
            .'how long a customer has been waiting, whether a date has passed, what "today", "tomorrow" '
            .'or "next Monday" refer to. Do not work these out yourself from the timestamps you were '
            .'given. All times are in the support agent\'s timezone, which is also reported.';
    }

    /**
     * {@inheritdoc}
     */
    public function parameters()
    {
        return $this->noParameters();
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $arguments, PanelContext $context)
    {
        $user = $context->user;
        $conversation = $context->conversation;
        $now = Clock::now($user);

        $last_message = $this->lastMessage($context);

        return ToolResult::ok([
            'now'        => $now->format(Clock::FORMAT_DATE_TIME),
            'weekday'    => $now->format('l'),
            'timezone'   => Clock::timezone($user),
            'utc_offset' => $now->format('P'),
            // The one machine-readable form, for a model that would rather
            // compute than read: unambiguous about the offset, unlike the rest.
            'iso8601'    => $now->format('c'),
            'conversation' => [
                'number'             => (int) $conversation->number,
                'created_at'         => Clock::dateTime($conversation->created_at, $user) ?: null,
                'age'                => Clock::humanDiff($conversation->created_at, $user) ?: null,
                // Null rather than absent, and null rather than an error: a
                // conversation with nothing in it yet is a normal state the
                // model should read and say out loud.
                'last_message_at'    => $last_message ? Clock::dateTime($last_message->created_at, $user) : null,
                'since_last_message' => $last_message ? Clock::humanDiff($last_message->created_at, $user) : null,
            ],
        ], __('Checked the current date and time'));
    }

    /**
     * The newest message in the conversation, drafts and status changes aside.
     *
     * Same set of threads ContextBuilder::threads() shows the model, minus
     * notes: "how long since the last message" is about the correspondence, and
     * an internal note nobody outside saw does not reset that clock.
     *
     * @param PanelContext $context
     *
     * @return Thread|null
     */
    protected function lastMessage(PanelContext $context)
    {
        return $context->conversation->threads()
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            ->where('state', Thread::STATE_PUBLISHED)
            ->orderBy('id', 'desc')
            ->first();
    }
}
