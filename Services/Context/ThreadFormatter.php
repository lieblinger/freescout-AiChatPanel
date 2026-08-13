<?php

namespace Modules\AiChatPanel\Services\Context;

use App\Thread;
use Modules\AiChatPanel\Services\Markdown\HtmlToMarkdown;

/**
 * Turns a Thread into the clean Markdown that goes into the prompt.
 *
 * Three jobs, in order:
 *   1. HTML to Markdown, so the model sees the structure the customer wrote —
 *      a list stays a list, a link keeps its target — instead of the flattened
 *      text core's Html2Text wrapper produces;
 *   2. cut the quoted reply chain, so a twelve-message thread does not repeat
 *      the first message twelve times;
 *   3. cut the signature.
 *
 * Steps 2 and 3 are best-effort by nature — quoting is not standardised and
 * signatures are not marked up at all. The panel says the context was trimmed
 * rather than pretending this is exact.
 */
class ThreadFormatter
{
    /**
     * Markdown of a thread body, with quotes and signature removed.
     *
     * The quote chain is cut on the HTML, before conversion: the markers are
     * HTML — \MailHelper::REPLY_SEPARATOR_HTML, <div class="gmail_quote"> and
     * friends — and cutting first also means the quoted half is never converted
     * at all.
     *
     * @param Thread      $thread
     * @param string|null $signature Rendered mailbox signature, when known.
     *
     * @return string
     */
    public static function body(Thread $thread, $signature = null)
    {
        $html = (string) $thread->body;

        if (trim($html) === '') {
            return '';
        }

        $html = self::stripQuotedHtml($html);

        $text = HtmlToMarkdown::fromThread($html);
        $text = self::stripQuotedText($text);
        $text = self::stripSignature($text, $signature);

        return self::collapse($text);
    }

    /**
     * Markdown of a draft body, with nothing removed.
     *
     * Deliberately not body(): quote and signature stripping are right for
     * history and wrong here. A draft is about to be rewritten verbatim, so
     * hiding a pasted quote from the model would silently delete that quote from
     * the agent's draft.
     *
     * @param Thread $thread
     *
     * @return string
     */
    public static function draftBody(Thread $thread)
    {
        $html = (string) $thread->body;

        if (trim($html) === '') {
            return '';
        }

        // Markdown, like every other body the model reads. It matters more
        // here than anywhere else: the model reads a draft in order to rewrite
        // it, and whatever it reads is what it writes back — so flattening the
        // formatting here would strip it from the draft on the next edit.
        return self::collapse(HtmlToMarkdown::fromThread($html));
    }

    /**
     * Cut at the first quote marker.
     *
     * The marker list is core's own — \MailHelper::$alternative_reply_separators
     * (core/app/Misc/Mail.php:67), which is public and static. Core's actual
     * splitter lives on the FetchEmails command and cannot be reused from here,
     * so only the pattern list is shared.
     *
     * @param string $html
     *
     * @return string
     */
    public static function stripQuotedHtml($html)
    {
        $earliest = null;

        foreach (self::separators() as $separator) {
            if (strpos($separator, 'regex:') === 0) {
                $pattern = substr($separator, 6);

                if (@preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE) && isset($m[0][1])) {
                    $position = $m[0][1];
                } else {
                    continue;
                }
            } else {
                $position = stripos($html, $separator);

                if ($position === false) {
                    continue;
                }
            }

            if ($earliest === null || $position < $earliest) {
                $earliest = $position;
            }
        }

        // A marker at the very start means the whole body is quoted; keeping
        // nothing would be worse than keeping everything.
        if ($earliest === null || $earliest < 20) {
            return $html;
        }

        return substr($html, 0, $earliest);
    }

    /**
     * Text-side quote markers that survive the HTML conversion.
     *
     * @param string $text
     *
     * @return string
     */
    public static function stripQuotedText($text)
    {
        $patterns = [
            // "On Mon, 3 Feb 2025 at 14:02, Someone <a@b.c> wrote:"
            '/^\s*On .{10,120}\s+wrote:\s*$/mi',
            // German and French equivalents, common in European helpdesks.
            '/^\s*Am .{10,120}\s+schrieb\s.{0,120}:\s*$/mi',
            '/^\s*Le .{10,120}\s+a écrit\s*:\s*$/mi',
            '/^\s*-{2,}\s*Original(?: Message| E-Mail|nachricht)?\s*-{2,}\s*$/mi',
            // The converter never escapes a line that is nothing but a run of
            // underscores, dashes or equals signs, precisely so this keeps
            // matching. The backslash is belt and braces.
            '/^\s*[\\_]{10,}\s*$/m',
            '/^\s*From:\s.+$/mi',
            '/'.preg_quote(\MailHelper::REPLY_SEPARATOR_TEXT, '/').'/i',
        ];

        $earliest = null;

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) && isset($m[0][1])) {
                $position = $m[0][1];

                if ($earliest === null || $position < $earliest) {
                    $earliest = $position;
                }
            }
        }

        if ($earliest === null || $earliest < 20) {
            return $text;
        }

        return substr($text, 0, $earliest);
    }

    /**
     * Remove a trailing signature.
     *
     * Two strategies: the literal rendered mailbox signature when we have it,
     * and the classic sigdashes line.
     *
     * @param string      $text
     * @param string|null $signature
     *
     * @return string
     */
    public static function stripSignature($text, $signature = null)
    {
        if ($signature) {
            // Converted the same way as the body, or a signature with a link
            // or a bold name in it would never match.
            $signature_text = self::collapse(HtmlToMarkdown::fromThread($signature));

            if (mb_strlen($signature_text) > 3) {
                $position = mb_strrpos($text, $signature_text);

                if ($position !== false) {
                    $text = mb_substr($text, 0, $position);
                }
            }
        }

        // Sigdashes: a line consisting of exactly "--" or "-- ".
        if (preg_match('/^\s*--\s*$/m', $text, $m, PREG_OFFSET_CAPTURE) && isset($m[0][1]) && $m[0][1] > 20) {
            $text = substr($text, 0, $m[0][1]);
        }

        return $text;
    }

    /**
     * Squash runs of blank lines and trailing whitespace. Email bodies are full
     * of them and every one costs tokens.
     *
     * @param string $text
     *
     * @return string
     */
    public static function collapse($text)
    {
        $text = preg_replace("/\r\n?/", "\n", (string) $text);
        $text = preg_replace('/[ \t]+$/m', '', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * Core's quote-marker list.
     *
     * @return array
     */
    protected static function separators()
    {
        $separators = [];

        if (property_exists('\MailHelper', 'alternative_reply_separators')) {
            $separators = \MailHelper::$alternative_reply_separators;
        }

        if (!is_array($separators)) {
            $separators = [];
        }

        // Belt and braces: the hashed per-message marker core puts in outgoing
        // mail is not in that list, only its unhashed prefix is.
        $separators[] = \MailHelper::REPLY_SEPARATOR_HTML;

        return array_values(array_filter($separators, function ($s) {
            return is_string($s) && $s !== '';
        }));
    }

    /**
     * A human label for who wrote a thread, for the prompt.
     *
     * Users are deletable and still appear in old threads, so $thread->user can
     * be null even on a TYPE_MESSAGE.
     *
     * @param Thread $thread
     *
     * @return string
     */
    public static function author(Thread $thread)
    {
        switch ($thread->type) {
            case Thread::TYPE_CUSTOMER:
                $customer = $thread->customer;
                $name = $customer ? $customer->getFullName(true) : '';

                return $name ? 'Customer '.$name : 'Customer';

            case Thread::TYPE_NOTE:
                $user = $thread->user;

                return $user ? 'Agent '.$user->getFullName().' (internal note)' : 'Agent (internal note)';

            case Thread::TYPE_MESSAGE:
            default:
                $user = $thread->user;

                return $user ? 'Agent '.$user->getFullName() : 'Agent';
        }
    }

    /**
     * Label for the kind of turn, so the model can tell a note from a reply.
     *
     * Drafts get their own labels: the difference between text the customer has
     * read and text nobody has sent yet is the most important thing the model
     * can know about a thread.
     *
     * @param Thread $thread
     *
     * @return string
     */
    public static function kind(Thread $thread)
    {
        if ($thread->isDraft()) {
            if ($thread->type == Thread::TYPE_NOTE) {
                return 'draft_note';
            }

            return $thread->isForward() ? 'draft_forward' : 'draft_reply';
        }

        switch ($thread->type) {
            case Thread::TYPE_CUSTOMER:
                return 'customer_message';

            case Thread::TYPE_NOTE:
                return 'internal_note';

            case Thread::TYPE_MESSAGE:
                return 'agent_reply';

            default:
                return 'message';
        }
    }
}
