<?php

namespace Modules\AiChatPanel\Services;

use App\Conversation;
use App\Thread;

/**
 * What an AI turn changed in the conversation, and the broadcast that tells
 * every open browser about it.
 *
 * FreeScout has exactly one mechanism for "the conversation view changed,
 * update the browser": polycast plus App\Events\RealtimeConvNewThread. This
 * module does not build a second one. It dispatches core's own event and lets
 * core render the thread, authorise the recipient, insert the HTML and refresh
 * the status and assignee widgets.
 *
 * Core deliberately refuses to broadcast drafts:
 * RealtimeConvNewThread::dispatchSelf() returns early for any thread that is
 * not published (core/app/Events/RealtimeConvNewThread.php:75). That guard is
 * right — every user reply starts life as a draft written by the reply
 * editor's autosave, so broadcasting drafts in general would push half-typed
 * text to colleagues on every keystroke batch. An assistant draft is the
 * exception: it is written once, complete, and only while this collector is
 * armed. Hence the direct event() rather than dispatchSelf().
 *
 * The collector is armed only inside AiChatPanel requests, so an ordinary
 * autosave still broadcasts nothing.
 *
 * It records ids, never HTML. Rendering happens later, in the receiving user's
 * poll request, inside RealtimeConvNewThread::processPayload().
 */
class ChangeCollector
{
    /**
     * The conversation this turn is allowed to touch. Null means disarmed, and
     * a disarmed collector records and broadcasts nothing.
     *
     * @var int|null
     */
    protected $conversation_id = null;

    /**
     * Every thread id recorded this request, oldest first.
     *
     * @var array
     */
    protected $thread_ids = [];

    /**
     * Ids not yet sent to the browser. flush() drains this; snapshot() ignores
     * it.
     *
     * @var array
     */
    protected $unflushed = [];

    /**
     * Ids of threads that already existed and were edited, rather than created.
     *
     * The browser has to treat these differently: core's handler inserts a
     * thread only when it is not already in the DOM (main.js:3821), and there
     * is no core path that replaces one. The module does that itself.
     *
     * @var array
     */
    protected $updated_thread_ids = [];

    /**
     * The draft the assistant wrote, if it wrote one. The panel offers to open
     * it in the reply editor, so it needs to be told apart from the other
     * threads.
     *
     * @var int|null
     */
    protected $draft_thread_id = null;

    /** @var int|null Set only when conversation.status_changed fired. */
    protected $status = null;

    /** @var int|null Set only when conversation.user_changed fired. */
    protected $user_id = null;

    /**
     * Whether the status or assignee changed since the last flush. Kept apart
     * from the values themselves, which stay set for snapshot() to report.
     *
     * @var bool
     */
    protected $conversation_unflushed = false;

    /**
     * Server time immediately before the first broadcast of this turn.
     *
     * The browser hands this to polycast as the poll's time cursor. Without it
     * the poll uses the cursor from the previous poll, and polycast defers the
     * handler by the event's age relative to that cursor
     * (core/public/js/polycast/polycast.js:361) — so a change would sit unshown
     * for up to the full five-second poll interval even though the poke made
     * the request immediately.
     *
     * @var string|null
     */
    protected $since = null;

    /**
     * The request-scoped instance.
     *
     * Resolved from the container rather than passed around: the hooks that
     * feed it fire deep inside core's model layer, and the places that read it
     * (AgentLoop, ChatController) would otherwise have to thread it through
     * constructors that third-party tools already depend on.
     *
     * @return self
     */
    public static function instance()
    {
        return app(self::class);
    }

    /**
     * Start recording for one conversation.
     *
     * Idempotent: re-arming the same conversation keeps whatever has already
     * been recorded, so ChatController can call this on every request without
     * caring whether something else armed it first.
     *
     * @param int $conversation_id
     *
     * @return self
     */
    public function arm($conversation_id)
    {
        $conversation_id = (int) $conversation_id;

        if ($this->conversation_id !== $conversation_id) {
            $this->conversation_id = $conversation_id;
            $this->thread_ids = [];
            $this->unflushed = [];
            $this->draft_thread_id = null;
            $this->status = null;
            $this->user_id = null;
            $this->updated_thread_ids = [];
            $this->conversation_unflushed = false;
            $this->since = null;
        }

        return $this;
    }

    /**
     * @return bool
     */
    public function armed()
    {
        return $this->conversation_id !== null;
    }

    /**
     * Record a thread and broadcast it.
     *
     * Called from the thread.created hook, which fires for drafts and line
     * items as well as ordinary messages — that is exactly why it is the hook
     * we want. SetStatusTool calls Conversation::changeStatus(), and core
     * creates the line-item thread inside that method
     * (core/app/Conversation.php:1886), so the tool never sees its id.
     *
     * @param Thread|int $thread
     * @param bool       $updated Whether the thread already existed and was
     *                            edited. Threads created and then edited in the
     *                            same turn count as created: the browser has
     *                            not seen either version.
     *
     * @return void
     */
    public function noteThread($thread, $updated = false)
    {
        if (!$this->armed()) {
            return;
        }

        if (!$thread instanceof Thread) {
            $thread = Thread::find((int) $thread);
        }

        if (!$thread || (int) $thread->conversation_id !== $this->conversation_id) {
            return;
        }

        if (in_array($thread->id, $this->thread_ids)) {
            return;
        }

        $this->thread_ids[] = $thread->id;
        $this->unflushed[] = $thread->id;

        if ($updated) {
            $this->updated_thread_ids[] = $thread->id;
        }

        if ($thread->type == Thread::TYPE_MESSAGE && $thread->state == Thread::STATE_DRAFT) {
            $this->draft_thread_id = $thread->id;
        }

        $this->broadcast($thread);
    }

    /**
     * Record the conversation's status and assignee after a change.
     *
     * The values only travel to the browser as a hint that something moved;
     * the authoritative ones are read fresh in
     * RealtimeConvNewThread::processPayload().
     *
     * @param Conversation $conversation
     *
     * @return void
     */
    public function noteConversation($conversation)
    {
        if (!$this->armed() || !$conversation || (int) $conversation->id !== $this->conversation_id) {
            return;
        }

        $this->status = (int) $conversation->status;
        $this->user_id = $conversation->user_id === null ? 0 : (int) $conversation->user_id;
        $this->conversation_unflushed = true;
    }

    /**
     * What has changed since the last flush, or null if nothing has.
     *
     * Used for the mid-turn SSE frame, so the page updates the moment a tool
     * finishes rather than when the assistant stops writing.
     *
     * @return array|null
     */
    public function flush()
    {
        if (!$this->unflushed && !$this->conversation_unflushed) {
            return null;
        }

        $changes = $this->payload($this->unflushed, $this->conversation_unflushed);

        $this->unflushed = [];
        $this->conversation_unflushed = false;

        return $changes;
    }

    /**
     * Everything recorded this request, or null if nothing was.
     *
     * Used for the responses that end a turn. Cumulative on purpose: a client
     * that missed a mid-turn frame converges here, and re-applying is free
     * because the browser skips threads already in the DOM
     * (core/public/js/main.js:3821).
     *
     * @return array|null
     */
    public function snapshot()
    {
        if (!$this->thread_ids && $this->status === null && $this->user_id === null) {
            return null;
        }

        return $this->payload($this->thread_ids, true);
    }

    /**
     * @return bool
     */
    public function isEmpty()
    {
        return $this->snapshot() === null;
    }

    /**
     * @param array $thread_ids           The ids this payload reports.
     * @param bool  $include_conversation Whether the status and assignee moved
     *                                    within the window this payload covers.
     *
     * @return array
     */
    protected function payload(array $thread_ids, $include_conversation)
    {
        $changes = [
            'conversation_id' => $this->conversation_id,
            'thread_ids'      => array_values($thread_ids),
        ];

        if ($this->since !== null) {
            $changes['since'] = $this->since;
        }

        $updated = array_values(array_intersect($this->updated_thread_ids, $thread_ids));

        if ($updated) {
            $changes['updated_thread_ids'] = $updated;
        }

        // Only when the draft is part of *this* payload, so the panel does not
        // offer to open it twice.
        if ($this->draft_thread_id && in_array($this->draft_thread_id, $thread_ids)) {
            $changes['draft_thread_id'] = $this->draft_thread_id;
        }

        if ($include_conversation && $this->status !== null) {
            $changes['status'] = $this->status;
        }

        if ($include_conversation && $this->user_id !== null) {
            $changes['user_id'] = $this->user_id;
        }

        return $changes;
    }

    /**
     * Write the polycast event rows for one thread.
     *
     * @param Thread $thread
     *
     * @return void
     */
    protected function broadcast(Thread $thread)
    {
        try {
            $conversation = $thread->conversation;

            if (!$conversation) {
                return;
            }

            // Stamped before the insert, and truncated to the second exactly as
            // PolycastBroadcaster stamps created_at, so the row this is about to
            // write always satisfies the endpoint's created_at >= time filter.
            if ($this->since === null) {
                $this->since = \Carbon\Carbon::now()->toDateTimeString();
            }

            event(new \App\Events\RealtimeConvNewThread([
                'thread_id'       => $thread->id,
                'conversation_id' => $conversation->id,
                'mailbox_id'      => $conversation->mailbox_id,
                // 'user_id' is omitted on purpose, exactly as core's
                // dispatchSelf() omits it (RealtimeConvNewThread.php:83).
                // main.js:3813 drops the event when data.user_id matches the
                // viewer, so including it would hide the update from the one
                // person who asked for it.
            ]));

            // Folder counters. Core dispatches this itself for drafts and for
            // notes, but Conversation::changeStatus() only updates the counters
            // in the database — nothing tells the sidebar.
            if ($thread->type == Thread::TYPE_LINEITEM) {
                \App\Events\RealtimeMailboxNewThread::dispatchSelf(
                    $conversation->mailbox_id,
                    $thread->id,
                    (int) $conversation->isChat()
                );
            }
        } catch (\Exception $e) {
            // A failed broadcast costs the user a page reload. Losing the
            // thread the assistant just wrote would cost them the work.
            \Helper::logException($e, '[AiChatPanel] Broadcasting a conversation change failed: ');
        }
    }
}
