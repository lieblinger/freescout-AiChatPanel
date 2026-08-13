<?php

namespace Modules\AiChatPanel\Tests;

use Modules\AiChatPanel\Services\Context\ContextBuilder;
use Modules\AiChatPanel\Services\Context\HistoryWindow;
use Modules\AiChatPanel\Services\Context\TokenBudget;

/**
 * Trimming the chat history so a long chat cannot grow the request without
 * limit, or crowd the conversation out of the system message.
 *
 * The assertion that matters most is protocolIsValid(): whatever else the
 * window does, what comes out has to be something the endpoint will accept.
 */
class HistoryWindowTest extends AiChatPanelTestCase
{
    public function testAShortHistoryComesBackUntouched()
    {
        $history = [
            $this->user('Draft a reply.'),
            $this->assistant('Here you go.'),
            $this->user('Make it shorter.'),
        ];

        $window = (new HistoryWindow(4000))->apply($history);

        $this->assertEquals($history, $window['messages']);
        $this->assertFalse($window['truncated']);
        $this->assertEquals(0, $window['dropped']);
        $this->assertEquals('', $window['rollup']);
    }

    public function testTheOldestTurnsGoFirstAndTheNewestSurvive()
    {
        $history = [];

        for ($i = 1; $i <= 12; $i++) {
            $history[] = $this->user('Question number '.$i.'. '.str_repeat('padding ', 40));
            $history[] = $this->assistant('Answer number '.$i.'. '.str_repeat('padding ', 40));
        }

        $window = (new HistoryWindow(600))->apply($history);

        $content = $this->contentOf($window['messages']);

        $this->assertGreaterThan(0, $window['dropped']);
        $this->assertTrue($window['truncated']);

        // The most recent exchange is what the user is actually talking about.
        $this->assertStringContainsString('Question number 12.', $content);
        $this->assertStringContainsString('Answer number 12.', $content);

        // The oldest is gone.
        $this->assertStringNotContainsString('Question number 1.', $content);

        $this->protocolIsValid($window['messages']);
    }

    public function testTheNewestTurnSurvivesEvenWhenItAloneBlowsTheBudget()
    {
        $history = [
            $this->user('Old and small.'),
            $this->assistant('Fine.'),
            $this->user(str_repeat('an enormous pasted log line ', 500)),
        ];

        $window = (new HistoryWindow(200))->apply($history);

        // Sending nothing would be worse: the endpoint's own length error is a
        // better failure than an empty history that silently answers nothing.
        $this->assertNotEmpty($window['messages']);
        $this->assertStringContainsString(
            'an enormous pasted log line',
            $this->contentOf($window['messages'])
        );

        $this->protocolIsValid($window['messages']);
    }

    public function testAToolResultIsNeverSeparatedFromTheCallThatAskedForIt()
    {
        $history = [];

        for ($i = 1; $i <= 8; $i++) {
            $history[] = $this->user('Look up conversation '.$i.'. '.str_repeat('padding ', 30));
            $history[] = $this->assistantCall('call-'.$i, 'conversation.get', '{"id":'.$i.'}');
            $history[] = $this->tool('call-'.$i, 'Conversation '.$i.'. '.str_repeat('result body ', 60));
            $history[] = $this->assistant('That one is about a refund. '.str_repeat('padding ', 30));
        }

        // Tight enough to force several rounds of trimming.
        foreach ([300, 700, 1500, 3000] as $budget) {
            $window = (new HistoryWindow($budget))->apply($history);

            $this->protocolIsValid($window['messages'], 'budget '.$budget);
        }
    }

    public function testAPendingWriteAndItsResultAreNeverSplit()
    {
        // The shape confirm() replays: the assistant asked for a write, the
        // user approved it, and the result was persisted. Splitting these
        // would break the run this history exists to resume.
        $history = [
            $this->user('Old chatter. '.str_repeat('padding ', 100)),
            $this->assistant('Old answer. '.str_repeat('padding ', 100)),
            $this->user('Add a note saying we called them.'),
            $this->assistantCall('write-1', 'conversation.add_note', '{"body":"We called them."}'),
            $this->tool('write-1', 'Note added.'),
        ];

        $window = (new HistoryWindow(120))->apply($history);

        $content = $this->contentOf($window['messages']);

        $this->assertStringContainsString('Note added.', $content);
        $this->assertStringNotContainsString('Old chatter.', $content);

        $this->protocolIsValid($window['messages']);
    }

    public function testToolResultsAreElidedBeforeWholeTurnsAreDropped()
    {
        $history = [
            $this->user('First question.'),
            $this->assistantCall('call-1', 'customer.get', '{}'),
            $this->tool('call-1', str_repeat('an extremely long tool result ', 120)),
            $this->assistant('The customer is on the pro plan.'),
            $this->user('Thanks, now draft a reply.'),
        ];

        // Enough for everything except that one enormous result.
        $window = (new HistoryWindow(260))->apply($history);

        $content = $this->contentOf($window['messages']);

        // The exchange survived; only its output went.
        $this->assertStringContainsString('First question.', $content);
        $this->assertStringContainsString('The customer is on the pro plan.', $content);
        $this->assertStringContainsString(HistoryWindow::ELIDED_RESULT, $content);
        $this->assertStringNotContainsString('an extremely long tool result', $content);

        $this->assertEquals(0, $window['dropped']);
        $this->assertTrue($window['truncated']);
        $this->assertStringContainsString('tool call', $window['notice']);

        $this->protocolIsValid($window['messages']);
    }

    public function testToolCallArgumentsCountTowardsTheCost()
    {
        // A turn whose weight is entirely in its arguments. Costing content
        // alone would call this free and let it through.
        $arguments = '{"body":"'.str_repeat('x', 6000).'"}';

        $history = [
            $this->user('Keep this one.'),
            $this->assistantCall('call-1', 'conversation.update_draft', $arguments),
            $this->tool('call-1', 'Draft updated.'),
            $this->user('And now the last question.'),
        ];

        $window = (new HistoryWindow(400))->apply($history);

        $this->assertGreaterThan(0, $window['dropped']);
        $this->assertStringNotContainsString(
            $arguments,
            \Helper::jsonEncodeSafe($window['messages'])
        );

        $this->protocolIsValid($window['messages']);
    }

    public function testTheRollupRemembersWhatTheAgentAskedForAndWhichToolsRan()
    {
        $window = (new HistoryWindow(200))->apply($this->historyWorthSummarising());

        $this->assertGreaterThan(0, $window['dropped']);

        $this->assertStringContainsString('Always answer in German', $window['rollup']);
        $this->assertStringContainsString('customer.get', $window['rollup']);
        $this->assertStringContainsString('out of date', $window['rollup']);
    }

    public function testTheRollupGivesUpTheToolListBeforeTheInstructions()
    {
        // Squeezed hard enough that the whole rollup will not fit. What the
        // agent asked for cannot be recovered any other way; which tools ran
        // can — the model can just call them again — so that is what goes.
        $window = (new HistoryWindow(150))->apply($this->historyWorthSummarising());

        $this->assertStringContainsString('Always answer in German', $window['rollup']);
        $this->assertStringNotContainsString('customer.get', $window['rollup']);
    }

    /**
     * A chat carrying one durable instruction and one tool call, long enough
     * that its older turns have to go.
     *
     * @return array
     */
    protected function historyWorthSummarising()
    {
        return [
            $this->user('Always answer in German and keep it formal.'),
            $this->assistantCall('call-1', 'customer.get', '{}'),
            $this->tool('call-1', str_repeat('customer profile data ', 100)),
            $this->assistant('Understood. '.str_repeat('padding ', 100)),
            $this->user('Now draft the reply.'),
        ];
    }

    public function testThereIsNoRollupWhenNothingWasDropped()
    {
        $window = (new HistoryWindow(4000))->apply([
            $this->user('Short.'),
            $this->assistant('Also short.'),
        ]);

        $this->assertEquals('', $window['rollup']);
        $this->assertFalse($window['truncated']);
        $this->assertEquals('', $window['notice']);
    }

    public function testAnEmptyHistoryIsHandled()
    {
        $window = (new HistoryWindow(4000))->apply([]);

        $this->assertEquals([], $window['messages']);
        $this->assertEquals('', $window['rollup']);
        $this->assertFalse($window['truncated']);
    }

    /**
     * The regression this whole thing exists for.
     *
     * The chat used to be reserved at its full size, so a long one pushed the
     * conversation out of the system message entirely and the assistant ended
     * up answering about a ticket it could no longer see.
     */
    public function testALongChatNoLongerCrowdsTheConversationOutOfTheSystemMessage()
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->addThread('<div>Message number '.$i.'. '.str_repeat('padding text ', 120).'</div>');
        }

        $this->setSettings(['max_context_tokens' => 8000]);

        // A chat far larger than the whole budget.
        $history = [];

        for ($i = 1; $i <= 100; $i++) {
            $history[] = $this->user('Chat message '.$i.'. '.str_repeat('chatter ', 30));
            $history[] = $this->assistant('Chat answer '.$i.'. '.str_repeat('chatter ', 30));
        }

        // What used to happen: the raw history reserved against the budget.
        $raw = TokenBudget::estimate($this->contentOf($history));
        $before = (new ContextBuilder($this->context()))->build($raw);

        $this->assertStringNotContainsString(
            'Message number 12.',
            $before['content'],
            'precondition: the unbounded reservation used to leave no room for the conversation'
        );

        // What happens now: the chat is windowed first, and only what survives
        // is reserved.
        $window = HistoryWindow::forContext($this->context())->apply($history);
        $after = (new ContextBuilder($this->context()))->build($window['tokens']);

        $this->assertStringContainsString('Message number 12.', $after['content']);
        $this->assertLessThan($raw, $window['tokens']);

        // And the chat itself is bounded by its share rather than growing on.
        $this->assertLessThanOrEqual(
            8000 * HistoryWindow::share(),
            $window['tokens'],
            'the chat must stay inside its share of the budget'
        );
    }

    public function testTheShareIsClampedSoABadConfigCannotStarveEitherSide()
    {
        \Config::set(AICHATPANEL_MODULE.'.history_token_share', 0);
        $this->assertEquals(0.1, HistoryWindow::share());

        \Config::set(AICHATPANEL_MODULE.'.history_token_share', 5);
        $this->assertEquals(0.9, HistoryWindow::share());

        \Config::set(AICHATPANEL_MODULE.'.history_token_share', 0.5);
        $this->assertEquals(0.5, HistoryWindow::share());
    }

    // -----------------------------------------------------------------------

    /**
     * The invariant the endpoint enforces: every tool result answers a call
     * that is present, and every call present has an answer.
     *
     * @param array  $messages
     * @param string $context
     *
     * @return void
     */
    protected function protocolIsValid(array $messages, $context = '')
    {
        $where = $context ? ' ('.$context.')' : '';

        $called = [];
        $answered = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'assistant' && !empty($message['tool_calls'])) {
                foreach ($message['tool_calls'] as $call) {
                    $called[] = $call['id'];
                }
            }

            if ($message['role'] === 'tool') {
                $answered[] = $message['tool_call_id'];
            }
        }

        $this->assertEquals(
            [],
            array_values(array_diff($answered, $called)),
            'orphaned tool result: no assistant turn asked for it'.$where
        );

        $this->assertEquals(
            [],
            array_values(array_diff($called, $answered)),
            'unanswered tool call: the endpoint rejects the whole request'.$where
        );
    }

    /**
     * @param array $messages
     *
     * @return string
     */
    protected function contentOf(array $messages)
    {
        $text = '';

        foreach ($messages as $message) {
            $text .= (isset($message['content']) ? $message['content'] : '')."\n";
        }

        return $text;
    }

    /**
     * @return array
     */
    protected function user($content)
    {
        return ['role' => 'user', 'content' => $content];
    }

    /**
     * @return array
     */
    protected function assistant($content)
    {
        return ['role' => 'assistant', 'content' => $content];
    }

    /**
     * @return array
     */
    protected function assistantCall($id, $name, $arguments)
    {
        return [
            'role'       => 'assistant',
            'content'    => '',
            'tool_calls' => [[
                'id'       => $id,
                'type'     => 'function',
                'function' => ['name' => $name, 'arguments' => $arguments],
            ]],
        ];
    }

    /**
     * @return array
     */
    protected function tool($id, $content)
    {
        return ['role' => 'tool', 'tool_call_id' => $id, 'content' => $content];
    }
}
