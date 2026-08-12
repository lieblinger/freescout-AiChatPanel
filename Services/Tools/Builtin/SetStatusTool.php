<?php

namespace Modules\AiChatPanel\Services\Tools\Builtin;

use App\Conversation;
use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\Tool;
use Modules\AiChatPanel\Services\Tools\ToolException;
use Modules\AiChatPanel\Services\Tools\ToolResult;

/**
 * Write tool: change the status of the current conversation.
 *
 * Reference implementation. Uses core's Conversation::changeStatus(), which
 * writes the line-item thread, moves the conversation between folders, updates
 * the counters and fires conversation.status_changed. Setting the column by
 * hand would skip all of that.
 */
class SetStatusTool extends AbstractTool
{
    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return 'conversation.set_status';
    }

    /**
     * {@inheritdoc}
     */
    public function description()
    {
        return 'Change the status of the current conversation. Use "closed" when the request is '
            .'resolved, "pending" when waiting on the customer, and "active" when it needs an '
            .'agent. Only do this when the agent asks for it.';
    }

    /**
     * {@inheritdoc}
     */
    public function parameters()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'status' => [
                    'type'        => 'string',
                    'description' => 'The new status.',
                    'enum'        => ['active', 'pending', 'closed', 'spam'],
                ],
            ],
            'required' => ['status'],
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
    public function confirmationLabel(array $arguments, PanelContext $context)
    {
        return __('Change the status of conversation #:number from ":from" to ":to".', [
            'number' => $context->conversation->number,
            'from'   => $this->statusName($context->conversation->status),
            'to'     => isset($arguments['status']) ? $arguments['status'] : '?',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $arguments, PanelContext $context)
    {
        $conversation = $context->conversation;

        $code = $this->statusCode(isset($arguments['status']) ? $arguments['status'] : '');

        if (!$code) {
            throw new ToolException('Unknown status. Use one of: active, pending, closed, spam.');
        }

        $previous = $this->statusName($conversation->status);

        if ((int) $conversation->status === $code) {
            return ToolResult::ok(
                ['status' => $previous, 'changed' => false],
                __('Conversation #:number was already :status', [
                    'number' => $conversation->number,
                    'status' => $previous,
                ])
            );
        }

        // Core's method does the folder moves, counters, line item and hooks.
        $conversation->changeStatus($code, $context->user);

        return ToolResult::ok(
            [
                'status'          => $this->statusName($code),
                'previous_status' => $previous,
                'changed'         => true,
            ],
            __('Changed #:number from :from to :to', [
                'number' => $conversation->number,
                'from'   => $previous,
                'to'     => $this->statusName($code),
            ])
        );
    }

    /**
     * @param string $name
     *
     * @return int|null
     */
    protected function statusCode($name)
    {
        $map = [
            'active'  => Conversation::STATUS_ACTIVE,
            'pending' => Conversation::STATUS_PENDING,
            'closed'  => Conversation::STATUS_CLOSED,
            'spam'    => Conversation::STATUS_SPAM,
        ];

        $name = strtolower(trim((string) $name));

        return isset($map[$name]) ? $map[$name] : null;
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
