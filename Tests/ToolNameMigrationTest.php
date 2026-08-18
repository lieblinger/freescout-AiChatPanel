<?php

namespace Modules\AiChatPanel\Tests;

use Modules\AiChatPanel\Entities\Chat;
use Modules\AiChatPanel\Entities\Message;
use Modules\AiChatPanel\Services\Settings;

require_once __DIR__.'/../Database/Migrations/2026_08_18_000005_canonicalise_aichatpanel_tool_names.php';

/**
 * The migration that renames the builtins inside stored chat history.
 *
 * The names matter after the fact because the history is replayed to the model
 * on every later turn: a chat holding the pre-1.3.0 spelling teaches the model
 * to ask for a tool that no longer answers to that name.
 */
class ToolNameMigrationTest extends AiChatPanelTestCase
{
    /**
     * @return Chat
     */
    protected function chat()
    {
        return Chat::findOrCreateFor($this->conversation->id, $this->agent->id);
    }

    /**
     * @return void
     */
    protected function migrate()
    {
        (new \CanonicaliseAichatpanelToolNames())->up();
    }

    public function testAPreRenameToolCallIsRenamed()
    {
        $chat = $this->chat();

        $assistant = Message::create([
            'chat_id'    => $chat->id,
            'role'       => Message::ROLE_ASSISTANT,
            'body'       => '',
            'tool_calls' => [[
                'id'        => 'call_1',
                'name'      => 'conversation.create_draft_reply',
                'arguments' => '{"body":"Viele Grüße"}',
            ]],
        ]);

        $result = Message::create([
            'chat_id'      => $chat->id,
            'role'         => Message::ROLE_TOOL,
            'body'         => '{"ok":true,"data":{"thread_id":117}}',
            'tool_call_id' => 'call_1',
            'tool_name'    => 'conversation.create_draft_reply',
            'status'       => Message::STATUS_OK,
        ]);

        $this->migrate();

        $calls = $assistant->fresh()->tool_calls;

        $this->assertEquals('conversation_create_draft_reply', $calls[0]['name']);
        $this->assertEquals('call_1', $calls[0]['id']);
        $this->assertEquals('{"body":"Viele Grüße"}', $calls[0]['arguments'], 'The arguments must survive re-encoding.');

        $this->assertEquals('conversation_create_draft_reply', $result->fresh()->tool_name);
    }

    public function testTheRenamedHistoryIsWhatGetsReplayedToTheModel()
    {
        $chat = $this->chat();

        Message::create([
            'chat_id'    => $chat->id,
            'role'       => Message::ROLE_ASSISTANT,
            'body'       => '',
            'tool_calls' => [
                ['id' => 'call_1', 'name' => 'conversation.get', 'arguments' => '{}'],
                ['id' => 'call_2', 'name' => 'customer.get', 'arguments' => '{}'],
            ],
        ]);

        Message::create([
            'chat_id'      => $chat->id,
            'role'         => Message::ROLE_TOOL,
            'body'         => '{"ok":true}',
            'tool_call_id' => 'call_1',
            'tool_name'    => 'conversation.get',
            'status'       => Message::STATUS_OK,
        ]);

        Message::create([
            'chat_id'      => $chat->id,
            'role'         => Message::ROLE_TOOL,
            'body'         => '{"ok":true}',
            'tool_call_id' => 'call_2',
            'tool_name'    => 'customer.get',
            'status'       => Message::STATUS_OK,
        ]);

        $this->migrate();

        $replayed = $chat->fresh()->toApiMessages();
        $assistant = null;

        foreach ($replayed as $message) {
            if ($message['role'] === 'assistant' && !empty($message['tool_calls'])) {
                $assistant = $message;
            }
        }

        $this->assertNotNull($assistant);
        $this->assertEquals(
            ['conversation_get', 'customer_get'],
            array_column(array_column($assistant['tool_calls'], 'function'), 'name')
        );
    }

    public function testAToolThatWasNeverRenamedIsLeftAlone()
    {
        $chat = $this->chat();

        $assistant = Message::create([
            'chat_id'    => $chat->id,
            'role'       => Message::ROLE_ASSISTANT,
            'body'       => '',
            'tool_calls' => [[
                'id'        => 'call_1',
                // A third-party tool. The map only covers the eight builtins,
                // and renaming anything else would break that module's calls.
                'name'      => 'acme.do_thing',
                'arguments' => '{}',
            ]],
        ]);

        $result = Message::create([
            'chat_id'      => $chat->id,
            'role'         => Message::ROLE_TOOL,
            'body'         => '{"ok":true}',
            'tool_call_id' => 'call_1',
            'tool_name'    => 'acme.do_thing',
            'status'       => Message::STATUS_OK,
        ]);

        $this->migrate();

        $calls = $assistant->fresh()->tool_calls;

        $this->assertEquals('acme.do_thing', $calls[0]['name']);
        $this->assertEquals('acme.do_thing', $result->fresh()->tool_name);
    }

    public function testThePreRenameNamesInTheSettingsAreRenamed()
    {
        // The panel copes with either spelling, but the settings screen ticks a
        // box by comparing the stored name with the tool's current one: left as
        // they were, every tool showed unticked and saving that page would have
        // turned them all off.
        $this->setSettings([
            'tools_enabled'       => ['conversation.get', 'customer.get', 'acme.do_thing'],
            'write_tools_autorun' => ['conversation.add_note'],
        ]);

        $this->migrate();

        \Option::$cache = [];

        $this->assertEquals(
            ['conversation_get', 'customer_get', 'acme.do_thing'],
            Settings::get('tools_enabled')
        );
        $this->assertEquals(['conversation_add_note'], Settings::get('write_tools_autorun'));
    }

    public function testAMailboxsOwnToolListIsRenamedToo()
    {
        Settings::setMailboxMeta($this->mailbox, [
            'tools_enabled' => ['conversation.get', 'conversation_add_note'],
        ]);

        $this->migrate();

        $this->assertEquals(
            ['conversation_get', 'conversation_add_note'],
            Settings::get('tools_enabled', $this->mailbox->fresh())
        );
    }

    public function testRunningItTwiceChangesNothingFurther()
    {
        $chat = $this->chat();

        $result = Message::create([
            'chat_id'      => $chat->id,
            'role'         => Message::ROLE_TOOL,
            'body'         => '{"ok":true}',
            'tool_call_id' => 'call_1',
            'tool_name'    => 'conversation.add_note',
            'status'       => Message::STATUS_OK,
        ]);

        $this->migrate();
        $this->migrate();

        $this->assertEquals('conversation_add_note', $result->fresh()->tool_name);
    }
}
