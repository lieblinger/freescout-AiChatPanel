<?php

namespace Modules\AiChatPanel\Services\Context\Providers;

use App\Conversation;
use Modules\AiChatPanel\Services\Clock;
use Modules\AiChatPanel\Services\Context\ContextProvider;
use Modules\AiChatPanel\Services\PanelContext;

/**
 * Built-in reference implementation of the ContextProvider interface.
 *
 * Lists the customer's other conversations so the model knows whether this is a
 * first contact or the fifth follow-up. Kept small on purpose: subject, number,
 * status and date only, because the point of a provider is to be cheap enough
 * to pay for on every message.
 *
 * Read docs/extending.md before copying this — it is deliberately written to be
 * the example.
 */
class PreviousConversationsProvider implements ContextProvider
{
    const KEY = 'aichatpanel.previous_conversations';

    /** How many to list. Beyond this the block stops being cheap. */
    const LIMIT = 10;

    /**
     * {@inheritdoc}
     */
    public function key()
    {
        return self::KEY;
    }

    /**
     * {@inheritdoc}
     */
    public function label()
    {
        return __('Previous conversations of this customer');
    }

    /**
     * {@inheritdoc}
     */
    public function priority()
    {
        return 20;
    }

    /**
     * {@inheritdoc}
     */
    public function estimatedTokens(PanelContext $context)
    {
        if (!$context->customer) {
            return 0;
        }

        // ~25 tokens a line plus a heading; over-estimating is the safe way to
        // be wrong.
        return 30 + (self::LIMIT * 25);
    }

    /**
     * {@inheritdoc}
     */
    public function render(PanelContext $context)
    {
        if (!$context->customer) {
            return null;
        }

        $conversations = $this->conversations($context);

        if (!count($conversations)) {
            return null;
        }

        $lines = ['Other conversations from this customer, newest first:'];

        foreach ($conversations as $conversation) {
            $lines[] = sprintf(
                '- #%s (%s, %s): %s',
                $conversation->number,
                $this->statusName($conversation),
                $conversation->created_at ? Clock::date($conversation->created_at, $context->user) : 'unknown date',
                (string) $conversation->subject
            );
        }

        return implode("\n", $lines);
    }

    /**
     * The customer's other conversations that this user is allowed to see.
     *
     * Authorisation is not optional here: a customer can have conversations in
     * mailboxes this agent has no access to, and putting those in the prompt
     * would leak them.
     *
     * @param PanelContext $context
     *
     * @return \Illuminate\Support\Collection
     */
    protected function conversations(PanelContext $context)
    {
        $mailbox_ids = $context->user->mailboxesIdsCanView();

        if (!$mailbox_ids) {
            return collect();
        }

        $candidates = Conversation::where('customer_id', $context->customer->id)
            ->where('id', '<>', $context->conversation->id)
            ->whereIn('mailbox_id', $mailbox_ids)
            ->where('state', '<>', Conversation::STATE_DRAFT)
            ->orderBy('created_at', 'desc')
            // Over-fetch a little: the policy can still reject some of these
            // when the user may only see assigned conversations.
            ->limit(self::LIMIT * 3)
            ->get();

        return $candidates
            ->filter(function ($conversation) use ($context) {
                return $context->canViewConversation($conversation);
            })
            ->take(self::LIMIT);
    }

    /**
     * @param Conversation $conversation
     *
     * @return string
     */
    protected function statusName(Conversation $conversation)
    {
        $names = [
            Conversation::STATUS_ACTIVE  => 'active',
            Conversation::STATUS_PENDING => 'pending',
            Conversation::STATUS_CLOSED  => 'closed',
            Conversation::STATUS_SPAM    => 'spam',
        ];

        return isset($names[$conversation->status]) ? $names[$conversation->status] : 'unknown';
    }
}
