<?php

namespace Modules\AiChatPanel\Services\Tools\Builtin;

use App\Folder;
use App\Thread;
use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\Tool;
use Modules\AiChatPanel\Services\Tools\ToolException;
use Modules\AiChatPanel\Services\Tools\ToolResult;

/**
 * Write tool: save a draft reply on the current conversation.
 *
 * The most sensitive tool in the module, and the one whose limits are worth
 * reading carefully:
 *
 *   - it creates a thread in STATE_DRAFT and nothing else. No mail is sent, no
 *     queue job is pushed, the conversation status is untouched. The agent
 *     still opens the draft, reads it and presses Send.
 *   - it can never be added to the admin's auto-run allowlist. ToolRegistry
 *     hard-codes it in neverAutoRun(), because a customer-facing message is the
 *     one place where an unattended mistake reaches a customer.
 *   - it refuses to create a second draft, so a model in a loop cannot bury the
 *     agent's own work under a pile of drafts. Changing a draft that already
 *     exists is conversation.update_draft's job, not a second create.
 */
class CreateDraftReplyTool extends AbstractTool
{
    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return 'conversation.create_draft_reply';
    }

    /**
     * {@inheritdoc}
     */
    public function description()
    {
        return 'Save a NEW draft reply to the customer on the current conversation. The draft is '
            .'NOT sent: a human reviews it and sends it themselves. Use this only when the agent '
            .'explicitly asks you to prepare a reply in the conversation. If they just want to '
            .'see suggested wording, write it in the chat instead. If the conversation already '
            .'has a draft, do not call this — change that one with conversation.update_draft.';
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
                    'description' => 'The reply text addressed to the customer. Plain text; line breaks are preserved. Do not include a signature, it is added automatically.',
                    'minLength'   => 1,
                    'maxLength'   => 50000,
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
     * {@inheritdoc}
     */
    public function isRelevant(PanelContext $context)
    {
        // Without a customer there is nobody to address the draft to.
        return (bool) $context->customer;
    }

    /**
     * {@inheritdoc}
     */
    public function confirmationLabel(array $arguments, PanelContext $context)
    {
        $customer = $context->customer;

        return __('Save a draft reply to :customer on conversation #:number. Nothing is sent — you review the draft and send it yourself.', [
            'customer' => $customer ? $customer->getFullName(true) : __('the customer'),
            'number'   => $context->conversation->number,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $arguments, PanelContext $context)
    {
        $body = trim((string) $arguments['body']);

        if ($body === '') {
            throw new ToolException('The reply body was empty. Provide the text of the draft.');
        }

        $conversation = $context->conversation;
        $customer = $conversation->customer;

        if (!$customer) {
            throw new ToolException('This conversation has no customer linked, so a reply cannot be drafted.');
        }

        // One draft at a time. A model that keeps calling this must not be able
        // to bury the agent's own draft.
        $existing = $conversation->threads()
            ->where('state', Thread::STATE_DRAFT)
            ->orderBy('id', 'desc')
            ->first();

        if ($existing) {
            throw new ToolException(
                'This conversation already has a draft (thread '.$existing->id.'). Do not create a '
                .'second one. To change what it says, call conversation.update_draft with '
                .'thread_id '.$existing->id.' and the full new text; read it first with '
                .'conversation.get_drafts if you have not already.'
            );
        }

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type = Thread::TYPE_MESSAGE;
        $thread->state = Thread::STATE_DRAFT;
        $thread->status = $conversation->status;
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id = $customer->id;
        $thread->created_by_user_id = $context->user->id;
        $thread->user_id = $conversation->user_id;
        // Model output is untrusted; escape it before it is stored and later
        // rendered into the reply editor.
        $thread->body = \Helper::stripDangerousTags(nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')));
        $thread->setTo([$customer->getMainEmail()]);
        $thread->save();

        $conversation->addToFolder(Folder::TYPE_DRAFTS);
        $conversation->mailbox->updateFoldersCounters(Folder::TYPE_DRAFTS);

        return ToolResult::ok(
            [
                'thread_id' => $thread->id,
                'saved'     => true,
                'sent'      => false,
            ],
            __('Saved a draft reply on #:number (not sent)', ['number' => $conversation->number])
        );
    }
}
