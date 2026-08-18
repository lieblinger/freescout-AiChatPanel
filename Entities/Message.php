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
     * Returns null for turns that must not be replayed: an error bubble — the
     * row written when the endpoint itself failed, which the model never
     * produced and would only be confused by.
     *
     * A failed TOOL result is not one of those and is always replayed. Its
     * STATUS_ERROR is what paints the activity row red in the panel; the model
     * still has to be told that the tool it asked for did not work, and the
     * endpoint requires every tool_call id in the assistant turn above to be
     * answered before the next completion. Dropping it left an unanswered
     * tool_call in the history and every later message in that chat came back
     * as a 400.
     *
     * STATUS_PENDING is replayed too, and has to be: confirm() resumes the run
     * by sending exactly that turn with the result of the write beneath it.
     *
     * @return array|null
     */
    public function toApiMessage()
    {
        if ($this->status == self::STATUS_ERROR && $this->role != self::ROLE_TOOL) {
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
     * The clock fields are formatted here rather than in JavaScript so they go
     * through User::dateFormat like every other date in FreeScout: the viewer's
     * timezone, their 12/24-hour preference and localised month and weekday
     * names all come from there. Only the Today/Yesterday wording is left to
     * the browser, because a server-computed "today" goes stale in a panel that
     * stays open past midnight.
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

        $created = $this->created_at;

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
            'created_at' => $created ? $created->toIso8601String() : null,
            // copy(): dateFormat() calls setTimezone() on the instance it is
            // given, which would otherwise reach through to created_at above.
            'time'       => $created ? \App\User::dateFormat($created->copy(), 'H:i') : '',
            'date_key'   => $created ? $this->localDateKey($created) : '',
            'date_label' => $created ? \App\User::dateFormat($created->copy(), 'l, M j, Y') : '',
        ];
    }

    /**
     * The calendar day this turn belongs to, in the viewer's timezone.
     *
     * Deliberately not User::dateFormat: that ends in formatLocalized(), so
     * under a locale whose ICU data uses non-Latin digits the result would stop
     * comparing equal to the YYYY-MM-DD the browser builds. This value is a
     * grouping key, not something anyone reads.
     *
     * @param \Carbon\Carbon $created
     *
     * @return string
     */
    protected function localDateKey($created)
    {
        $date = $created->copy();
        $user = auth()->user();

        if ($user && $user->timezone) {
            $date->setTimezone($user->timezone);
        }

        return $date->format('Y-m-d');
    }
}
