<?php

namespace Modules\AiChatPanel\Services\Tools\Builtin;

use App\Conversation;
use App\Thread;
use Modules\AiChatPanel\Services\Context\ThreadFormatter;
use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\ToolResult;

/**
 * Read tool: fetch one conversation by its number.
 *
 * Reference implementation. Note two things:
 *
 *   - the lookup is by conversation *number*, not id, because that is what
 *     appears in the UI and in what customers write; ids are an internal detail
 *     the model should not be reasoning about;
 *   - a conversation the user may not view is reported as not found, not as
 *     forbidden. Saying "that exists but you may not see it" leaks its
 *     existence into the prompt.
 */
class GetConversationTool extends AbstractTool
{
    /** Enough to answer a question, few enough not to blow the context. */
    const MAX_MESSAGES = 20;

    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return 'conversation_get';
    }

    /**
     * {@inheritdoc}
     */
    public function description()
    {
        return 'Fetch a single conversation by its number, including its messages. '
            .'Use this when the customer or the agent refers to another ticket by number, '
            .'or after conversation_list_customer_conversations to read one of the results. '
            .'Internal notes are only included when the mailbox allows it.';
    }

    /**
     * {@inheritdoc}
     */
    public function parameters()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'number' => [
                    'type'        => 'integer',
                    'description' => 'The conversation number, as shown in the interface (without the # sign).',
                    'minimum'     => 1,
                ],
                'max_messages' => [
                    'type'        => 'integer',
                    'description' => 'How many of the most recent messages to include. Defaults to 10.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_MESSAGES,
                ],
            ],
            'required' => ['number'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $arguments, PanelContext $context)
    {
        $number = (int) $arguments['number'];
        $max = isset($arguments['max_messages']) ? (int) $arguments['max_messages'] : 10;
        $max = max(1, min(self::MAX_MESSAGES, $max));

        $mailbox_ids = $context->user->mailboxesIdsCanView();

        if (!$mailbox_ids) {
            return ToolResult::error('Conversation #'.$number.' was not found.');
        }

        // The number a user (and therefore the model) sees is not always the
        // `number` column: with the default custom_number setting off, core's
        // getNumberAttribute() returns the id instead. numberFieldName() tells
        // us which column that display value actually lives in.
        $conversation = Conversation::where(Conversation::numberFieldName(), $number)
            ->whereIn('mailbox_id', $mailbox_ids)
            ->first();

        // Same answer for "does not exist" and "not allowed to see it": the
        // difference is itself information.
        if (!$conversation || !$context->canViewConversation($conversation)) {
            return ToolResult::error(
                'Conversation #'.$number.' was not found, or you do not have access to it.'
            );
        }

        $types = [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE];

        if ($context->setting('include_notes')) {
            $types[] = Thread::TYPE_NOTE;
        }

        $threads = $conversation->threads()
            ->whereIn('type', $types)
            ->where('state', Thread::STATE_PUBLISHED)
            ->orderBy('id', 'desc')
            ->limit($max)
            ->get();

        $messages = [];

        foreach ($threads->reverse() as $thread) {
            $body = ThreadFormatter::body($thread);

            if (trim($body) === '') {
                continue;
            }

            $messages[] = [
                'kind'      => ThreadFormatter::kind($thread),
                'author'    => ThreadFormatter::author($thread),
                'date'      => $thread->created_at ? $thread->created_at->toDateTimeString() : null,
                // Keep individual messages bounded: one runaway thread should
                // not be able to consume the whole remaining context.
                'body'      => \Illuminate\Support\Str::limit($body, 4000),
            ];
        }

        $customer = $conversation->customer;

        return ToolResult::ok([
            'number'      => (int) $conversation->number,
            'subject'     => (string) $conversation->subject,
            'status'      => $this->statusName($conversation->status),
            'created_at'  => $conversation->created_at ? $conversation->created_at->toDateTimeString() : null,
            'mailbox'     => $conversation->mailbox ? $conversation->mailbox->name : null,
            'assignee'    => $conversation->user ? $conversation->user->getFullName() : null,
            'customer'    => $customer ? $customer->getFullName(true) : null,
            'messages'    => $messages,
            'message_count_returned' => count($messages),
        ], __('Read conversation #:number', ['number' => $conversation->number]));
    }

    /**
     * @param int $status
     *
     * @return string
     */
    protected function statusName($status)
    {
        $map = [
            Conversation::STATUS_ACTIVE  => 'active',
            Conversation::STATUS_PENDING => 'pending',
            Conversation::STATUS_CLOSED  => 'closed',
            Conversation::STATUS_SPAM    => 'spam',
        ];

        return isset($map[$status]) ? $map[$status] : 'unknown';
    }
}
