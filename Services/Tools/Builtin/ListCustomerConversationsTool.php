<?php

namespace Modules\AiChatPanel\Services\Tools\Builtin;

use App\Conversation;
use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\ToolResult;

/**
 * Read tool: the customer's other conversations.
 *
 * Reference implementation — read docs/extending.md alongside this file.
 *
 * The authorisation pattern here is the important part: the query is scoped to
 * the mailboxes the user can view, and then every row is still put through the
 * conversation policy, because "can view the mailbox" and "can view this
 * conversation" are not the same thing once a user has the
 * only-assigned-tickets permission.
 */
class ListCustomerConversationsTool extends AbstractTool
{
    const MAX_LIMIT = 25;

    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return 'conversation.list_customer_conversations';
    }

    /**
     * {@inheritdoc}
     */
    public function description()
    {
        return 'List other conversations belonging to the customer of the current conversation. '
            .'Use this to find out whether the customer has contacted support before, or to locate '
            .'a previous ticket the customer refers to. Returns conversation numbers, subjects, '
            .'statuses and dates, but not message bodies — call conversation.get for those.';
    }

    /**
     * {@inheritdoc}
     */
    public function parameters()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'How many conversations to return, newest first. Defaults to 10.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                ],
                'status' => [
                    'type'        => 'string',
                    'description' => 'Only return conversations with this status.',
                    'enum'        => ['active', 'pending', 'closed', 'spam'],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function isRelevant(PanelContext $context)
    {
        // Nothing to list without a customer; do not waste tokens offering it.
        return (bool) $context->customer;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $arguments, PanelContext $context)
    {
        if (!$context->customer) {
            return ToolResult::error('This conversation has no customer linked, so there are no other conversations to list.');
        }

        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 10;
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $mailbox_ids = $context->user->mailboxesIdsCanView();

        if (!$mailbox_ids) {
            return ToolResult::ok([], 'No accessible mailboxes');
        }

        $query = Conversation::where('customer_id', $context->customer->id)
            ->where('id', '<>', $context->conversation->id)
            ->whereIn('mailbox_id', $mailbox_ids)
            ->where('state', '<>', Conversation::STATE_DRAFT);

        if (!empty($arguments['status'])) {
            $query->where('status', $this->statusCode($arguments['status']));
        }

        // Over-fetch: the policy below can still reject rows.
        $candidates = $query->orderBy('created_at', 'desc')->limit($limit * 3)->get();

        $rows = [];

        foreach ($candidates as $conversation) {
            if (count($rows) >= $limit) {
                break;
            }

            if (!$context->canViewConversation($conversation)) {
                continue;
            }

            $rows[] = [
                'number'     => (int) $conversation->number,
                'subject'    => (string) $conversation->subject,
                'status'     => $this->statusName($conversation->status),
                'created_at' => $conversation->created_at ? $conversation->created_at->toDateString() : null,
                'updated_at' => $conversation->updated_at ? $conversation->updated_at->toDateString() : null,
                'mailbox'    => $conversation->mailbox ? $conversation->mailbox->name : null,
            ];
        }

        return ToolResult::ok(
            ['conversations' => $rows],
            trans_choice('{0} No other conversations|{1} 1 other conversation|[2,*] :count other conversations', count($rows), ['count' => count($rows)])
        );
    }

    /**
     * @param string $name
     *
     * @return int
     */
    protected function statusCode($name)
    {
        $map = [
            'active'  => Conversation::STATUS_ACTIVE,
            'pending' => Conversation::STATUS_PENDING,
            'closed'  => Conversation::STATUS_CLOSED,
            'spam'    => Conversation::STATUS_SPAM,
        ];

        return isset($map[$name]) ? $map[$name] : Conversation::STATUS_ACTIVE;
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
