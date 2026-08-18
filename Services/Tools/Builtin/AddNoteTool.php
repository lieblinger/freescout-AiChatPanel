<?php

namespace Modules\AiChatPanel\Services\Tools\Builtin;

use App\Thread;
use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\Tool;
use Modules\AiChatPanel\Services\Tools\ToolException;
use Modules\AiChatPanel\Services\Tools\ToolResult;

/**
 * Write tool: add an internal note to the current conversation.
 *
 * Reference implementation of a write tool. The three things that make it one:
 *
 *   - mode() returns MODE_WRITE, so the registry will not run it without a
 *     confirmation the user gave in the panel;
 *   - authorize() checks 'update' on the conversation, i.e. the same gate the
 *     UI uses, so the tool cannot do more than the user could by hand;
 *   - confirmationLabel() says what will happen in the user's language.
 *
 * The note is written as the logged-in user. It is internal, so it never
 * reaches the customer.
 */
class AddNoteTool extends AbstractTool
{
    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return 'conversation_add_note';
    }

    /**
     * {@inheritdoc}
     */
    public function description()
    {
        return 'Add an internal note to the current conversation. Internal notes are visible to '
            .'agents only and are never sent to the customer. Use this to record findings, '
            .'next steps or context for colleagues. Do not use it to reply to the customer.';
    }

    /**
     * {@inheritdoc}
     */
    public function parameters()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'body' => [
                    'type'        => 'string',
                    'description' => 'The note, written in Markdown. Notes are internal, so headings, tables, '
                        .'inline `code` and fenced code blocks are appropriate here as well as lists, bold and '
                        .'links. Keep it short and factual. Do not include images or raw HTML; both are removed.',
                    'minLength'   => 1,
                    'maxLength'   => 20000,
                ],
            ],
            'required' => ['body'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function mode()
    {
        return Tool::MODE_WRITE;
    }

    /**
     * {@inheritdoc}
     */
    public function authorize(PanelContext $context)
    {
        return $context->userCanUpdate();
    }

    /**
     * Nothing to annotate on a mail that has not been sent: the agent is
     * looking at the whole of it, and a note filed against it would surface
     * only once the mail goes out.
     *
     * {@inheritdoc}
     */
    public function isRelevant(PanelContext $context)
    {
        return !$context->isUnsentDraft();
    }

    /**
     * {@inheritdoc}
     */
    public function confirmationLabel(array $arguments, PanelContext $context)
    {
        return __('Add an internal note to conversation #:number. The note is visible to agents only and is not sent to the customer.', [
            'number' => $context->conversation->number,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $arguments, PanelContext $context)
    {
        $body = trim((string) $arguments['body']);

        if ($body === '') {
            throw new ToolException('The note body was empty. Provide the text of the note.');
        }

        $conversation = $context->conversation;
        $customer = $conversation->customer;

        // Thread::createExtended() dereferences the customer when creating a
        // non-customer thread, so a conversation without one would fatal there.
        // Refuse cleanly instead, and let the model tell the user.
        if (!$customer) {
            throw new ToolException(
                'This conversation has no customer linked, so a note cannot be added automatically. '
                .'Ask the user to add the note by hand.'
            );
        }

        // Model output is untrusted: it is influenced by customer-written text.
        // toEditorHtml() runs it through HTMLPurifier with a profile narrower
        // than core's, so nothing executable survives and no further stripping
        // is needed here — \Helper::stripDangerousTags() on purifier output
        // would be dead code that implies the purifier is not trusted.
        $body = self::renderBody($body);

        $thread = Thread::createExtended([
            'type'                => Thread::TYPE_NOTE,
            'body'                => $body,
            'created_by_user_id'  => $context->user->id,
            'source_via'          => Thread::PERSON_USER,
            'source_type'         => Thread::SOURCE_TYPE_WEB,
        ], $conversation, $customer);

        if (!$thread) {
            throw new ToolException('The note could not be created.');
        }

        return ToolResult::ok(
            [
                'thread_id' => $thread->id,
                'added'     => true,
            ],
            __('Added an internal note to #:number', ['number' => $conversation->number])
        );
    }
}
