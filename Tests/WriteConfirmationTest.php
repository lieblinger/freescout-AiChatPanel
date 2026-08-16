<?php

namespace Modules\AiChatPanel\Tests;

use App\Thread;
use Modules\AiChatPanel\Entities\Chat;
use Modules\AiChatPanel\Entities\Message;
use Modules\AiChatPanel\Entities\ToolCall;
use Modules\AiChatPanel\Services\Agent\AgentLoop;
use Modules\AiChatPanel\Services\Agent\AgentOutcome;
use Modules\AiChatPanel\Services\Llm\FakeLlmClient;
use Modules\AiChatPanel\Services\Tools\ToolRegistry;
use Modules\AiChatPanel\Tests\Support\EchoTool;
use Modules\AiChatPanel\Tests\Support\WriteTool;

/**
 * The confirmation flow, from the loop pausing to the user answering.
 */
class WriteConfirmationTest extends AiChatPanelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        EchoTool::$calls = 0;
        WriteTool::$calls = 0;

        \Eventy::addFilter(ToolRegistry::FILTER, function ($tools) {
            $tools[] = new EchoTool();
            $tools[] = new WriteTool();

            return $tools;
        }, 20, 2);

        $this->setSettings([
            'tools_enabled' => ['test.echo', 'test.write', 'conversation_add_note'],
        ]);
    }

    protected function tearDown(): void
    {
        \Eventy::removeAllFilters(ToolRegistry::FILTER);

        parent::tearDown();
    }

    /**
     * @param FakeLlmClient $client
     *
     * @return AgentLoop
     */
    protected function loop(FakeLlmClient $client)
    {
        $context = $this->context();

        return new AgentLoop($client, new ToolRegistry($context), $context, 'fake-model');
    }

    /**
     * @return array
     */
    protected function messages()
    {
        return [
            ['role' => 'system', 'content' => 'system'],
            ['role' => 'user', 'content' => 'do the thing'],
        ];
    }

    public function testTheLoopPausesOnAWriteToolAndRunsNothing()
    {
        $client = (new FakeLlmClient())->queueToolCall('test.write', ['value' => 'danger']);

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_AWAITING_CONFIRMATION, $outcome->status);
        $this->assertNotNull($outcome->pending);
        $this->assertEquals('test.write', $outcome->pending->tool);
        $this->assertEquals(['value' => 'danger'], $outcome->pending->arguments);
        $this->assertEquals(0, WriteTool::$calls, 'Nothing may run before the user answers.');

        // Exactly one request: the loop stopped rather than carrying on.
        $this->assertCount(1, $client->payloads);
    }

    public function testTheConfirmationLabelDescribesTheEffect()
    {
        $client = (new FakeLlmClient())->queueToolCall('test.write', ['value' => 'danger']);

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertStringContainsString('danger', $outcome->pending->label);
    }

    public function testReadToolsInTheSameTurnStillRunBeforeThePause()
    {
        $client = (new FakeLlmClient())->queueToolCalls([
            ['name' => 'test.echo', 'arguments' => ['text' => 'first']],
            ['name' => 'test.write', 'arguments' => ['value' => 'second']],
        ]);

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_AWAITING_CONFIRMATION, $outcome->status);
        $this->assertEquals(1, EchoTool::$calls, 'The read tool should have run.');
        $this->assertEquals(0, WriteTool::$calls);
        $this->assertEquals('test.write', $outcome->pending->tool);
    }

    public function testASecondWriteInTheSameTurnIsDeferredNotQueued()
    {
        $client = (new FakeLlmClient())->queueToolCalls([
            ['name' => 'test.write', 'arguments' => ['value' => 'one']],
            ['name' => 'test.write', 'arguments' => ['value' => 'two']],
        ]);

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_AWAITING_CONFIRMATION, $outcome->status);
        $this->assertEquals('one', $outcome->pending->arguments['value']);

        // The endpoint requires every tool_call id to be answered, so the
        // second one gets a result saying it was not executed.
        $tool_turns = array_values(array_filter($outcome->turns, function ($turn) {
            return $turn['role'] === Message::ROLE_TOOL;
        }));

        $this->assertCount(1, $tool_turns);
        $this->assertStringContainsString('Not executed', $tool_turns[0]['body']);
        $this->assertEquals(0, WriteTool::$calls);
    }

    public function testAnAutorunWriteDoesNotPause()
    {
        $this->setSettings(['write_tools_autorun' => ['test.write']]);

        $client = (new FakeLlmClient())
            ->queueToolCall('test.write', ['value' => 'fine'])
            ->queueText('Done.');

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_COMPLETE, $outcome->status);
        $this->assertEquals(1, WriteTool::$calls);
    }

    // -----------------------------------------------------------------------
    // Over HTTP, the way the panel does it
    // -----------------------------------------------------------------------

    /**
     * Put a chat into the "waiting for confirmation" state the way the loop
     * would, so the confirm route can be exercised on its own.
     *
     * @param string $tool
     * @param array  $arguments
     *
     * @return Chat
     */
    protected function chatAwaitingWrite($tool = 'conversation_add_note', array $arguments = ['body' => 'from the model'])
    {
        $chat = Chat::findOrCreateFor($this->conversation->id, $this->agent->id);

        Message::create([
            'chat_id' => $chat->id,
            'role'    => Message::ROLE_USER,
            'body'    => 'please do it',
        ]);

        Message::create([
            'chat_id'    => $chat->id,
            'role'       => Message::ROLE_ASSISTANT,
            'body'       => '',
            'status'     => Message::STATUS_PENDING,
            'tool_calls' => [[
                'id'        => 'call_pending',
                'name'      => $tool,
                'arguments' => \Helper::jsonEncodeSafe($arguments),
            ]],
        ]);

        return $chat;
    }

    public function testHistoryReportsThePendingWrite()
    {
        $this->chatAwaitingWrite();

        $response = $this->actingAs($this->agent)
            ->csrfPost('/aichatpanel/chat/history', ['conversation_id' => $this->conversation->id]);

        $response->assertStatus(200);

        $pending = $response->json()['pending'];

        $this->assertNotNull($pending);
        $this->assertEquals('conversation_add_note', $pending['tool']);
        $this->assertEquals('from the model', $pending['arguments']['body']);
        $this->assertNotEmpty($pending['label']);
    }

    public function testSendingANewMessageIsRefusedWhileAWriteIsPending()
    {
        $this->chatAwaitingWrite();

        $response = $this->actingAs($this->agent)->csrfPost('/aichatpanel/chat/send', [
            'conversation_id' => $this->conversation->id,
            'message'         => 'never mind',
        ]);

        $this->assertEquals('error', $response->json()['status']);
    }

    public function testRejectingAWriteRecordsItAndRunsNothing()
    {
        $this->chatAwaitingWrite();

        $before = Thread::where('conversation_id', $this->conversation->id)
            ->where('type', Thread::TYPE_NOTE)->count();

        $this->actingAs($this->agent)->csrfPost('/aichatpanel/chat/confirm', [
            'conversation_id' => $this->conversation->id,
            'tool_call_id'    => 'call_pending',
            'approved'        => 0,
        ]);

        $after = Thread::where('conversation_id', $this->conversation->id)
            ->where('type', Thread::TYPE_NOTE)->count();

        $this->assertEquals($before, $after, 'A rejected write must not touch the conversation.');

        $audit = ToolCall::where('tool', 'conversation_add_note')->first();
        $this->assertNotNull($audit);
        $this->assertEquals(ToolCall::STATUS_REJECTED, $audit->status);

        // The rejection is fed back to the model as a tool result.
        $tool_message = Message::where('tool_call_id', 'call_pending')->first();
        $this->assertNotNull($tool_message);
        $this->assertStringContainsString('rejected', $tool_message->body);
    }

    public function testApprovingAWriteExecutesItWithTheStoredArguments()
    {
        $this->chatAwaitingWrite('conversation_add_note', ['body' => 'the approved note']);

        $this->actingAs($this->agent)->csrfPost('/aichatpanel/chat/confirm', [
            'conversation_id' => $this->conversation->id,
            'tool_call_id'    => 'call_pending',
            // A client trying to smuggle different arguments in. They are
            // ignored: the server reads them from the stored assistant turn.
            'arguments'       => ['body' => 'something else entirely'],
            'approved'        => 1,
        ]);

        $note = Thread::where('conversation_id', $this->conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($note, 'The approved note should have been created.');
        $this->assertStringContainsString('the approved note', $note->body);
        $this->assertStringNotContainsString('something else entirely', $note->body);
        $this->assertEquals($this->agent->id, $note->created_by_user_id, 'The write runs as the acting user.');

        $audit = ToolCall::where('tool', 'conversation_add_note')->first();
        $this->assertEquals(ToolCall::STATUS_OK, $audit->status);
    }

    public function testAUserWithoutUpdateRightsCannotApproveAWrite()
    {
        $this->chatAwaitingWrite();

        // Only-assigned-tickets, and the conversation belongs to someone else.
        $this->conversation->user_id = $this->admin->id;
        $this->conversation->save();

        $this->agent->addPermission(\App\User::PERM_ONLY_ASSIGNED_TICKETS);

        $response = $this->actingAs($this->agent)->csrfPost('/aichatpanel/chat/confirm', [
            'conversation_id' => $this->conversation->id,
            'tool_call_id'    => 'call_pending',
            'approved'        => 1,
        ]);

        // Denied at the route: the user cannot even see the conversation now.
        $response->assertStatus(403);

        $this->assertEquals(
            0,
            Thread::where('conversation_id', $this->conversation->id)->where('type', Thread::TYPE_NOTE)->count()
        );
    }
}
