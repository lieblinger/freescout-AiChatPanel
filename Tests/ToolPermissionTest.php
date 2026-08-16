<?php

namespace Modules\AiChatPanel\Tests;

use Modules\AiChatPanel\Entities\ToolCall;
use Modules\AiChatPanel\Services\Tools\ToolRegistry;
use Modules\AiChatPanel\Tests\Support\EchoTool;
use Modules\AiChatPanel\Tests\Support\ForbiddenTool;
use Modules\AiChatPanel\Tests\Support\WriteTool;

/**
 * The registry decides what the model may see and what may run.
 *
 * The rule under test throughout: a tool the user is not allowed to run must be
 * invisible in the request payload AND rejected if it is called anyway. Only
 * checking the first is the classic mistake, because the model's tool call is
 * just as untrusted as anything else coming back over the wire.
 */
class ToolPermissionTest extends AiChatPanelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        EchoTool::$calls = 0;
        ForbiddenTool::$calls = 0;
        WriteTool::$calls = 0;

        \Eventy::addFilter(ToolRegistry::FILTER, function ($tools) {
            $tools[] = new EchoTool();
            $tools[] = new ForbiddenTool();
            $tools[] = new WriteTool();

            return $tools;
        }, 20, 2);

        $this->setSettings([
            'tools_enabled' => ['test.echo', 'test.forbidden', 'test.write'],
        ]);
    }

    protected function tearDown(): void
    {
        \Eventy::removeAllFilters(ToolRegistry::FILTER);

        parent::tearDown();
    }

    /**
     * @return ToolRegistry
     */
    protected function registry()
    {
        return new ToolRegistry($this->context());
    }

    public function testAForbiddenToolIsNeverPutInTheRequestPayload()
    {
        $names = array_column(
            array_column($this->registry()->toApiDefinitions(), 'function'),
            'name'
        );

        $this->assertContains('test_echo', $names);
        $this->assertNotContains('test_forbidden', $names, 'An unauthorised tool must not be offered to the model.');
    }

    public function testAToolNameIsSanitisedBeforeItGoesOnTheWire()
    {
        // OpenAI and Anthropic require ^[a-zA-Z0-9_-]{1,64}$ and reject the
        // whole request — every tool, not just the offending one — when a name
        // does not match. Local endpoints accept anything, which is how dotted
        // names survived this long.
        $names = array_column(
            array_column($this->registry()->toApiDefinitions(), 'function'),
            'name'
        );

        foreach ($names as $name) {
            $this->assertMatchesRegularExpression(
                '/^[a-zA-Z0-9_-]{1,64}$/',
                $name,
                $name.' is not a valid function name.'
            );
        }

        $this->assertNotEmpty($names);
    }

    public function testAToolCalledByItsSanitisedNameStillResolves()
    {
        // The model answers with the name it was given, not the internal one.
        $result = $this->registry()->execute('test_echo', '{"text":"hi"}');

        $this->assertTrue($result->ok);
        $this->assertEquals(1, EchoTool::$calls);
    }

    public function testTwoNamesThatSanitiseAlikeStayDistinct()
    {
        // "test.echo" and "test_echo" both sanitise to "test_echo"; routing one
        // tool's calls to the other would be worse than dropping it.
        \Eventy::addFilter(ToolRegistry::FILTER, function ($tools) {
            $tools[] = new Support\EchoTool2();

            return $tools;
        }, 30, 2);

        $this->setSettings([
            'tools_enabled' => ['test.echo', 'test_echo', 'test.forbidden', 'test.write'],
        ]);

        $names = array_column(
            array_column($this->registry()->toApiDefinitions(), 'function'),
            'name'
        );

        $this->assertCount(count(array_unique($names)), $names, 'Two tools must never share a wire name.');
        $this->assertContains('test_echo', $names);
        $this->assertContains('test_echo_2', $names);
    }

    public function testSettingsWrittenBeforeTheBuiltinsWereRenamedStillWork()
    {
        // An existing install has the old dotted names stored in tools_enabled.
        // Reading them literally would turn every configured tool off on upgrade.
        $this->setSettings([
            'tools_enabled' => ['conversation.get', 'customer.get'],
        ]);

        $names = array_keys($this->registry()->available());

        $this->assertContains('conversation_get', $names);
        $this->assertContains('customer_get', $names);
    }

    public function testAForbiddenToolIsRejectedWhenCalledAnyway()
    {
        // The model can name any tool it likes; the server decides.
        $result = $this->registry()->execute('test.forbidden', '{}');

        $this->assertFalse($result->ok);
        $this->assertEquals(0, ForbiddenTool::$calls, 'The handler must never run.');
    }

    public function testADisabledToolIsIndistinguishableFromAMissingOne()
    {
        $this->setSettings(['tools_enabled' => ['test.echo']]);

        $disabled = $this->registry()->execute('test.write', '{"value":"x"}');
        $missing = $this->registry()->execute('does.not.exist', '{}');

        $this->assertFalse($disabled->ok);
        $this->assertFalse($missing->ok);

        // Same wording apart from the name the model used, so the answer never
        // reveals that the tool exists but is turned off.
        $this->assertStringStartsWith('Unknown tool', $disabled->error);
        $this->assertStringStartsWith('Unknown tool', $missing->error);
        $this->assertEquals(
            str_replace('test.write', 'NAME', $disabled->error),
            str_replace('does.not.exist', 'NAME', $missing->error)
        );
        $this->assertStringNotContainsString('disabled', $disabled->error);

        $this->assertEquals(0, WriteTool::$calls);
    }

    public function testWriteToolsDisappearWhenTheMasterSwitchIsOff()
    {
        $this->setSettings(['write_tools_enabled' => false]);

        $names = array_keys($this->registry()->available());

        $this->assertContains('test.echo', $names);
        $this->assertNotContains('test.write', $names);

        $result = $this->registry()->execute('test.write', '{"value":"x"}', ['confirmed' => true]);

        $this->assertFalse($result->ok, 'The master switch must hold even for a confirmed call.');
        $this->assertEquals(0, WriteTool::$calls);
    }

    public function testAnUnconfirmedWriteToolDoesNotExecute()
    {
        $result = $this->registry()->execute('test.write', '{"value":"x"}', ['confirmed' => false]);

        $this->assertFalse($result->ok);
        $this->assertEquals(0, WriteTool::$calls, 'A write must not run without confirmation.');

        $audit = ToolCall::where('tool', 'test.write')->first();
        $this->assertNotNull($audit, 'A blocked write must still be audited.');
        $this->assertEquals(ToolCall::STATUS_DENIED, $audit->status);
    }

    public function testAConfirmedWriteToolExecutes()
    {
        $result = $this->registry()->execute('test.write', '{"value":"x"}', ['confirmed' => true]);

        $this->assertTrue($result->ok);
        $this->assertEquals(1, WriteTool::$calls);

        $audit = ToolCall::where('tool', 'test.write')->first();
        $this->assertEquals(ToolCall::STATUS_OK, $audit->status);
        $this->assertEquals(ToolCall::MODE_WRITE, $audit->mode);
    }

    public function testAnAutorunListedWriteToolRunsWithoutConfirmation()
    {
        $this->setSettings(['write_tools_autorun' => ['test.write']]);

        $result = $this->registry()->execute('test.write', '{"value":"x"}', ['confirmed' => false]);

        $this->assertTrue($result->ok);
        $this->assertEquals(1, WriteTool::$calls);
    }

    public function testTheDraftReplyToolCanNeverBeAutorun()
    {
        // Even if an administrator manages to get it into the list.
        $this->setSettings(['write_tools_autorun' => ['conversation_create_draft_reply']]);

        $registry = $this->registry();
        $tool = new \Modules\AiChatPanel\Services\Tools\Builtin\CreateDraftReplyTool();

        $this->assertFalse(
            $registry->mayAutoRun($tool),
            'Drafting a customer-facing message must always be confirmed.'
        );
    }

    public function testEveryToolExecutionIsAudited()
    {
        $this->registry()->execute('test.echo', '{"text":"hi"}');

        $audit = ToolCall::where('tool', 'test.echo')->first();

        $this->assertNotNull($audit);
        $this->assertEquals($this->agent->id, $audit->user_id);
        $this->assertEquals($this->conversation->id, $audit->conversation_id);
        $this->assertEquals(ToolCall::MODE_READ, $audit->mode);
        $this->assertEquals(ToolCall::STATUS_OK, $audit->status);
        $this->assertEquals(['text' => 'hi'], $audit->arguments);
        $this->assertNotNull($audit->duration_ms);
    }

    public function testAToolWithAnInvalidNameIsIgnored()
    {
        \Eventy::addFilter(ToolRegistry::FILTER, function ($tools) {
            $tools[] = new class extends \Modules\AiChatPanel\Services\Tools\AbstractTool {
                public function name()
                {
                    // Spaces are not allowed in an OpenAI function name; if this
                    // reached the payload the whole request would fail, not just
                    // this tool.
                    return 'not a valid name!';
                }

                public function description()
                {
                    return 'Invalid.';
                }

                public function parameters()
                {
                    return $this->noParameters();
                }

                public function handle(array $arguments, \Modules\AiChatPanel\Services\PanelContext $context)
                {
                    return \Modules\AiChatPanel\Services\Tools\ToolResult::ok();
                }
            };

            return $tools;
        }, 30, 2);

        $names = array_keys($this->registry()->all());

        $this->assertNotContains('not a valid name!', $names);
        $this->assertContains('test.echo', $names);
    }

    public function testSomethingThatIsNotAToolIsIgnored()
    {
        \Eventy::addFilter(ToolRegistry::FILTER, function ($tools) {
            $tools[] = new \stdClass();
            $tools[] = 'just a string';

            return $tools;
        }, 30, 2);

        // A badly behaved module must not take the panel down with it.
        $names = array_keys($this->registry()->all());

        $this->assertContains('test.echo', $names);
    }
}
