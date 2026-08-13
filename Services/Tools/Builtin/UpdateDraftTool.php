<?php

namespace Modules\AiChatPanel\Services\Tools\Builtin;

use App\Thread;
use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\Tool;
use Modules\AiChatPanel\Services\Tools\ToolException;
use Modules\AiChatPanel\Services\Tools\ToolResult;

/**
 * Write tool: replace the text of an existing draft.
 *
 * This is what makes "make it shorter" work. Without it the model can only
 * create, and CreateDraftReplyTool refuses a second draft, so a revision request
 * has nowhere to go.
 *
 * The boundary is drafts, and only drafts. A published thread — a reply the
 * customer has read, a note colleagues have seen — is never a valid target, and
 * the state is re-checked at execution time rather than trusted from when the
 * prompt was built: the agent may have pressed Send in another tab in between.
 * Core guards the same race in its own draft editor
 * (ConversationsController.php:1455).
 *
 * Like CreateDraftReplyTool it can never be added to the auto-run allowlist. It
 * rewrites customer-facing text, which is the same class of risk as writing it.
 */
class UpdateDraftTool extends AbstractTool
{
    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return 'conversation.update_draft';
    }

    /**
     * {@inheritdoc}
     */
    public function description()
    {
        return 'Replace the text of an existing draft on the current conversation. Use this '
            .'whenever the agent asks for a change to a draft — shorter, more formal, add a '
            .'sentence — instead of creating a new one. Read the draft with '
            .'conversation.get_drafts first and send the complete new text: this replaces the '
            .'body, it does not append to it. The draft is still NOT sent; a human reviews it '
            .'and sends it themselves.';
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
                    'description' => 'The complete new text of the draft, in Markdown, replacing what is there now. '
                        .'The same formatting is available as in conversation.create_draft_reply, and what you were '
                        .'shown by conversation.get_drafts is Markdown too — keep the formatting you were given unless '
                        .'you were asked to change it. Do not include a signature, it is added automatically.',
                    'minLength'   => 1,
                    'maxLength'   => 50000,
                ],
                'thread_id' => [
                    'type'        => 'integer',
                    'description' => 'Which draft to replace, from conversation.get_drafts. Optional when the conversation has exactly one draft.',
                    'minimum'     => 1,
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
     * Nothing to update when there is no draft. Keeping it out of the payload
     * also stops the model reaching for it instead of create_draft_reply.
     *
     * {@inheritdoc}
     */
    public function isRelevant(PanelContext $context)
    {
        return $context->conversation->threads()
            ->where('state', Thread::STATE_DRAFT)
            ->exists();
    }

    /**
     * {@inheritdoc}
     */
    public function confirmationLabel(array $arguments, PanelContext $context)
    {
        $thread = null;

        try {
            $thread = $this->resolve($arguments, $context);
        } catch (ToolException $e) {
            // Ambiguous or already sent. The dialog still has to say something
            // sensible; execution will produce the real error.
        }

        if ($thread && $thread->type == Thread::TYPE_NOTE) {
            return __('Replace the text of the draft note on conversation #:number. It stays a draft and is visible to agents only.', [
                'number' => $context->conversation->number,
            ]);
        }

        $customer = $context->customer;

        return __('Replace the text of the draft reply to :customer on conversation #:number. Nothing is sent — you review the draft and send it yourself.', [
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
            throw new ToolException('The new draft body was empty. Provide the full text the draft should now contain.');
        }

        $thread = $this->resolve($arguments, $context);

        // Model output is untrusted — it is influenced by customer-written text.
        // renderBody() converts the Markdown and sanitises it in one step,
        // exactly as conversation.create_draft_reply does. It matters twice
        // over here: the model read this draft as Markdown, so writing it back
        // as escaped text would strip the formatting on every edit.
        $thread->body = self::renderBody($body);

        // Core stamps the editor only when it is not the author, and reads it
        // back to render "X edited Y's draft" (app/Thread.php:721). Following
        // the same rule makes the conversation view tell the truth for free.
        if ($thread->created_by_user_id != $context->user->id) {
            $thread->edited_by_user_id = $context->user->id;
            $thread->edited_at = date('Y-m-d H:i:s');
        }

        $thread->save();

        // No folder work: the conversation is already in Drafts, because this
        // draft was already there before it was edited.

        return ToolResult::ok(
            [
                'thread_id' => $thread->id,
                'updated'   => true,
                'sent'      => false,
            ],
            __('Updated the draft on #:number (not sent)', ['number' => $context->conversation->number])
        );
    }

    /**
     * Which draft the model means.
     *
     * Scoped to the open conversation's threads, so a thread id belonging to
     * another conversation cannot be reached through this tool whatever the
     * model asks for.
     *
     * @param array        $arguments
     * @param PanelContext $context
     *
     * @return Thread
     *
     * @throws ToolException
     */
    protected function resolve(array $arguments, PanelContext $context)
    {
        $drafts = $context->conversation->threads()
            ->where('state', Thread::STATE_DRAFT)
            ->orderBy('id', 'asc')
            ->get();

        if (!count($drafts)) {
            throw new ToolException(
                'This conversation has no draft to update. Use conversation.create_draft_reply '
                .'to write one, or tell the user there is nothing to change.'
            );
        }

        if (!empty($arguments['thread_id'])) {
            $thread_id = (int) $arguments['thread_id'];

            $thread = $drafts->first(function ($draft) use ($thread_id) {
                return (int) $draft->id === $thread_id;
            });

            if (!$thread) {
                // Covers three cases on purpose — wrong conversation, already
                // sent, never existed — because telling them apart would report
                // on threads the user may not be looking at.
                throw new ToolException(
                    'Thread '.$thread_id.' is not a draft on this conversation. It may have been sent '
                    .'or discarded. Call conversation.get_drafts to see what is there now.'
                );
            }

            return $thread;
        }

        if (count($drafts) > 1) {
            throw new ToolException(
                'This conversation has '.count($drafts).' drafts, so it is not clear which one to '
                .'change. Call conversation.get_drafts and pass the thread_id of the one you mean. '
                .'Thread ids: '.$drafts->pluck('id')->implode(', ').'.'
            );
        }

        return $drafts->first();
    }
}
