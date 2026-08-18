<?php

namespace Modules\AiChatPanel\Tests;

use App\Conversation;
use App\Thread;
use Modules\AiChatPanel\Entities\Chat;
use Modules\AiChatPanel\Services\Context\ContextBuilder;
use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\ToolRegistry;

/**
 * The panel on a mail that has not been sent.
 *
 * FreeScout gives the compose screen a real conversation of its own: the first
 * autosave writes one in STATE_DRAFT, and sending reuses that same conversation
 * rather than making a second. Everything here follows from that — the chat is
 * keyed to the draft, survives the send, and until the send the assistant is
 * told it is looking at a mail nobody has received.
 */
class ComposeScreenTest extends AiChatPanelTestCase
{
    /**
     * A conversation in the state core leaves the compose form in: draft, with
     * the composed body as its one draft thread.
     *
     * @return Conversation
     */
    protected function draftConversation()
    {
        $conversation = factory(Conversation::class)->create([
            'mailbox_id'  => $this->mailbox->id,
            'customer_id' => $this->customer->id,
            'user_id'     => $this->agent->id,
            'state'       => Conversation::STATE_DRAFT,
            'status'      => Conversation::STATUS_ACTIVE,
        ]);

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type = Thread::TYPE_MESSAGE;
        $thread->state = Thread::STATE_DRAFT;
        $thread->status = Thread::STATUS_ACTIVE;
        $thread->body = '<div>Half a sentence so far</div>';
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->created_by_user_id = $this->agent->id;
        $thread->save();

        return $conversation->fresh();
    }

    public function testAConversationThatHasNotBeenSentIsRecognised()
    {
        $context = $this->context($this->agent, $this->draftConversation());

        $this->assertTrue($context->isUnsentDraft());
    }

    public function testTheCurrentConversationIsNotTreatedAsUnsent()
    {
        $this->assertFalse($this->context()->isUnsentDraft());
    }

    /**
     * The compose screen renders before any autosave has run, so the panel is
     * built against a conversation that has never been saved.
     */
    public function testAConversationWithNoIdCountsAsUnsent()
    {
        $conversation = new Conversation();
        $conversation->mailbox_id = $this->mailbox->id;
        $conversation->setRelation('mailbox', $this->mailbox);

        $context = new PanelContext($conversation, $this->agent);

        $this->assertTrue($context->isUnsentDraft());
    }

    public function testThePromptSaysTheMailHasNotBeenSent()
    {
        $content = (new ContextBuilder($this->context($this->agent, $this->draftConversation())))
            ->build(0)['content'];

        $this->assertStringContainsString('This mail has not been sent:', $content);
        $this->assertStringContainsString('composing a new mail', $content);
    }

    /**
     * The mail being written is itself a draft thread. Announcing it as a draft
     * would send the model after it with a tool, for text it already has as the
     * editor contents — and those tools are withheld here anyway.
     */
    public function testThePromptDoesNotOfferTheDraftToolsWhileComposing()
    {
        $content = (new ContextBuilder($this->context($this->agent, $this->draftConversation())))
            ->build(0)['content'];

        $this->assertStringNotContainsString('Unsent drafts', $content);
        $this->assertStringNotContainsString('conversation_get_drafts', $content);
        $this->assertStringNotContainsString('conversation_update_draft', $content);

        // The rule that tells the model where a reply it was asked for should
        // go must name the chat here, not the draft tool it cannot call.
        $this->assertStringNotContainsString('conversation_create_draft_reply', $content);
        $this->assertStringContainsString('The agent inserts it into the mail they are writing.', $content);
    }

    public function testAnOrdinaryConversationStillGetsTheDraftWording()
    {
        $content = (new ContextBuilder($this->context()))->build(0)['content'];

        $this->assertStringContainsString('Drafts:', $content);
        $this->assertStringContainsString('Unsent drafts', $content);
    }

    /**
     * Nothing that writes into a conversation is offered while the mail is
     * unsent: a note nobody can read yet, a status on something not sent, and a
     * draft reply to a mail that has not gone out are all meaningless.
     */
    public function testWriteToolsAreWithheldWhileTheMailIsUnsent()
    {
        $this->setSettings([
            'tools_enabled' => [
                'conversation_create_draft_reply',
                'conversation_update_draft',
                'conversation_add_note',
                'conversation_set_status',
                'conversation_get_drafts',
            ],
        ]);

        $registry = new ToolRegistry($this->context($this->agent, $this->draftConversation()));

        $this->assertSame([], array_keys($registry->available()));
    }

    public function testTheSameToolsAreOfferedOnceTheMailHasBeenSent()
    {
        $this->setSettings([
            'tools_enabled' => ['conversation_add_note', 'conversation_set_status'],
        ]);

        $registry = new ToolRegistry($this->context());

        $names = array_keys($registry->available());

        $this->assertContains('conversation_add_note', $names);
        $this->assertContains('conversation_set_status', $names);
    }

    /**
     * The point of keying the chat to the draft conversation: send_reply
     * publishes that very conversation instead of creating a new one, so the
     * exchange that produced the mail is still there afterwards.
     */
    public function testTheChatSurvivesTheMailBeingSent()
    {
        $conversation = $this->draftConversation();

        $chat = Chat::findOrCreateFor($conversation->id, $this->agent->id);

        $conversation->state = Conversation::STATE_PUBLISHED;
        $conversation->save();

        $this->assertEquals(
            $chat->id,
            Chat::findOrCreateFor($conversation->id, $this->agent->id)->id
        );
    }

    /**
     * The panel is rendered on a reopened draft too, so its endpoints have to
     * accept one.
     */
    public function testTheChatEndpointsAcceptADraftConversation()
    {
        $conversation = $this->draftConversation();

        $response = $this->actingAs($this->agent)
            ->csrfPost('/aichatpanel/chat/history', ['conversation_id' => $conversation->id]);

        $response->assertStatus(200);

        $body = json_decode($response->getContent(), true);

        $this->assertEquals('success', $body['status']);
    }
}
