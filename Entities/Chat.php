<?php

namespace Modules\AiChatPanel\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * A chat session: one conversation, one user.
 *
 * Chats are private to the user who created them. Two agents looking at the
 * same conversation each get their own, which is why the unique key is the pair.
 */
class Chat extends Model
{
    protected $table = 'aichatpanel_chats';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'model',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function messages()
    {
        return $this->hasMany(Message::class, 'chat_id')->orderBy('id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function conversation()
    {
        return $this->belongsTo('App\Conversation');
    }

    /**
     * The current chat for this conversation and user, created if needed.
     *
     * @param int $conversation_id
     * @param int $user_id
     *
     * @return static
     */
    public static function findOrCreateFor($conversation_id, $user_id)
    {
        $chat = self::where('conversation_id', $conversation_id)
            ->where('user_id', $user_id)
            ->first();

        if ($chat) {
            return $chat;
        }

        return self::create([
            'conversation_id' => $conversation_id,
            'user_id'         => $user_id,
        ]);
    }

    /**
     * Drop every message but keep the chat row, so the model preference and the
     * conversation link survive a "new chat".
     *
     * @return void
     */
    public function reset()
    {
        $this->messages()->delete();
        $this->touch();
    }

    /**
     * Messages in the shape the endpoint expects, oldest first.
     *
     * The system message is not stored — it is rebuilt on every request so a
     * changed system prompt or changed mailbox settings take effect at once.
     *
     * @return array
     */
    public function toApiMessages()
    {
        $messages = [];

        foreach ($this->messages as $message) {
            $api = $message->toApiMessage();

            if ($api) {
                $messages[] = $api;
            }
        }

        return $messages;
    }

    /**
     * Delete the chats belonging to the given conversations, and their messages.
     *
     * Used by both conversation-deletion hooks and by the retention command.
     *
     * @param array $conversation_ids
     *
     * @return void
     */
    public static function deleteForConversations($conversation_ids)
    {
        $conversation_ids = array_values(array_filter((array) $conversation_ids));

        if (!$conversation_ids) {
            return;
        }

        foreach (array_chunk($conversation_ids, \Helper::IN_LIMIT) as $chunk) {
            $chat_ids = self::whereIn('conversation_id', $chunk)->pluck('id')->toArray();

            if ($chat_ids) {
                Message::whereIn('chat_id', $chat_ids)->delete();
                self::whereIn('id', $chat_ids)->delete();
            }
        }
    }
}
