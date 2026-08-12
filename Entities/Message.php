<?php

namespace Modules\AiChatPanel\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * One turn of a chat.
 *
 * Roles are stored as small integers rather than an enum, per FreeScout's
 * migration conventions.
 */
class Message extends Model
{
    const ROLE_USER      = 1;
    const ROLE_ASSISTANT = 2;
    const ROLE_TOOL      = 3;

    const STATUS_OK      = 0;
    const STATUS_ERROR   = 1;
    // An assistant turn asking for a write tool that is waiting on the user.
    const STATUS_PENDING = 2;

    protected $table = 'aichatpanel_messages';

    protected $fillable = [
        'chat_id',
        'role',
        'body',
        'body_html',
        'reasoning',
        'tool_calls',
        'tool_call_id',
        'tool_name',
        'status',
        'meta',
    ];

    protected $casts = [
        'tool_calls' => 'array',
        'meta'       => 'array',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function chat()
    {
        return $this->belongsTo(Chat::class, 'chat_id');
    }

    /**
     * Shape this turn the way /v1/chat/completions expects it.
     *
     * Returns null for turns that must not be replayed — an error bubble, or an
     * assistant turn still waiting on a write confirmation.
     *
     * @return array|null
     */
    public function toApiMessage()
    {
        if ($this->status == self::STATUS_ERROR) {
            return null;
        }

        switch ($this->role) {
            case self::ROLE_USER:
                return [
                    'role'    => 'user',
                    'content' => (string) $this->body,
                ];

            case self::ROLE_TOOL:
                return [
                    'role'         => 'tool',
                    'tool_call_id' => (string) $this->tool_call_id,
                    'content'      => (string) $this->body,
                ];

            case self::ROLE_ASSISTANT:
                $message = [
                    'role' => 'assistant',
                    // Never replay reasoning: it is display-only, and feeding a
                    // model its own chain of thought back is wasteful and
                    // confusing.
                    'content' => (string) $this->body,
                ];

                if ($this->tool_calls) {
                    $message['tool_calls'] = [];

                    foreach ($this->tool_calls as $call) {
                        $message['tool_calls'][] = [
                            'id'       => $call['id'],
                            'type'     => 'function',
                            'function' => [
                                'name'      => $call['name'],
                                'arguments' => $call['arguments'],
                            ],
                        ];
                    }
                }

                return $message;
        }

        return null;
    }

    /**
     * The shape the panel renders.
     *
     * body_html is the server-rendered, purified version; the client uses it
     * verbatim and never re-renders stored history itself.
     *
     * @return array
     */
    public function toPanelArray()
    {
        $roles = [
            self::ROLE_USER      => 'user',
            self::ROLE_ASSISTANT => 'assistant',
            self::ROLE_TOOL      => 'tool',
        ];

        return [
            'id'         => $this->id,
            'role'       => isset($roles[$this->role]) ? $roles[$this->role] : 'assistant',
            'body'       => (string) $this->body,
            'html'       => (string) $this->body_html,
            'reasoning'  => (string) $this->reasoning,
            'tool_calls' => $this->tool_calls ?: [],
            'tool_name'  => (string) $this->tool_name,
            'status'     => (int) $this->status,
            'meta'       => $this->meta ?: [],
            'created_at' => $this->created_at ? $this->created_at->toIso8601String() : null,
        ];
    }
}
