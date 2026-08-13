<?php

namespace Modules\AiChatPanel\Tests;

use Illuminate\Http\Request;
use Modules\AiChatPanel\Entities\Chat;
use Modules\AiChatPanel\Entities\Message;
use Modules\AiChatPanel\Http\Controllers\ChatController;

/**
 * The endpoint behind the panel's "Reply" and "Note" buttons.
 *
 * The action is invoked directly rather than over HTTP. The rest of this
 * suite's HTTP tests currently fail in this environment on a core middleware
 * calling header_remove() after PHPUnit has already written output, which has
 * nothing to do with this endpoint — invoking the action keeps the
 * authorisation rules covered without inheriting that failure.
 */
class EditorHtmlEndpointTest extends AiChatPanelTestCase
{
    /**
     * @param int         $chat_user_id
     * @param string      $body
     * @param int         $role
     * @param int         $status
     *
     * @return Message
     */
    protected function message($chat_user_id, $body = 'Hello **world**', $role = Message::ROLE_ASSISTANT, $status = Message::STATUS_OK)
    {
        $chat = Chat::findOrCreateFor($this->conversation->id, $chat_user_id);

        return Message::create([
            'chat_id' => $chat->id,
            'role'    => $role,
            'body'    => $body,
            'status'  => $status,
        ]);
    }

    /**
     * @param Message|null $message
     * @param int|null     $conversation_id
     *
     * @return array
     */
    protected function call_($message, $conversation_id = null)
    {
        $request = Request::create('/aichatpanel/chat/editor-html', 'POST', [
            'conversation_id' => $conversation_id ?: $this->conversation->id,
            'message_id'      => $message ? $message->id : 0,
        ]);

        return json_decode((new ChatController())->editorHtml($request)->getContent(), true);
    }

    public function testAnAnswerIsRenderedAsEditorHtml()
    {
        $this->actingAs($this->agent);

        $response = $this->call_($this->message($this->agent->id, "Hi,\n\n- **one**\n- two\n\n`code` too"));

        $this->assertEquals('success', $response['status']);
        $this->assertStringContainsString('<strong>one</strong>', $response['html']);
        $this->assertStringContainsString('<li>', $response['html']);

        // The panel profile would keep these; core's purifier would then drop
        // them, which is the bug this endpoint exists to fix.
        $this->assertStringNotContainsString('<code', $response['html']);
        $this->assertStringContainsString('font-family:monospace', $response['html']);
        $this->assertStringNotContainsString('<p>', $response['html']);
    }

    public function testTheRenderSurvivesCoresPurifier()
    {
        $this->actingAs($this->agent);

        $html = $this->call_($this->message($this->agent->id, "# Heading\n\n---\n\n| a | b |\n|---|---|\n| 1 | 2 |"))['html'];

        $this->assertEquals(
            trim(preg_replace('/>\s+</', '><', $html)),
            trim(preg_replace('/>\s+</', '><', \Helper::purifyHtml($html)))
        );
    }

    public function testAnotherUsersChatIsNotReadable()
    {
        // A chat belongs to one conversation AND one user. Checking only the
        // conversation would let any agent on the mailbox read the others'.
        $this->actingAs($this->agent);

        $response = $this->call_($this->message($this->admin->id));

        $this->assertEquals('error', $response['status']);
        $this->assertArrayNotHasKey('html', $response);
    }

    public function testAUserWithoutAccessIsDenied()
    {
        $this->actingAs($this->outsider);

        $response = $this->call_($this->message($this->outsider->id));

        $this->assertEquals('error', $response['status']);
    }

    public function testAMissingMessageIsDeniedTheSameWay()
    {
        $this->actingAs($this->agent);

        $missing = $this->call_(null);
        $forbidden = $this->call_($this->message($this->admin->id));

        // Indistinguishable answers, so the endpoint is not a way to find out
        // which message ids exist.
        $this->assertEquals($forbidden['msg'], $missing['msg']);
    }

    public function testAUserTurnCannotBeRendered()
    {
        $this->actingAs($this->agent);

        $response = $this->call_($this->message($this->agent->id, 'my question', Message::ROLE_USER));

        $this->assertEquals('error', $response['status']);
    }

    public function testAFailedTurnCannotBeRendered()
    {
        $this->actingAs($this->agent);

        $response = $this->call_(
            $this->message($this->agent->id, 'The endpoint timed out.', Message::ROLE_ASSISTANT, Message::STATUS_ERROR)
        );

        $this->assertEquals('error', $response['status']);
    }
}
