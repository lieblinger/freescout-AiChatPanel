<?php

namespace Modules\AiChatPanel\Services\Context;

/**
 * A pessimistic token estimator and a spend tracker.
 *
 * We cannot tokenise the way the far end will — the endpoint's tokeniser is
 * model-specific and not exposed. So this deliberately over-estimates: sending
 * a request the model rejects for length is a worse failure than trimming one
 * message too many, because the user sees an error instead of an answer.
 */
class TokenBudget
{
    /** @var int */
    protected $total;

    /** @var int */
    protected $spent = 0;

    /**
     * What had to be dropped, so the panel can say so instead of silently
     * lying about what the model saw.
     *
     * @var array
     */
    protected $dropped = [];

    /**
     * @param int $total
     */
    public function __construct($total)
    {
        $this->total = max(0, (int) $total);
    }

    /**
     * Rough token count for a string.
     *
     * @param string $text
     *
     * @return int
     */
    public static function estimate($text)
    {
        $text = (string) $text;

        if ($text === '') {
            return 0;
        }

        $chars_per_token = (float) \Config::get(AICHATPANEL_MODULE.'.chars_per_token', 3.5);

        if ($chars_per_token <= 0) {
            $chars_per_token = 3.5;
        }

        return (int) ceil(mb_strlen($text) / $chars_per_token);
    }

    /**
     * @return int
     */
    public function total()
    {
        return $this->total;
    }

    /**
     * @return int
     */
    public function spent()
    {
        return $this->spent;
    }

    /**
     * @return int
     */
    public function remaining()
    {
        return max(0, $this->total - $this->spent);
    }

    /**
     * Whether a block of this size still fits.
     *
     * @param int $tokens
     *
     * @return bool
     */
    public function fits($tokens)
    {
        return $this->spent + (int) $tokens <= $this->total;
    }

    /**
     * Reserve tokens unconditionally. Used for the parts that are not
     * negotiable — the system prompt and the user's own question.
     *
     * @param int $tokens
     *
     * @return void
     */
    public function reserve($tokens)
    {
        $this->spent += max(0, (int) $tokens);
    }

    /**
     * Reserve only if it fits.
     *
     * @param int $tokens
     *
     * @return bool Whether it was reserved.
     */
    public function tryReserve($tokens)
    {
        if (!$this->fits($tokens)) {
            return false;
        }

        $this->reserve($tokens);

        return true;
    }

    /**
     * Record something that did not fit.
     *
     * @param string $kind  'messages' | 'provider'
     * @param string $label
     * @param int    $count
     *
     * @return void
     */
    public function drop($kind, $label, $count = 1)
    {
        if (!isset($this->dropped[$kind])) {
            $this->dropped[$kind] = ['count' => 0, 'labels' => []];
        }

        $this->dropped[$kind]['count'] += (int) $count;

        if ($label && !in_array($label, $this->dropped[$kind]['labels'])) {
            $this->dropped[$kind]['labels'][] = $label;
        }
    }

    /**
     * @return bool
     */
    public function truncated()
    {
        return !empty($this->dropped);
    }

    /**
     * @return array
     */
    public function dropped()
    {
        return $this->dropped;
    }

    /**
     * A sentence for the panel describing what was left out.
     *
     * @return string
     */
    public function truncationNotice()
    {
        if (!$this->truncated()) {
            return '';
        }

        $parts = [];

        if (!empty($this->dropped['messages']['count'])) {
            $parts[] = trans_choice(
                '{1} the oldest message|[2,*] the :count oldest messages',
                $this->dropped['messages']['count'],
                ['count' => $this->dropped['messages']['count']]
            );
        }

        if (!empty($this->dropped['provider']['labels'])) {
            $parts[] = implode(', ', $this->dropped['provider']['labels']);
        }

        return __('Context was shortened to fit the token budget: :parts left out.', [
            'parts' => implode(__(' and '), $parts),
        ]);
    }
}
