<?php

namespace Modules\AiChatPanel\Tests;

use App\Thread;
use Modules\AiChatPanel\Entities\Chat;
use Modules\AiChatPanel\Entities\Message;
use Modules\AiChatPanel\Http\Controllers\ChatController;
use Modules\AiChatPanel\Services\Context\ContextBuilder;
use Modules\AiChatPanel\Services\Context\HistoryWindow;
use Modules\AiChatPanel\Services\Context\TokenBudget;
use Modules\AiChatPanel\Services\Tools\ToolRegistry;

/**
 * The conversation's guaranteed share of the budget.
 *
 * The bug this suite exists for: an agent works through a ticket in the panel,
 * the customer replies while they are doing it, and the assistant goes on
 * insisting nobody has written — then finds the reply the moment it is pushed
 * to call conversation_get.
 *
 * Nothing was cached. The system message is rebuilt from the database on every
 * turn, so the reply was in the query every time. What it was not in was the
 * request: build() reserves the instructions, the tool schemas, the open editor
 * draft and the whole chat unconditionally, and TokenBudget::reserve() is
 * allowed to overspend. Past the total, tryReserve() fails for every thread and
 * the entire history block leaves the system message without a word. All that
 * is left saying anything about the ticket is the chat transcript — in which
 * the assistant's own earlier turns describe the ticket as it was when they
 * were written. It answers from those, confidently, because nothing in the
 * request contradicts them.
 *
 * So the guarantee is structural, in three parts, and each is tested here:
 * the chat is windowed to what is left after the conversation's floor
 * (historyAllowance), the newest message is kept whatever it costs, and what
 * goes is the oldest — really the oldest, not whatever happened not to fit.
 */
class RecentHistoryFloorTest extends AiChatPanelTestCase
{
    /**
     * The regression, in the shape it was reported.
     *
     * A reservation larger than the entire budget, which is what a long chat
     * used to produce, must still not cost the agent the message that just
     * arrived.
     */
    public function testTheNewestMessageSurvivesAReservationLargerThanTheBudget()
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->addThread('<div>Message number '.$i.'. '.str_repeat('padding text ', 120).'</div>');
        }

        $this->setSettings(['max_context_tokens' => 4000]);

        $built = (new ContextBuilder($this->context()))->build(999999);

        $this->assertStringContainsString('Message number 12.', $built['content']);
        $this->assertTrue($built['truncated'], 'everything older than it has to be reported as dropped');
    }

    /**
     * What is kept is a run of the newest messages, with no holes in it.
     *
     * The old loop walked newest to oldest and carried on past anything that
     * did not fit, so a big recent message was dropped while smaller older ones
     * were squeezed in behind it. That produced a history that jumps — and a
     * notice calling the survivors' absent neighbours "the oldest".
     */
    public function testWhatIsKeptIsTheNewestRunAndWhatIsDroppedIsReallyTheOldest()
    {
        // Alternating sizes, so a loop that skips rather than stops will keep a
        // small old message after dropping a large newer one.
        for ($i = 1; $i <= 10; $i++) {
            $padding = str_repeat('padding text ', $i % 2 ? 200 : 5);
            $this->addThread('<div>Message number '.$i.'. '.$padding.'</div>');
        }

        $this->setSettings(['max_context_tokens' => 3000]);

        $content = (new ContextBuilder($this->context()))->build(0)['content'];

        $kept = [];

        for ($i = 1; $i <= 10; $i++) {
            if (strpos($content, 'Message number '.$i.'.') !== false) {
                $kept[] = $i;
            }
        }

        $this->assertNotEmpty($kept, 'the newest message is kept whatever it costs');
        $this->assertContains(10, $kept, 'the newest message is the one that must never go');

        // Contiguous, and ending at the newest: that is what "the oldest were
        // left out" claims, so it is what has to be true.
        $this->assertSame(range(min($kept), 10), $kept, 'the kept messages must be an unbroken newest-first run');
    }

    /**
     * One line of current fact that outlives the history block.
     *
     * Reserved with the rest of the metadata, before anything droppable, so it
     * survives a budget that leaves room for almost nothing else. It is the
     * last thing standing between the model and a confident answer drawn from
     * its own stale turns.
     */
    public function testMetadataNamesTheNewestMessageAndDatesIt()
    {
        $this->addThread('<div>An older one.</div>');
        $newest = $this->addThread('<div>The one that just arrived.</div>');

        $content = (new ContextBuilder($this->context()))->build(999999)['content'];

        $this->assertStringContainsString('Latest message: customer_message', $content);
        $this->assertStringContainsString('newest message in this conversation right now', $content);

        // Dated, and dated relatively: "how long ago" is a subtraction the
        // model is documented as being unreliable at.
        $this->assertStringContainsString(
            \Modules\AiChatPanel\Services\Clock::dateTime($newest->created_at, $this->agent),
            $content
        );
        $this->assertMatchesRegularExpression('/Latest message:.*\((just now|\d+ \w+ ago)\)/', $content);
    }

    /**
     * The instruction that answers the other half of the failure: the model
     * preferring what it said earlier over what it is being shown now.
     */
    public function testTheInstructionsSayTheConversationBlocksBeatEarlierAnswers()
    {
        $content = (new ContextBuilder($this->context()))->build(0)['content'];

        $this->assertStringContainsString('read from the database afresh every time', $content);
        $this->assertStringContainsString('a customer can reply between two of your answers', $content);
    }

    /**
     * The allowance is what makes the reservation honest: the chat is trimmed
     * to it instead of being reserved at whatever it happens to cost.
     */
    public function testTheAllowanceNeverEatsTheConversationsFloor()
    {
        $this->addThread('<div>Something to talk about.</div>');

        $total = 16000;
        $this->setSettings(['max_context_tokens' => $total]);

        $allowance = (new ContextBuilder($this->context()))->historyAllowance(1500);

        $this->assertGreaterThanOrEqual(0, $allowance);
        $this->assertLessThanOrEqual(
            (int) floor($total * HistoryWindow::share()),
            $allowance,
            'never more than the chat could have had before the floor existed'
        );

        // What the floor is for: after the chat and the tool schemas have taken
        // their share there is still room for the conversation itself.
        $fixed = TokenBudget::estimate((new ContextBuilder($this->context()))->build($allowance + 1500)['content']);

        $this->assertLessThan($total, $fixed, 'the assembled system message has to fit inside the budget');
    }

    /**
     * A budget too small for anything still spends what is left on the ticket
     * rather than on the chat.
     */
    public function testATinyBudgetStillLeavesTheChatSomethingAndNeverGoesNegative()
    {
        $this->setSettings(['max_context_tokens' => 500]);

        $this->assertSame(0, (new ContextBuilder($this->context()))->historyAllowance(2000));
    }

    /**
     * A message with attachments and no text used to disappear completely: the
     * body stripped to nothing, renderThread() returned '', and the loop
     * skipped it without counting it. The model was never told it existed.
     */
    public function testAMessageWithNoReadableTextIsStillShown()
    {
        $this->addThread('<div>Please see attached.</div>');

        $empty = $this->addThread('<div>&nbsp;</div>');
        $empty->has_attachments = true;
        $empty->save();

        $attachment = new \App\Attachment();
        $attachment->thread_id = $empty->id;
        $attachment->file_name = 'Rechnung.pdf';
        $attachment->mime_type = 'application/pdf';
        $attachment->type = \App\Attachment::TYPE_OTHER;
        $attachment->file_dir = '';
        $attachment->size = 1024;
        $attachment->embedded = false;
        $attachment->save();

        $content = (new ContextBuilder($this->context()))->build(0)['content'];

        $this->assertStringContainsString('Rechnung.pdf', $content);
        $this->assertStringContainsString('no readable text', $content);
    }

    /**
     * And when there is not even an attachment to show, the loss is reported
     * rather than left silent.
     */
    public function testAMessageWhoseTextStripsToNothingIsReported()
    {
        $this->addThread('<div>Something readable.</div>');
        $this->addThread('<div>&nbsp;</div>');

        $built = (new ContextBuilder($this->context()))->build(0);

        $this->assertTrue($built['truncated']);
        $this->assertStringContainsString('could not be read', $built['notice']);
    }

    /**
     * The floor is a share of the budget, clamped, so a bad edit can neither
     * switch the guarantee off nor starve everything else to honour it.
     */
    public function testTheFloorShareIsClamped()
    {
        \Config::set(AICHATPANEL_MODULE.'.thread_token_floor', 0);
        $this->assertSame(0.1, ContextBuilder::threadFloorShare());

        \Config::set(AICHATPANEL_MODULE.'.thread_token_floor', 5);
        $this->assertSame(0.6, ContextBuilder::threadFloorShare());

        \Config::set(AICHATPANEL_MODULE.'.thread_token_floor', ContextBuilder::THREAD_FLOOR_SHARE);
        $this->assertSame(0.25, ContextBuilder::threadFloorShare());
    }

    /**
     * End to end through the controller, which is where the two halves meet.
     *
     * ContextBuilder can only honour the floor if the caller windows the chat
     * to the allowance first; buildMessages() is the only caller that does, and
     * no other test reaches it. A chat this size used to reserve its way clean
     * through the budget and leave the assembled request with no conversation
     * in it at all.
     */
    public function testTheAssembledRequestStillCarriesTheConversationUnderALongChat()
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->addThread('<div>Message number '.$i.'. '.str_repeat('padding text ', 120).'</div>');
        }

        $this->setSettings(['max_context_tokens' => 8000]);

        $chat = Chat::findOrCreateFor($this->conversation->id, $this->agent->id);

        // Far more chat than the whole budget could hold.
        for ($i = 1; $i <= 60; $i++) {
            Message::create([
                'chat_id' => $chat->id,
                'role'    => Message::ROLE_USER,
                'body'    => 'Chat message '.$i.'. '.str_repeat('chatter ', 40),
            ]);

            Message::create([
                'chat_id' => $chat->id,
                'role'    => Message::ROLE_ASSISTANT,
                'body'    => 'Chat answer '.$i.'. '.str_repeat('chatter ', 40),
            ]);
        }

        $context = $this->context();

        $controller = new ChatController();
        // No setAccessible(): it has been a no-op since PHP 8.1 and raises a
        // deprecation from 8.5 on.
        $method = new \ReflectionMethod($controller, 'buildMessages');

        $assembled = $method->invoke($controller, $context, $chat, new ToolRegistry($context), '', 'reply');

        $system = $assembled['messages'][0];

        $this->assertEquals('system', $system['role']);
        $this->assertStringContainsString('Message number 12.', $system['content']);
        $this->assertStringContainsString('Latest message: customer_message', $system['content']);

        // And the chat really was trimmed rather than reserved at full size:
        // the whole request has to fit the budget it was assembled against.
        $this->assertLessThanOrEqual(
            8000,
            TokenBudget::estimate($this->contentOf($assembled['messages'])),
            'the assembled request must fit inside max_context_tokens'
        );

        $this->protocolIsValid($assembled['messages'], 'assembled request');
    }

    /**
     * Drafts and line items are still not history, floor or no floor.
     */
    public function testTheFloorDoesNotSmuggleDraftsIntoTheHistory()
    {
        $draft = $this->addThread('<div>unfinished draft</div>', Thread::TYPE_MESSAGE);
        $draft->state = Thread::STATE_DRAFT;
        $draft->save();

        $content = (new ContextBuilder($this->context()))->build(999999)['content'];

        $this->assertStringNotContainsString('unfinished draft', $content);
    }
}
