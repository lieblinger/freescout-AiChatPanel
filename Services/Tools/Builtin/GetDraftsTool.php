<?php

namespace Modules\AiChatPanel\Services\Tools\Builtin;

use App\Conversation;
use App\Thread;
use Illuminate\Support\Str;
use Modules\AiChatPanel\Services\Clock;
use Modules\AiChatPanel\Services\Context\ThreadFormatter;
use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\ToolResult;

/**
 * Read tool: the unsent drafts on a conversation.
 *
 * A draft is text nobody has received. It is deliberately absent from the
 * conversation history the model is given (ContextBuilder::threads() keeps to
 * published threads), so this tool is the only way the model sees one.
 *
 * Why a tool rather than a block in the system message, which would save a round
 * trip: the system message is built once per request and then the agent loop
 * iterates against that fixed copy. A draft embedded there is stale the moment
 * the model edits it, and a second edit in the same turn would silently rewrite
 * the pre-edit text. Read here, it is always current.
 */
class GetDraftsTool extends AbstractTool
{
    /** A draft the agent will read in an editor; enough to rewrite it faithfully. */
    const MAX_BODY_CHARS = 20000;

    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return 'conversation_get_drafts';
    }

    /**
     * {@inheritdoc}
     */
    public function description()
    {
        return 'Read the unsent drafts on a conversation, with the thread id of each. '
            .'A draft has NOT been sent and the customer has not seen it. Call this before '
            .'changing a draft with conversation_update_draft, and whenever the agent refers '
            .'to "the draft" — drafts are not part of the conversation history you were given, '
            .'and a draft you saw earlier in this chat may since have changed. '
            .'Returns an empty list when there are none.';
    }

    /**
     * The only draft on a mail being composed is that mail, which the model
     * already has as the editor contents. Offering this tool there would have
     * it read back a stale copy of what the agent is typing.
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
    public function parameters()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'number' => [
                    'type'        => 'integer',
                    'description' => 'Conversation number, as shown in the interface (without the # sign). Omit for the conversation that is currently open.',
                    'minimum'     => 1,
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $arguments, PanelContext $context)
    {
        $conversation = $context->conversation;

        // A number is only worth resolving when it is not the open conversation:
        // conversations are found by display number, which is not always the
        // `number` column (see GetConversationTool).
        if (!empty($arguments['number']) && (int) $arguments['number'] !== (int) $conversation->number) {
            $conversation = $this->findConversation((int) $arguments['number'], $context);

            if (!$conversation) {
                return ToolResult::error(
                    'Conversation #'.(int) $arguments['number'].' was not found, or you do not have access to it.'
                );
            }
        }

        $threads = $conversation->threads()
            ->where('state', Thread::STATE_DRAFT)
            ->orderBy('id', 'asc')
            ->get();

        $drafts = [];

        foreach ($threads as $thread) {
            $drafts[] = [
                'thread_id' => $thread->id,
                'kind'      => ThreadFormatter::kind($thread),
                'author'    => ThreadFormatter::author($thread),
                'date'      => $thread->created_at ? Clock::dateTime($thread->created_at, $context->user) : null,
                'body'      => Str::limit(ThreadFormatter::draftBody($thread), self::MAX_BODY_CHARS),
            ];
        }

        // No drafts is a normal answer, not a failure: the model should read it
        // and say so, or offer to write one.
        return ToolResult::ok([
            'number' => (int) $conversation->number,
            'drafts' => $drafts,
            'count'  => count($drafts),
        ], trans_choice(
            '{0} No drafts on #:number|{1} Read 1 draft on #:number|[2,*] Read :count drafts on #:number',
            count($drafts),
            ['count' => count($drafts), 'number' => $conversation->number]
        ));
    }

    /**
     * Another conversation, by the number the interface shows.
     *
     * Same rule as conversation_get: one the user may not view is reported as
     * not found rather than forbidden, because the difference is itself
     * information.
     *
     * @param int          $number
     * @param PanelContext $context
     *
     * @return Conversation|null
     */
    protected function findConversation($number, PanelContext $context)
    {
        $mailbox_ids = $context->user->mailboxesIdsCanView();

        if (!$mailbox_ids) {
            return null;
        }

        $conversation = Conversation::where(Conversation::numberFieldName(), $number)
            ->whereIn('mailbox_id', $mailbox_ids)
            ->first();

        if (!$conversation || !$context->canViewConversation($conversation)) {
            return null;
        }

        return $conversation;
    }
}
