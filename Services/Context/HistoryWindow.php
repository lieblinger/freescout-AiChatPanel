<?php

namespace Modules\AiChatPanel\Services\Context;

use Modules\AiChatPanel\Services\PanelContext;

/**
 * Bounds the chat history that is replayed to the endpoint.
 *
 * The API is stateless, so every turn re-sends the whole transcript. Left alone
 * that grows without limit: the request eventually exceeds the model's context,
 * and long before that the conversation the assistant is supposed to be helping
 * with gets squeezed out of the system message by the chat about it.
 *
 * So the chat gets its own share of the budget and is trimmed to fit. What is
 * dropped is summarised into a rollup line that the caller appends to the system
 * message.
 *
 * Three things are load-bearing.
 *
 * 1. Messages are trimmed in GROUPS, never individually. The endpoint rejects a
 *    tool result whose tool_call_id has no matching assistant tool_calls entry,
 *    and rejects an assistant tool_calls entry that nothing answers. Grouping
 *    the assistant turn together with its results makes both impossible. It is
 *    also what keeps the write-confirmation flow working: confirm() replays the
 *    history with the pending assistant turn and its result, and splitting
 *    those would break the run it is trying to resume.
 *
 *    Each group is repaired as it is closed rather than assumed to be sound —
 *    see repair(). This is the last place a stored chat is looked at before it
 *    goes on the wire, and a history that was already broken when it arrived
 *    would otherwise take the whole request down with it, on every message,
 *    forever.
 *
 * 2. The newest group is kept whatever it costs. It is the question that was
 *    just asked — sending an empty history is worse than sending an oversized
 *    one, and the endpoint's own error is a better failure than a silent one.
 *
 * 3. The rollup is built mechanically, with no second completion. Unlike a
 *    coding session, everything durable here is rebuilt from the database on
 *    every request (ticket, customer, threads, drafts), and anything learned
 *    from a tool can be looked up again. The one thing that is not
 *    re-derivable is what the agent asked for along the way, and that lives in
 *    the small user messages rather than the bulky tool results.
 */
class HistoryWindow
{
    /**
     * Stands in for a tool result that was dropped for size. The tool_call_id
     * around it survives, so the exchange stays valid.
     */
    const ELIDED_RESULT = '[Result omitted: this chat was shortened to fit the context. Call the tool again if you still need it.]';

    /**
     * Stands in for a tool result that was never recorded at all, so the call
     * above it still has an answer and the request stays valid.
     */
    const MISSING_RESULT = '[No result recorded for this call: it did not complete. Call the tool again if you still need it.]';

    /** Share of the history budget the rollup may occupy. */
    const ROLLUP_SHARE = 0.12;

    /**
     * Floor for that share, so a small budget still leaves room for the
     * boilerplate plus an instruction or two. Capped at half the budget below,
     * so it cannot swallow a tiny one whole.
     */
    const ROLLUP_MIN = 100;

    /** Characters kept from each remembered instruction. */
    const ROLLUP_ASK_CHARS = 160;

    /**
     * Rough cost of a message's envelope — role, tool_call_id, JSON punctuation.
     * Small, but a long chat is mostly short messages and it adds up.
     */
    const MESSAGE_OVERHEAD = 4;

    /** @var int */
    protected $total;

    /** @var TokenBudget Set by the winning pass of apply(). */
    protected $budget;

    /**
     * @param int $total Tokens the chat history may occupy.
     */
    public function __construct($total)
    {
        $this->total = max(0, (int) $total);
    }

    /**
     * The window for a panel context: a share of the context budget, with the
     * rest guaranteed to the system message and the ticket itself.
     *
     * @param PanelContext $context
     *
     * @return static
     */
    public static function forContext(PanelContext $context)
    {
        return new static(floor((int) $context->setting('max_context_tokens') * self::share()));
    }

    /**
     * Configured history share, clamped so a bad edit can neither zero the chat
     * nor starve the conversation it is about.
     *
     * @return float
     */
    public static function share()
    {
        $share = (float) \Config::get(AICHATPANEL_MODULE.'.history_token_share', 0.5);

        return max(0.1, min(0.9, $share));
    }

    /**
     * Trim a history to fit.
     *
     * @param array $messages As returned by Chat::toApiMessages().
     *
     * @return array ['messages' => array, 'rollup' => string, 'tokens' => int,
     *                'truncated' => bool, 'notice' => string, 'dropped' => int]
     */
    public function apply(array $messages)
    {
        $groups = $this->group($messages);

        if (!$groups) {
            $this->budget = new TokenBudget($this->total);

            return $this->result([], '', 0);
        }

        // First pass with nothing set aside for a rollup. If it turns out
        // nothing had to be dropped there is nothing to summarise, and
        // reserving for it would have trimmed the chat for no reason.
        $pass = $this->walk($groups, 0);

        if ($pass['dropped']) {
            // Something is going, so the rollup is going to exist. Redo the
            // walk with room for it rather than overspending afterwards.
            $pass = $this->walk($groups, $this->rollupAllowance());
        }

        $this->budget = $pass['budget'];

        $rollup = '';

        if ($pass['dropped']) {
            $rollup = $this->rollup(array_slice($groups, 0, $pass['dropped']));

            $this->budget->drop('chat', '', $pass['dropped']);
        }

        if ($pass['elided']) {
            // Milder than a drop — the exchange is still there, only its output
            // is gone — but the model is answering on less than it was given,
            // so say so rather than let it look like a full history.
            $this->budget->drop('chat_results', '', $pass['elided']);
        }

        return $this->result($this->flatten($pass['kept']), $rollup, $pass['dropped']);
    }

    // -----------------------------------------------------------------------

    /**
     * Walk newest to oldest, keeping what fits.
     *
     * Each group gets up to two chances: as it stands, and then with its tool
     * results elided. Anything that still does not fit ends the walk — it and
     * everything older than it goes, because keeping older groups after
     * dropping a newer one would present the model with a history that jumps.
     *
     * @param array $groups
     * @param int   $rollup_allowance Tokens to hold back for the rollup.
     *
     * @return array ['kept' => array, 'dropped' => int, 'elided' => int, 'budget' => TokenBudget]
     */
    protected function walk(array $groups, $rollup_allowance)
    {
        $budget = new TokenBudget($this->total);
        $budget->reserve($rollup_allowance);

        $kept = [];
        $elided = 0;
        $newest = true;

        foreach (array_reverse($groups) as $group) {
            if ($newest) {
                // Unconditional: this is what the user just said, or the write
                // they just confirmed.
                $budget->reserve($this->groupCost($group));
                $kept[] = $group;
                $newest = false;
                continue;
            }

            if ($budget->tryReserve($this->groupCost($group))) {
                $kept[] = $group;
                continue;
            }

            $compact = $this->elide($group);

            if ($compact !== null && $budget->tryReserve($this->groupCost($compact))) {
                $kept[] = $compact;
                $elided++;
                continue;
            }

            break;
        }

        return [
            'kept'    => array_reverse($kept),
            'dropped' => count($groups) - count($kept),
            'elided'  => $elided,
            'budget'  => $budget,
        ];
    }

    /**
     * Split a flat message list into indivisible groups.
     *
     * A group is one user message, one plain assistant message, or an assistant
     * message together with every tool result answering it.
     *
     * @param array $messages
     *
     * @return array
     */
    protected function group(array $messages)
    {
        $groups = [];
        $current = [];

        foreach ($messages as $message) {
            $role = isset($message['role']) ? $message['role'] : '';

            if ($role === 'tool') {
                if (!$current) {
                    // A result with nothing to answer. Cannot happen from
                    // persistTurns(), which always writes the assistant turn
                    // first, but sending it would be rejected outright — so
                    // drop it rather than trust the invariant.
                    continue;
                }

                $current[] = $message;
                continue;
            }

            if ($current) {
                $groups[] = $this->repair($current);
            }

            $current = [$message];
        }

        if ($current) {
            $groups[] = $this->repair($current);
        }

        return $groups;
    }

    /**
     * One group, made valid on its own terms.
     *
     * Every tool result answers a call the turn above it actually made, and
     * every call it made has exactly one answer. Both halves are enforced, not
     * trusted: the endpoint refuses the entire request over either, so a single
     * missing row anywhere in a stored chat makes that chat permanently
     * unusable — which is exactly what a dropped result did.
     *
     * A synthesised answer is a poor substitute for the real one, but the model
     * can call the tool again, and the alternative is no request at all.
     *
     * @param array $group Head message first, its tool results after it.
     *
     * @return array
     */
    protected function repair(array $group)
    {
        $head = $group[0];
        $called = [];

        if (isset($head['role']) && $head['role'] === 'assistant' && !empty($head['tool_calls'])) {
            foreach ($head['tool_calls'] as $call) {
                if (isset($call['id'])) {
                    $called[] = (string) $call['id'];
                }
            }
        }

        $repaired = [$head];
        $answered = [];

        foreach (array_slice($group, 1) as $message) {
            $id = isset($message['tool_call_id']) ? (string) $message['tool_call_id'] : '';

            // Nothing asked for this, or something already answered it. A
            // group headed by a user message has $called empty, so results
            // stranded by a dropped assistant turn go here too.
            if (!in_array($id, $called, true) || in_array($id, $answered, true)) {
                continue;
            }

            $answered[] = $id;
            $repaired[] = $message;
        }

        foreach ($called as $id) {
            if (in_array($id, $answered, true)) {
                continue;
            }

            $repaired[] = [
                'role'         => 'tool',
                'tool_call_id' => $id,
                'content'      => self::MISSING_RESULT,
            ];
        }

        return $repaired;
    }

    /**
     * The same group with its tool results replaced by a placeholder.
     *
     * Tool output is the bulk of a long chat and the part that ages worst — the
     * model can call the tool again and get something current. The call itself
     * is kept so the model still knows what it did.
     *
     * @param array $group
     *
     * @return array|null Null when there is nothing to elide.
     */
    protected function elide(array $group)
    {
        $elided = [];
        $changed = false;

        foreach ($group as $message) {
            if (isset($message['role']) && $message['role'] === 'tool'
                && isset($message['content']) && $message['content'] !== self::ELIDED_RESULT) {
                $message['content'] = self::ELIDED_RESULT;
                $changed = true;
            }

            $elided[] = $message;
        }

        return $changed ? $elided : null;
    }

    /**
     * @param array $group
     *
     * @return int
     */
    protected function groupCost(array $group)
    {
        $cost = 0;

        foreach ($group as $message) {
            $cost += $this->cost($message);
        }

        return $cost;
    }

    /**
     * What one message costs.
     *
     * tool_calls are counted, not just content: the arguments are real tokens,
     * and a chat whose weight is in its tool calls would otherwise look free.
     *
     * @param array $message
     *
     * @return int
     */
    protected function cost(array $message)
    {
        $text = isset($message['content']) ? (string) $message['content'] : '';

        if (!empty($message['tool_calls'])) {
            $text .= \Helper::jsonEncodeSafe($message['tool_calls']);
        }

        return TokenBudget::estimate($text) + self::MESSAGE_OVERHEAD;
    }

    /**
     * @return int
     */
    protected function rollupAllowance()
    {
        $share = (int) floor($this->total * self::ROLLUP_SHARE);

        return (int) min(floor($this->total / 2), max($share, self::ROLLUP_MIN));
    }

    /**
     * A summary of the groups that were dropped, for the system message.
     *
     * What the agent asked for, and which tools were called. Deliberately not a
     * model-generated summary: that would cost a completion on the critical
     * path of a user-visible turn, and there is very little here a completion
     * would recover that this does not.
     *
     * @param array $groups Dropped groups, oldest first.
     *
     * @return string
     */
    protected function rollup(array $groups)
    {
        $asks = [];
        $tools = [];

        foreach ($groups as $group) {
            foreach ($group as $message) {
                $role = isset($message['role']) ? $message['role'] : '';

                if ($role === 'user' && !empty($message['content'])) {
                    $asks[] = $this->condense($message['content']);
                }

                if ($role === 'assistant' && !empty($message['tool_calls'])) {
                    foreach ($message['tool_calls'] as $call) {
                        $name = isset($call['function']['name']) ? $call['function']['name'] : '';

                        if ($name && !in_array($name, $tools)) {
                            $tools[] = $name;
                        }
                    }
                }
            }
        }

        $turns = count($groups);

        $head = 'Earlier part of this chat ('.$turns.' turn'.($turns === 1 ? '' : 's').
            ') was left out to fit the context budget.';

        // Priority order, and it is deliberate. The instructions the agent gave
        // are the only thing here that no tool can produce again, so they are
        // fitted first and at least one always survives. The list of tools
        // called is a nicety — if it does not fit, it goes.
        $budget = new TokenBudget($this->rollupAllowance());
        $budget->reserve(TokenBudget::estimate($head));

        $middle = $this->fitAsks($asks, $budget);

        $tail = '';

        if ($tools) {
            $tail = ' Tools called in the omitted part: '.implode(', ', $tools).
                '. Anything they returned may be out of date — call them again rather than relying on it.';

            if (!$budget->tryReserve(TokenBudget::estimate($tail))) {
                $tail = '';
            }
        }

        return $head.$middle.$tail;
    }

    /**
     * As many remembered instructions as the allowance permits, newest first —
     * the recent ones are likelier to still apply — then back into
     * chronological order.
     *
     * The newest one is kept whatever it costs, on the same reasoning as the
     * newest group: a rollup that says only "some turns were left out" tells
     * the model nothing it could act on.
     *
     * @param array       $asks
     * @param TokenBudget $budget
     *
     * @return string
     */
    protected function fitAsks(array $asks, TokenBudget $budget)
    {
        $kept = [];

        foreach (array_reverse($asks) as $ask) {
            // +2 for the separator and the quotes around each one.
            $cost = TokenBudget::estimate($ask) + 2;

            if (!$kept) {
                $budget->reserve($cost);
                $kept[] = $ask;
                continue;
            }

            if (!$budget->tryReserve($cost)) {
                break;
            }

            $kept[] = $ask;
        }

        if (!$kept) {
            return '';
        }

        return ' What the agent asked for, in order: "'.implode('"; "', array_reverse($kept)).'".';
    }

    /**
     * One instruction, on one line, short enough to be worth remembering.
     *
     * @param string $text
     *
     * @return string
     */
    protected function condense($text)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        // Quotes would close the ones the rollup wraps this in.
        $text = str_replace('"', "'", $text);

        if (mb_strlen($text) > self::ROLLUP_ASK_CHARS) {
            $text = rtrim(mb_substr($text, 0, self::ROLLUP_ASK_CHARS)).'…';
        }

        return $text;
    }

    /**
     * @param array $groups
     *
     * @return array
     */
    protected function flatten(array $groups)
    {
        $messages = [];

        foreach ($groups as $group) {
            foreach ($group as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @param array  $messages
     * @param string $rollup
     * @param int    $dropped
     *
     * @return array
     */
    protected function result(array $messages, $rollup, $dropped)
    {
        $tokens = 0;

        foreach ($messages as $message) {
            $tokens += $this->cost($message);
        }

        return [
            'messages'  => $messages,
            'rollup'    => $rollup,
            'dropped'   => $dropped,
            'tokens'    => $tokens + TokenBudget::estimate($rollup),
            'truncated' => $this->budget->truncated(),
            'notice'    => $this->budget->truncationNotice(),
        ];
    }
}
