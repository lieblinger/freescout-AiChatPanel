<?php

namespace Modules\AiChatPanel\Tests;

use Modules\AiChatPanel\Entities\Message;
use Modules\AiChatPanel\Entities\ToolCall;
use Modules\AiChatPanel\Services\Agent\AgentLoop;
use Modules\AiChatPanel\Services\Agent\AgentOutcome;
use Modules\AiChatPanel\Services\Llm\FakeLlmClient;
use Modules\AiChatPanel\Services\Llm\LlmException;
use Modules\AiChatPanel\Services\Tools\ToolRegistry;
use Modules\AiChatPanel\Tests\Support\EchoTool;
use Modules\AiChatPanel\Tests\Support\ExplodingTool;
use Modules\AiChatPanel\Tests\Support\FailingTool;
use Modules\AiChatPanel\Tests\Support\WriteTool;

/**
 * The agent loop, driven entirely by a scripted client.
 */
class AgentLoopTest extends AiChatPanelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        EchoTool::$calls = 0;
        WriteTool::$calls = 0;

        // Register the test tools the way a third-party module would.
        \Eventy::addFilter(ToolRegistry::FILTER, function ($tools) {
            $tools[] = new EchoTool();
            $tools[] = new FailingTool();
            $tools[] = new ExplodingTool();
            $tools[] = new WriteTool();

            return $tools;
        }, 20, 2);

        $this->setSettings([
            'tools_enabled' => ['test.echo', 'test.fail', 'test.explode', 'test.write'],
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
    protected function messages($text = 'hello')
    {
        return [
            ['role' => 'system', 'content' => 'system prompt'],
            ['role' => 'user', 'content' => $text],
        ];
    }

    public function testPlainAnswerNeedsOneRequest()
    {
        $client = (new FakeLlmClient())->queueText('Hello there.');

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_COMPLETE, $outcome->status);
        $this->assertEquals(1, $outcome->iterations);
        $this->assertCount(1, $client->payloads);
        $this->assertEquals('Hello there.', $outcome->finalTurn()['body']);
    }

    public function testSingleToolCallThenAnswer()
    {
        $client = (new FakeLlmClient())
            ->queueToolCall('test.echo', ['text' => 'abc'])
            ->queueText('It said abc.');

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_COMPLETE, $outcome->status);
        $this->assertEquals(1, EchoTool::$calls);
        $this->assertCount(2, $client->payloads);

        // assistant(tool_calls) + tool result + assistant(answer)
        $this->assertCount(3, $outcome->turns);
        $this->assertEquals(Message::ROLE_TOOL, $outcome->turns[1]['role']);
        $this->assertStringContainsString('abc', $outcome->turns[1]['body']);
        $this->assertEquals('It said abc.', $outcome->finalTurn()['body']);

        // The second request must replay the assistant turn AND answer its call.
        $second = $client->payload(1)['messages'];
        $roles = array_column($second, 'role');
        $this->assertEquals(['system', 'user', 'assistant', 'tool'], $roles);
    }

    public function testChainedToolCalls()
    {
        $client = (new FakeLlmClient())
            ->queueToolCall('test.echo', ['text' => 'one'])
            ->queueToolCall('test.echo', ['text' => 'two'])
            ->queueText('Done.');

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_COMPLETE, $outcome->status);
        $this->assertEquals(2, EchoTool::$calls);
        $this->assertEquals(3, $outcome->iterations);
        $this->assertEquals('Done.', $outcome->finalTurn()['body']);
    }

    public function testIterationCapStopsTheLoop()
    {
        $this->setSettings(['max_tool_iterations' => 2]);

        // The model would keep calling tools forever.
        $client = new FakeLlmClient();
        for ($i = 0; $i < 10; $i++) {
            $client->queueToolCall('test.echo', ['text' => 'x']);
        }

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_COMPLETE, $outcome->status);
        $this->assertEquals(2, $outcome->iterations, 'The cap must stop the loop.');
        $this->assertEquals(2, EchoTool::$calls);
        $this->assertNotEmpty($outcome->notices, 'Hitting the cap has to be visible to the user.');
    }

    public function testMalformedArgumentsAreReportedToTheModel()
    {
        $client = (new FakeLlmClient())
            // Not valid JSON at all.
            ->queueToolCall('test.echo', '{"text": ')
            ->queueText('I will try again.');

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_COMPLETE, $outcome->status);
        $this->assertEquals(0, EchoTool::$calls, 'A malformed call must not execute.');

        $tool_turn = $outcome->turns[1];
        $this->assertEquals(Message::ROLE_TOOL, $tool_turn['role']);
        $this->assertStringContainsString('not valid JSON', $tool_turn['body']);
    }

    public function testSchemaViolationIsReportedToTheModel()
    {
        $client = (new FakeLlmClient())
            // 'text' is required.
            ->queueToolCall('test.echo', ['times' => 2])
            ->queueText('Sorry.');

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(0, EchoTool::$calls);
        $this->assertStringContainsString('Invalid arguments', $outcome->turns[1]['body']);
        $this->assertStringContainsString('required', $outcome->turns[1]['body']);
    }

    public function testUnknownToolNameIsReportedToTheModel()
    {
        $client = (new FakeLlmClient())
            ->queueToolCall('does.not.exist', ['a' => 1])
            ->queueText('Understood.');

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_COMPLETE, $outcome->status);
        $this->assertStringContainsString('Unknown tool', $outcome->turns[1]['body']);
    }

    public function testToolExceptionBecomesAStructuredError()
    {
        $client = (new FakeLlmClient())
            ->queueToolCall('test.fail')
            ->queueText('OK, nothing found.');

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_COMPLETE, $outcome->status);
        $this->assertStringContainsString('Nothing matched your query', $outcome->turns[1]['body']);

        $audit = ToolCall::where('tool', 'test.fail')->first();
        $this->assertNotNull($audit);
        $this->assertEquals(ToolCall::STATUS_FAILED, $audit->status);
    }

    public function testUnexpectedExceptionDoesNotLeakInternalsToTheModel()
    {
        $client = (new FakeLlmClient())
            ->queueToolCall('test.explode')
            ->queueText('That did not work.');

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_COMPLETE, $outcome->status);
        $this->assertStringNotContainsString('boom', $outcome->turns[1]['body']);
        $this->assertStringNotContainsString('internal detail', $outcome->turns[1]['body']);
        $this->assertStringContainsString('failed to run', $outcome->turns[1]['body']);
    }

    public function testEndpointFailureIsReportedAsATypedError()
    {
        $client = (new FakeLlmClient())->queueException(
            new LlmException(LlmException::TYPE_TIMEOUT, 'timed out')
        );

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_ERROR, $outcome->status);
        $this->assertEquals(LlmException::TYPE_TIMEOUT, $outcome->error_type);
        $this->assertNotEmpty($outcome->error);
    }

    public function testEndpointRejectingToolsFallsBackToAnAnswerWithoutThem()
    {
        $client = (new FakeLlmClient())
            ->queueException(new LlmException(LlmException::TYPE_TOOLS_UNSUPPORTED, 'no tools', 400))
            ->queueText('Answered without tools.');

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_COMPLETE, $outcome->status);
        $this->assertEquals('Answered without tools.', $outcome->finalTurn()['body']);
        $this->assertNotEmpty($outcome->notices);

        // The retry must not carry the tools parameter.
        $this->assertArrayNotHasKey('tools', $client->payload(1));

        // And the model is remembered as unable to use them.
        $this->assertFalse(\Modules\AiChatPanel\Services\Settings::modelSupportsTools('fake-model'));
    }

    public function testTruncatedResponseProducesANotice()
    {
        $response = new \Modules\AiChatPanel\Services\Llm\ChatResponse();
        $response->content = 'Half an ans';
        $response->finish_reason = 'length';

        $client = (new FakeLlmClient())->queueResponse($response);

        $outcome = $this->loop($client)->run($this->messages());

        $this->assertEquals(AgentOutcome::STATUS_COMPLETE, $outcome->status);
        $this->assertNotEmpty($outcome->notices);
        $this->assertStringContainsString('token limit', implode(' ', $outcome->notices));
    }

    public function testReasoningIsNeverReplayedToTheModel()
    {
        $response = new \Modules\AiChatPanel\Services\Llm\ChatResponse();
        $response->content = '';
        $response->reasoning = 'Let me think about this at length.';
        $response->finish_reason = 'tool_calls';
        $response->tool_calls = [[
            'id' => 'call_1', 'name' => 'test.echo', 'arguments' => '{"text":"z"}',
        ]];

        $client = (new FakeLlmClient())->queueResponse($response)->queueText('Done.');

        $this->loop($client)->run($this->messages());

        $replayed = \Helper::jsonEncodeSafe($client->payload(1)['messages']);
        $this->assertStringNotContainsString('think about this at length', $replayed);
    }
}
