<?php

namespace Modules\AiChatPanel\Tests;

use App\Conversation;
use App\Mailbox;
use App\User;
use Modules\AiChatPanel\Entities\Chat;
use Modules\AiChatPanel\Entities\Message;

/**
 * Every route is gated on the conversation, not on a role.
 *
 * The client is never trusted: it sends a conversation id and the server
 * decides. These tests come at that from the outside, over HTTP.
 */
class AuthorizationTest extends AiChatPanelTestCase
{
    /**
     * @return array
     */
    protected function routes()
    {
        return [
            ['post', '/aichatpanel/chat/history', ['conversation_id' => $this->conversation->id]],
            ['post', '/aichatpanel/chat/send', ['conversation_id' => $this->conversation->id, 'message' => 'hello']],
            ['post', '/aichatpanel/chat/reset', ['conversation_id' => $this->conversation->id]],
            ['post', '/aichatpanel/chat/confirm', ['conversation_id' => $this->conversation->id, 'tool_call_id' => 'x', 'approved' => 1]],
        ];
    }

    /**
     * A JSON request carrying a valid CSRF token.
     *
     * Every POST route sits in the 'web' middleware group, so the token is
     * required; testCsrfIsEnforced below proves that is not accidental.
     *
     * @param string $uri
     * @param array  $data
     *
     * @return \Illuminate\Foundation\Testing\TestResponse
     */
    protected function csrfPost($uri, array $data = [])
    {
        return $this->json('POST', $uri, array_merge($data, ['_token' => csrf_token()]));
    }

    /**
     * Read the body of a StreamedResponse.
     *
     * getContent() is empty for a streamed response — the callback only runs on
     * sendContent() — so it has to be captured.
     *
     * @param \Illuminate\Foundation\Testing\TestResponse $response
     *
     * @return string
     */
    protected function captureStream($response)
    {
        ob_start();
        $response->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    public function testUserWithoutMailboxAccessIsDeniedOnEveryRoute()
    {
        foreach ($this->routes() as $route) {
            list($method, $uri, $data) = $route;

            $response = $this->actingAs($this->outsider)->csrfPost($uri, $data);

            $response->assertStatus(403, 'Route '.$uri.' must reject a user without mailbox access.');
            $this->assertEquals('error', $response->json()['status']);
        }
    }

    /**
     * CSRF cannot be asserted end to end: Laravel's VerifyCsrfToken skips
     * itself whenever APP_ENV is "testing" (runningUnitTests()), so a request
     * without a token succeeds here no matter what the middleware stack says.
     *
     * What can be asserted is the thing that actually provides the protection:
     * every mutating route runs through the 'web' group, which is where
     * VerifyCsrfToken lives.
     */
    public function testEveryPostRouteRunsThroughTheWebMiddlewareGroup()
    {
        $checked = 0;

        foreach (\Route::getRoutes() as $route) {
            if (strpos($route->uri(), 'aichatpanel') === false) {
                continue;
            }

            if (!in_array('POST', $route->methods())) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $this->assertContains('web', $middleware,
                'Route '.$route->uri().' must be in the web group for CSRF protection.');
            $this->assertContains('auth', $middleware,
                'Route '.$route->uri().' must require authentication.');

            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'No AiChatPanel POST routes were found to check.');

        // And the group really does carry the CSRF middleware in this install.
        // Laravel 5.5's Kernel has no public accessor for the groups, so read
        // the property directly.
        $groups = new \ReflectionProperty(\App\Http\Kernel::class, 'middlewareGroups');
        $groups->setAccessible(true);
        $web = $groups->getValue(app(\Illuminate\Contracts\Http\Kernel::class))['web'];

        $this->assertContains(\App\Http\Middleware\VerifyCsrfToken::class, $web);
    }

    public function testUserWithMailboxAccessIsAllowed()
    {
        $response = $this->actingAs($this->agent)
            ->csrfPost('/aichatpanel/chat/history', ['conversation_id' => $this->conversation->id]);

        $response->assertStatus(200);
        $this->assertEquals('success', $response->json()['status']);
    }

    public function testUnauthenticatedRequestsAreRejected()
    {
        foreach ($this->routes() as $route) {
            list($method, $uri, $data) = $route;

            $response = $this->csrfPost($uri, $data);

            $this->assertContains(
                $response->getStatusCode(),
                [401, 403, 302],
                'Route '.$uri.' must not answer an unauthenticated request.'
            );
        }
    }

    public function testAConversationInAnotherMailboxIsNotReachable()
    {
        $other_mailbox = factory(Mailbox::class)->create();
        $other_conversation = factory(Conversation::class)->create([
            'mailbox_id' => $other_mailbox->id,
            'state'      => Conversation::STATE_PUBLISHED,
        ]);

        $response = $this->actingAs($this->agent)
            ->csrfPost('/aichatpanel/chat/history', ['conversation_id' => $other_conversation->id]);

        $response->assertStatus(403);
    }

    public function testAMissingConversationIsIndistinguishableFromAForbiddenOne()
    {
        $forbidden = factory(Conversation::class)->create([
            'mailbox_id' => factory(Mailbox::class)->create()->id,
            'state'      => Conversation::STATE_PUBLISHED,
        ]);

        $missing = $this->actingAs($this->agent)
            ->csrfPost('/aichatpanel/chat/history', ['conversation_id' => 999999]);

        $denied = $this->actingAs($this->agent)
            ->csrfPost('/aichatpanel/chat/history', ['conversation_id' => $forbidden->id]);

        $this->assertEquals($missing->getStatusCode(), $denied->getStatusCode());
        $this->assertEquals($missing->json()['msg'], $denied->json()['msg']);
    }

    public function testPanelIsDeniedWhenDisabledForTheMailbox()
    {
        $this->setSettings(['enabled' => false]);

        $response = $this->actingAs($this->agent)
            ->csrfPost('/aichatpanel/chat/history', ['conversation_id' => $this->conversation->id]);

        $response->assertStatus(403);
    }

    public function testAdminWithoutMailboxMembershipStillHasAccess()
    {
        // Core's ConversationPolicy grants admins access to every mailbox; the
        // module must not accidentally be stricter than the rest of the app.
        $response = $this->actingAs($this->admin)
            ->csrfPost('/aichatpanel/chat/history', ['conversation_id' => $this->conversation->id]);

        $response->assertStatus(200);
    }

    public function testChatsAreNotSharedBetweenUsers()
    {
        $agent_chat = Chat::findOrCreateFor($this->conversation->id, $this->agent->id);
        Message::create([
            'chat_id' => $agent_chat->id,
            'role'    => Message::ROLE_USER,
            'body'    => 'private note to self',
        ]);

        $response = $this->actingAs($this->admin)
            ->csrfPost('/aichatpanel/chat/history', ['conversation_id' => $this->conversation->id]);

        $response->assertStatus(200);

        $bodies = array_column($response->json()['messages'], 'body');
        $this->assertNotContains('private note to self', $bodies);
    }

    public function testConfirmRejectsAToolCallIdThatIsNotPending()
    {
        $response = $this->actingAs($this->agent)->csrfPost('/aichatpanel/chat/confirm', [
            'conversation_id' => $this->conversation->id,
            'tool_call_id'    => 'made-up',
            'approved'        => 1,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('error', $response->json()['status']);
    }

    public function testStreamTokenIsRejectedForAnotherUser()
    {
        $start = $this->actingAs($this->agent)->csrfPost('/aichatpanel/chat/send', [
            'conversation_id' => $this->conversation->id,
            'message'         => 'hello',
            'stream'          => 1,
        ]);

        $url = $start->json()['stream_url'];
        $this->assertNotEmpty($url);

        $path = parse_url($url, PHP_URL_PATH);

        // The admin can see the conversation but did not create this turn.
        $response = $this->actingAs($this->admin)->get($path);

        $this->assertStringContainsString('event: failure', $this->captureStream($response));
    }

    public function testStreamTokenWorksOnlyOnce()
    {
        $start = $this->actingAs($this->agent)->csrfPost('/aichatpanel/chat/send', [
            'conversation_id' => $this->conversation->id,
            'message'         => 'hello',
            'stream'          => 1,
        ]);

        $path = parse_url($start->json()['stream_url'], PHP_URL_PATH);

        // First use consumes the token (the endpoint is unreachable in tests,
        // so the run itself fails — that is fine, the token is still spent).
        $this->captureStream($this->actingAs($this->agent)->get($path));

        $second = $this->captureStream($this->actingAs($this->agent)->get($path));

        $this->assertStringContainsString('event: failure', $second);
        $this->assertStringContainsString('expired', $second);
    }
}
