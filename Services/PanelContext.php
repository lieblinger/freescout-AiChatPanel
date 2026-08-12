<?php

namespace Modules\AiChatPanel\Services;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\User;

/**
 * Everything a tool, a context provider or the prompt builder is allowed to
 * know about the situation it is running in.
 *
 * This object is the contract with third-party modules: it is passed to every
 * aichatpanel.* filter and to every tool handler. Adding fields to it is a
 * compatible change; removing or renaming one is not.
 *
 * It carries the acting user on purpose. Tools run *as that user* and must
 * authorise against them — there is no service account anywhere in this module.
 */
class PanelContext
{
    /** @var Conversation */
    public $conversation;

    /** @var Mailbox */
    public $mailbox;

    /** @var Customer|null Conversations do not always have a customer. */
    public $customer;

    /** @var User The logged-in user. Never a robot, never elevated. */
    public $user;

    /**
     * @param Conversation $conversation
     * @param User         $user
     */
    public function __construct(Conversation $conversation, User $user)
    {
        $this->conversation = $conversation;
        $this->user = $user;
        $this->mailbox = $conversation->mailbox;
        $this->customer = $conversation->customer;
    }

    /**
     * Whether the acting user may see this conversation at all.
     *
     * Delegates to core's ConversationPolicy rather than reimplementing the
     * mailbox and only-assigned-tickets rules.
     *
     * @return bool
     */
    public function userCanView()
    {
        return $this->user->can('view', $this->conversation);
    }

    /**
     * Whether the acting user may change this conversation. Every write tool
     * checks this before anything else.
     *
     * @return bool
     */
    public function userCanUpdate()
    {
        return $this->user->can('update', $this->conversation);
    }

    /**
     * A setting, with this mailbox's override applied.
     *
     * @param string $name
     *
     * @return mixed
     */
    public function setting($name)
    {
        return Settings::get($name, $this->mailbox);
    }

    /**
     * Convenience for tools that need to answer "may this user see that other
     * conversation?" before putting it in front of the model.
     *
     * @param Conversation $conversation
     *
     * @return bool
     */
    public function canViewConversation(Conversation $conversation)
    {
        return $this->user->can('view', $conversation);
    }
}
