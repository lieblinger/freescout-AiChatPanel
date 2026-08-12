<?php

namespace Modules\AiChatPanel\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit record of one tool execution.
 *
 * Written for every attempt, including the ones that never ran — a rejected
 * write and a permission-denied call are exactly the events an auditor wants
 * to see.
 */
class ToolCall extends Model
{
    const MODE_READ  = 1;
    const MODE_WRITE = 2;

    /** Awaiting the user's confirmation. */
    const STATUS_PENDING  = 1;
    /** Ran successfully. */
    const STATUS_OK       = 2;
    /** Ran and threw. */
    const STATUS_FAILED   = 3;
    /** The user pressed Reject. */
    const STATUS_REJECTED = 4;
    /** Blocked before running: permission, disabled tool, or bad arguments. */
    const STATUS_DENIED   = 5;

    protected $table = 'aichatpanel_tool_calls';

    protected $fillable = [
        'user_id',
        'conversation_id',
        'mailbox_id',
        'chat_id',
        'tool',
        'mode',
        'status',
        'arguments',
        'result',
        'error',
        'duration_ms',
    ];

    protected $casts = [
        'arguments' => 'array',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo('App\User');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function conversation()
    {
        return $this->belongsTo('App\Conversation');
    }

    /**
     * @return array
     */
    public static function statusNames()
    {
        return [
            self::STATUS_PENDING  => __('Awaiting confirmation'),
            self::STATUS_OK       => __('Executed'),
            self::STATUS_FAILED   => __('Failed'),
            self::STATUS_REJECTED => __('Rejected by user'),
            self::STATUS_DENIED   => __('Blocked'),
        ];
    }

    /**
     * @return string
     */
    public function getStatusName()
    {
        $names = self::statusNames();

        return isset($names[$this->status]) ? $names[$this->status] : (string) $this->status;
    }

    /**
     * Record an attempt.
     *
     * Never throws: an audit failure must not take down the chat, but it must
     * be loud in the log.
     *
     * @param array $attributes
     *
     * @return static|null
     */
    public static function record(array $attributes)
    {
        try {
            return self::create($attributes);
        } catch (\Exception $e) {
            \Helper::logException($e, '[AiChatPanel] Could not write the tool audit record: ');

            return null;
        }
    }
}
