<?php

namespace Modules\AiChatPanel\Tests;

use App\Conversation;
use App\Thread;
use Modules\AiChatPanel\Services\ChangeCollector;
use Modules\AiChatPanel\Services\Llm\FakeLlmClient;
use Modules\AiChatPanel\Services\Agent\AgentLoop;
use Modules\AiChatPanel\Services\Tools\ToolRegistry;

/**
 * Making the conversation view update without a reload.
 *
 * The module does not build its own refresh channel. It records what a turn
 * changed and dispatches core's own App\Events\RealtimeConvNewThread, which
 * core renders, authorises and inserts. These tests cover the recording, the
 * broadcast, and the one rule that must not regress: nothing broadcasts unless
 * an AI turn armed the collector.
 */
class ConversationChangesTest extends AiChatPanelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setSettings([
            'tools_enabled' => [
                'conversation.create_draft_reply',
                'conversation.add_note',
                'conversation.set_status',
            ],
        ]);

        // A fresh collector per test: it is a singleton, and the container
        // survives between tests in the same process.
        app()->forgetInstance(ChangeCollector::class);
    }

    /**
     * @return ChangeCollector
     */
    protected function collector()
    {
        return ChangeCollector::instance();
    }

    /**
     * @return ToolRegistry
     */
    protected function registry()
    {
        return new ToolRegistry($this->context());
    }

    /**
     * @param string $tool
     * @param array  $arguments
     *
     * @return \Modules\AiChatPanel\Services\Tools\ToolResult
     */
    protected function runTool($tool, array $arguments)
    {
        return $this->registry()->execute($tool, \Helper::jsonEncodeSafe($arguments), [
            'confirmed' => true,
        ]);
    }

    /**
     * @param int|null $conversation_id
     *
     * @return \Illuminate\Support\Collection
     */
    protected function polycastRows($conversation_id = null)
    {
        $conversation_id = $conversation_id ?: $this->conversation->id;

        return \DB::table('polycast_events')
            ->where('event', 'App\Events\RealtimeConvNewThread')
            ->where('channels', 'like', '%"conv.'.$conversation_id.'"%')
            ->get();
    }

    // =====================================================================
    // Recording
    // =====================================================================

    public function testTheCollectorRecordsADraftCreatedByTheTool()
    {
        $this->actingAs($this->agent);
        $this->collector()->arm($this->conversation->id);

        $result = $this->runTool('conversation.create_draft_reply', ['body' => 'Sorry for the delay.']);

        $this->assertTrue($result->ok, 'The draft tool should have succeeded: '.$result->error);

        $draft = Thread::where('conversation_id', $this->conversation->id)
            ->where('state', Thread::STATE_DRAFT)
            ->first();

        $this->assertNotNull($draft);

        $changes = $this->collector()->snapshot();

        $this->assertNotNull($changes);
        $this->assertEquals($this->conversation->id, $changes['conversation_id']);
        $this->assertContains($draft->id, $changes['thread_ids']);
        $this->assertEquals($draft->id, $changes['draft_thread_id']);
    }

    /**
     * The test that justifies collecting through core's hooks rather than
     * having each tool report its own effects: SetStatusTool calls
     * Conversation::changeStatus(), and core creates the line-item thread
     * inside that method, so the tool never sees its id.
     */
    public function testTheCollectorRecordsTheLineItemAndTheNewStatus()
    {
        $this->actingAs($this->agent);
        $this->collector()->arm($this->conversation->id);

        $result = $this->runTool('conversation.set_status', ['status' => 'closed']);

        $this->assertTrue($result->ok, 'The status tool should have succeeded: '.$result->error);

        $line_item = Thread::where('conversation_id', $this->conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)
            ->first();

        $this->assertNotNull($line_item, 'changeStatus() should have written a line item.');

        $changes = $this->collector()->snapshot();

        $this->assertNotNull($changes);
        $this->assertContains($line_item->id, $changes['thread_ids']);
        $this->assertEquals(Conversation::STATUS_CLOSED, $changes['status']);
    }

    public function testTheCollectorRecordsANote()
    {
        $this->actingAs($this->agent);
        $this->collector()->arm($this->conversation->id);

        $result = $this->runTool('conversation.add_note', ['body' => 'Checked the order.']);

        $this->assertTrue($result->ok, 'The note tool should have succeeded: '.$result->error);

        $note = Thread::where('conversation_id', $this->conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->first();

        $this->assertNotNull($note);
        $this->assertContains($note->id, $this->collector()->snapshot()['thread_ids']);
    }

    /**
     * UpdateDraftTool saves an existing thread, so it fires thread.updated and
     * never thread.created. The browser also has to be told the difference:
     * core inserts a thread only when it is absent from the DOM
     * (main.js:3821) and has no path for one that changed.
     */
    public function testTheCollectorRecordsAnEditedDraftAsUpdated()
    {
        $this->actingAs($this->agent);
        $this->setSettings(['tools_enabled' => [
            'conversation.create_draft_reply',
            'conversation.update_draft',
        ]]);

        // Create the draft in a turn of its own, the way a real session does,
        // so the edit lands on a thread the browser has already seen.
        $this->collector()->arm($this->conversation->id);
        $this->runTool('conversation.create_draft_reply', ['body' => 'First attempt.']);

        $draft = Thread::where('conversation_id', $this->conversation->id)
            ->where('state', Thread::STATE_DRAFT)
            ->first();

        $this->assertNotNull($draft);

        // A fresh turn: disarm and re-arm, exactly as the next request would.
        app()->forgetInstance(ChangeCollector::class);
        $this->collector()->arm($this->conversation->id);

        $result = $this->runTool('conversation.update_draft', ['body' => 'Second attempt, better.']);

        $this->assertTrue($result->ok, 'The update tool should have succeeded: '.$result->error);

        $changes = $this->collector()->snapshot();

        $this->assertNotNull($changes, 'Editing a draft must produce a change set.');
        $this->assertContains($draft->id, $changes['thread_ids']);
        $this->assertContains($draft->id, $changes['updated_thread_ids']);
        $this->assertEquals($draft->id, $changes['draft_thread_id']);

        $this->assertStringContainsString('Second attempt', $draft->fresh()->body);
    }

    /**
     * A thread created and then edited within one turn is still a creation: the
     * browser has seen neither version, so core's insert path is correct for it
     * and marking it updated would make the module try to replace a node that
     * does not exist yet.
     */
    public function testAThreadCreatedAndEditedInOneTurnCountsAsCreated()
    {
        $this->actingAs($this->agent);
        $this->setSettings(['tools_enabled' => [
            'conversation.create_draft_reply',
            'conversation.update_draft',
        ]]);

        $this->collector()->arm($this->conversation->id);

        $this->runTool('conversation.create_draft_reply', ['body' => 'First attempt.']);
        $this->runTool('conversation.update_draft', ['body' => 'Second attempt, better.']);

        $changes = $this->collector()->snapshot();

        $this->assertArrayNotHasKey('updated_thread_ids', $changes);
        $this->assertCount(1, $changes['thread_ids']);
    }

    public function testAnEditedDraftBroadcastsSoOtherTabsSeeIt()
    {
        $this->actingAs($this->agent);
        $this->setSettings(['tools_enabled' => [
            'conversation.create_draft_reply',
            'conversation.update_draft',
        ]]);

        $this->collector()->arm($this->conversation->id);
        $this->runTool('conversation.create_draft_reply', ['body' => 'First attempt.']);

        $before = $this->polycastRows()->count();

        app()->forgetInstance(ChangeCollector::class);
        $this->collector()->arm($this->conversation->id);
        $this->runTool('conversation.update_draft', ['body' => 'Second attempt, better.']);

        $this->assertEquals($before + 1, $this->polycastRows()->count());
    }

    public function testTheCollectorIgnoresThreadsOnAnotherConversation()
    {
        $other = factory(Conversation::class)->create([
            'mailbox_id'  => $this->mailbox->id,
            'customer_id' => $this->customer->id,
            'state'       => Conversation::STATE_PUBLISHED,
            'status'      => Conversation::STATUS_ACTIVE,
        ]);

        $this->collector()->arm($this->conversation->id);

        $thread = new Thread();
        $thread->conversation_id = $other->id;
        $thread->type = Thread::TYPE_NOTE;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->status = Thread::STATUS_ACTIVE;
        $thread->body = 'somewhere else';
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->created_by_user_id = $this->agent->id;
        $thread->save();

        $this->assertNull($this->collector()->snapshot());
    }

    public function testTheCollectorIsInertWhenNotArmed()
    {
        $this->addThread('a customer message');

        $this->assertNull($this->collector()->snapshot());
        $this->assertNull($this->collector()->flush());
    }

    public function testFlushReturnsTheDeltaAndSnapshotTheWhole()
    {
        $this->actingAs($this->agent);
        $this->collector()->arm($this->conversation->id);

        $this->runTool('conversation.add_note', ['body' => 'first']);

        $first = $this->collector()->flush();

        $this->assertCount(1, $first['thread_ids']);

        $this->runTool('conversation.add_note', ['body' => 'second']);

        $second = $this->collector()->flush();

        $this->assertCount(1, $second['thread_ids'], 'flush() must report only what is new.');
        $this->assertNotEquals($first['thread_ids'][0], $second['thread_ids'][0]);

        $this->assertCount(2, $this->collector()->snapshot()['thread_ids']);
    }

    public function testFlushingTwiceWithNothingNewReturnsNull()
    {
        $this->actingAs($this->agent);
        $this->collector()->arm($this->conversation->id);

        $this->runTool('conversation.set_status', ['status' => 'closed']);

        $this->assertNotNull($this->collector()->flush());
        $this->assertNull($this->collector()->flush());
    }

    // =====================================================================
    // Broadcasting
    // =====================================================================

    public function testAnArmedWriteBroadcastsOnTheConversationChannel()
    {
        $this->actingAs($this->agent);
        $this->collector()->arm($this->conversation->id);

        $this->runTool('conversation.create_draft_reply', ['body' => 'Sorry for the delay.']);

        $draft = Thread::where('conversation_id', $this->conversation->id)
            ->where('state', Thread::STATE_DRAFT)
            ->first();

        $rows = $this->polycastRows();

        $this->assertCount(1, $rows, 'Exactly one RealtimeConvNewThread row for the draft.');

        $payload = json_decode($rows[0]->payload, true);

        $this->assertEquals($draft->id, $payload['thread_id']);
        $this->assertEquals($this->conversation->id, $payload['conversation_id']);
        $this->assertEquals($this->mailbox->id, $payload['mailbox_id']);

        // main.js:3813 drops the event when data.user_id matches the viewer, so
        // carrying it would hide the update from the person who asked for it.
        $this->assertArrayNotHasKey('user_id', $payload);
    }

    /**
     * The browser polls with `since` as its time cursor. If it were later than
     * the row's created_at the endpoint would filter the row out and the change
     * would never arrive; if it were much earlier the poll would redeliver old
     * events. It must sit just before the row.
     */
    public function testTheChangeSetCarriesATimeCursorJustBeforeTheBroadcast()
    {
        $this->actingAs($this->agent);
        $this->collector()->arm($this->conversation->id);

        $before = \Carbon\Carbon::now()->toDateTimeString();

        $this->runTool('conversation.add_note', ['body' => 'noted']);

        $changes = $this->collector()->snapshot();
        $row = $this->polycastRows()->first();

        $this->assertArrayHasKey('since', $changes);
        $this->assertNotNull($row);

        // created_at >= since is exactly the filter the receive endpoint applies
        // (core/app/Providers/PolycastServiceProvider.php).
        $this->assertGreaterThanOrEqual($changes['since'], $row->created_at);
        $this->assertGreaterThanOrEqual($before, $changes['since']);
    }

    /**
     * Core refuses to broadcast drafts because every user reply starts life as
     * one, written by the reply editor's autosave. Arming is what makes the
     * assistant's draft the exception; without it nothing may go out.
     */
    public function testAnUnarmedDraftBroadcastsNothing()
    {
        $thread = new Thread();
        $thread->conversation_id = $this->conversation->id;
        $thread->type = Thread::TYPE_MESSAGE;
        $thread->state = Thread::STATE_DRAFT;
        $thread->status = Thread::STATUS_ACTIVE;
        $thread->body = 'half-typed by a human';
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->created_by_user_id = $this->agent->id;
        $thread->save();

        $this->assertCount(0, $this->polycastRows());
    }

    public function testAStatusChangeAlsoBroadcastsForTheFolderCounters()
    {
        $this->actingAs($this->agent);
        $this->collector()->arm($this->conversation->id);

        $this->runTool('conversation.set_status', ['status' => 'closed']);

        $mailbox_rows = \DB::table('polycast_events')
            ->where('event', 'App\Events\RealtimeMailboxNewThread')
            ->where('channels', 'like', '%"mailbox.'.$this->mailbox->id.'"%')
            ->count();

        $this->assertGreaterThan(0, $mailbox_rows);
    }

    // =====================================================================
    // What the receiving browser gets
    // =====================================================================

    public function testCoreRendersTheDraftForAUserWhoMaySeeIt()
    {
        $this->actingAs($this->agent);
        $this->collector()->arm($this->conversation->id);

        $this->runTool('conversation.create_draft_reply', ['body' => 'Sorry for the delay.']);

        $draft = Thread::where('conversation_id', $this->conversation->id)
            ->where('state', Thread::STATE_DRAFT)
            ->first();

        $payload = \App\Events\RealtimeConvNewThread::processPayload((object) [
            'thread_id'       => $draft->id,
            'conversation_id' => $this->conversation->id,
            'mailbox_id'      => $this->mailbox->id,
        ]);

        $this->assertNotEmpty($payload);
        $this->assertStringContainsString('id="thread-'.$draft->id.'"', $payload->thread_html);
        $this->assertStringContainsString('thread-type-draft', $payload->thread_html);
        // The controls the module has to re-bind, because core binds them
        // non-delegated at page load.
        $this->assertStringContainsString('edit-draft-trigger', $payload->thread_html);
        $this->assertStringContainsString('discard-draft-trigger', $payload->thread_html);
    }

    public function testCoreRefusesToRenderTheDraftForAnOutsider()
    {
        $this->actingAs($this->agent);
        $this->collector()->arm($this->conversation->id);

        $this->runTool('conversation.create_draft_reply', ['body' => 'Sorry for the delay.']);

        $draft = Thread::where('conversation_id', $this->conversation->id)
            ->where('state', Thread::STATE_DRAFT)
            ->first();

        // conv.* is a PUBLIC polycast channel: the only thing standing between
        // a logged-in outsider and this thread's body is processPayload().
        $this->actingAs($this->outsider);

        $payload = \App\Events\RealtimeConvNewThread::processPayload((object) [
            'thread_id'       => $draft->id,
            'conversation_id' => $this->conversation->id,
            'mailbox_id'      => $this->mailbox->id,
        ]);

        $this->assertEmpty($payload);
    }

    // =====================================================================
    // Getting the change set to the browser
    // =====================================================================

    public function testTheLoopEmitsConversationChangedAfterToolResult()
    {
        $this->actingAs($this->agent);
        $this->collector()->arm($this->conversation->id);

        $this->setSettings([
            'write_tools_autorun' => ['conversation.add_note'],
        ]);

        $events = [];

        $client = (new FakeLlmClient())
            ->queueToolCall('conversation.add_note', ['body' => 'noted'])
            ->queueText('Done.');

        $context = $this->context();

        $loop = new AgentLoop($client, new ToolRegistry($context), $context, 'fake-model');
        $loop->setEmitter(function ($event, $payload) use (&$events) {
            $events[] = ['event' => $event, 'payload' => $payload];
        });

        $loop->run([
            ['role' => 'system', 'content' => 'system'],
            ['role' => 'user', 'content' => 'add a note'],
        ]);

        $names = array_column($events, 'event');

        $this->assertContains('conversation_changed', $names);
        $this->assertGreaterThan(
            array_search('tool_result', $names),
            array_search('conversation_changed', $names),
            'The change frame must follow the tool result, not precede it.'
        );

        $changed = $events[array_search('conversation_changed', $names)]['payload'];

        $this->assertEquals($this->conversation->id, $changed['conversation_id']);
        $this->assertNotEmpty($changed['thread_ids']);
    }

    public function testAReadOnlyTurnEmitsNoConversationChanged()
    {
        $this->actingAs($this->agent);
        $this->collector()->arm($this->conversation->id);

        $events = [];

        $client = (new FakeLlmClient())->queueText('Nothing to do.');

        $context = $this->context();

        $loop = new AgentLoop($client, new ToolRegistry($context), $context, 'fake-model');
        $loop->setEmitter(function ($event, $payload) use (&$events) {
            $events[] = $event;
        });

        $loop->run([
            ['role' => 'system', 'content' => 'system'],
            ['role' => 'user', 'content' => 'just answer'],
        ]);

        $this->assertNotContains('conversation_changed', $events);
    }

    /**
     * The approved write runs inside the confirm request. The follow-up
     * streaming request is a separate one whose collector starts empty, so if
     * the change set does not ride out on this response it never arrives.
     */
    public function testConfirmingAWriteReturnsTheChangeSetOnTheStreamHandshake()
    {
        $chat = $this->chatAwaitingWrite('conversation.create_draft_reply', ['body' => 'Sorry for the delay.']);

        $response = $this->actingAs($this->agent)->csrfPost('/aichatpanel/chat/confirm', [
            'conversation_id' => $this->conversation->id,
            'tool_call_id'    => 'call_pending',
            'approved'        => 1,
            'stream'          => 1,
        ]);

        $response->assertStatus(200);

        $draft = Thread::where('conversation_id', $this->conversation->id)
            ->where('state', Thread::STATE_DRAFT)
            ->first();

        $this->assertNotNull($draft, 'The approved write should have created the draft.');

        $changes = $response->json()['changes'];

        $this->assertNotNull($changes);
        $this->assertEquals($this->conversation->id, $changes['conversation_id']);
        $this->assertEquals($draft->id, $changes['draft_thread_id']);
    }

    public function testRejectingAWriteReturnsNoChanges()
    {
        $this->chatAwaitingWrite('conversation.create_draft_reply', ['body' => 'Sorry for the delay.']);

        $response = $this->actingAs($this->agent)->csrfPost('/aichatpanel/chat/confirm', [
            'conversation_id' => $this->conversation->id,
            'tool_call_id'    => 'call_pending',
            'approved'        => 0,
            'stream'          => 1,
        ]);

        $this->assertNull($response->json()['changes']);
    }

    /**
     * Put a chat into the "waiting for confirmation" state the way the loop
     * would, so the confirm route can be exercised without an LLM.
     *
     * @param string $tool
     * @param array  $arguments
     *
     * @return \Modules\AiChatPanel\Entities\Chat
     */
    protected function chatAwaitingWrite($tool, array $arguments)
    {
        $chat = \Modules\AiChatPanel\Entities\Chat::findOrCreateFor(
            $this->conversation->id,
            $this->agent->id
        );

        \Modules\AiChatPanel\Entities\Message::create([
            'chat_id' => $chat->id,
            'role'    => \Modules\AiChatPanel\Entities\Message::ROLE_USER,
            'body'    => 'please do it',
        ]);

        \Modules\AiChatPanel\Entities\Message::create([
            'chat_id'    => $chat->id,
            'role'       => \Modules\AiChatPanel\Entities\Message::ROLE_ASSISTANT,
            'body'       => '',
            'status'     => \Modules\AiChatPanel\Entities\Message::STATUS_PENDING,
            'tool_calls' => [[
                'id'        => 'call_pending',
                'name'      => $tool,
                'arguments' => \Helper::jsonEncodeSafe($arguments),
            ]],
        ]);

        return $chat;
    }
}
