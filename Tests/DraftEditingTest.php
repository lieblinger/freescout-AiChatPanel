<?php

namespace Modules\AiChatPanel\Tests;

use App\Conversation;
use App\Thread;
use Modules\AiChatPanel\Services\Context\ContextBuilder;
use Modules\AiChatPanel\Services\Tools\ToolRegistry;

/**
 * Reading and editing drafts.
 *
 * The boundary these tests defend: a draft can be rewritten, anything published
 * cannot. A sent reply and a note colleagues have already read are not editable
 * through this module at all, and the check happens at execution time rather
 * than when the tool list was built — the agent may have pressed Send in another
 * tab in between.
 */
class DraftEditingTest extends AiChatPanelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setSettings([
            'tools_enabled' => [
                'conversation_get_drafts',
                'conversation_update_draft',
                'conversation_create_draft_reply',
            ],
        ]);
    }

    /**
     * @param User|null $user
     *
     * @return ToolRegistry
     */
    protected function registry($user = null)
    {
        return new ToolRegistry($this->context($user));
    }

    /**
     * A draft thread on the conversation.
     *
     * @param string       $body
     * @param int          $type
     * @param int|null     $created_by_user_id
     * @param Conversation $conversation
     *
     * @return Thread
     */
    protected function addDraft($body, $type = Thread::TYPE_MESSAGE, $created_by_user_id = null, $conversation = null)
    {
        $conversation = $conversation ?: $this->conversation;

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type = $type;
        $thread->state = Thread::STATE_DRAFT;
        $thread->status = $conversation->status;
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id = $this->customer->id;
        $thread->created_by_user_id = $created_by_user_id ?: $this->agent->id;
        $thread->body = $body;
        $thread->save();

        return $thread;
    }

    /**
     * @param array $arguments
     *
     * @return \Modules\AiChatPanel\Services\Tools\ToolResult
     */
    protected function update(array $arguments, $user = null)
    {
        return $this->registry($user)->execute(
            'conversation_update_draft',
            \Helper::jsonEncodeSafe($arguments),
            ['confirmed' => true]
        );
    }

    /**
     * @param array $arguments
     *
     * @return \Modules\AiChatPanel\Services\Tools\ToolResult
     */
    protected function getDrafts(array $arguments = [], $user = null)
    {
        return $this->registry($user)->execute(
            'conversation_get_drafts',
            \Helper::jsonEncodeSafe($arguments)
        );
    }

    // -- Updating -----------------------------------------------------------

    public function testItRewritesTheDraftInPlace()
    {
        $draft = $this->addDraft('The long original draft.');

        $result = $this->update(['body' => 'Shorter.']);

        $this->assertTrue($result->ok, $result->error);

        $draft = $draft->fresh();

        // A draft body is HTML: the tool takes Markdown and stores what the
        // reply editor produces, so a one-line body is one <div>.
        $this->assertEquals('<div>Shorter.</div>', $draft->body);
        $this->assertEquals(Thread::STATE_DRAFT, $draft->state, 'Editing must not publish the draft.');
        $this->assertEquals($draft->id, $result->data['thread_id'], 'The same thread must be reused, not replaced.');
        $this->assertFalse($result->data['sent']);
    }

    public function testItResolvesTheOnlyDraftWithoutAThreadId()
    {
        $draft = $this->addDraft('Original.');

        $this->assertTrue($this->update(['body' => 'New text.'])->ok);
        $this->assertEquals('<div>New text.</div>', $draft->fresh()->body);
    }

    public function testItRefusesToGuessBetweenSeveralDrafts()
    {
        $first = $this->addDraft('First draft.');
        $second = $this->addDraft('Second draft.');

        $result = $this->update(['body' => 'Which one?']);

        $this->assertFalse($result->ok);
        // The model has to be able to recover, so the error names the choices.
        $this->assertStringContainsString((string) $first->id, $result->error);
        $this->assertStringContainsString((string) $second->id, $result->error);

        $this->assertEquals('First draft.', $first->fresh()->body);
        $this->assertEquals('Second draft.', $second->fresh()->body);
    }

    public function testItTargetsTheNamedDraftWhenThereAreSeveral()
    {
        $first = $this->addDraft('First draft.');
        $second = $this->addDraft('Second draft.');

        $this->assertTrue($this->update(['body' => 'Rewritten.', 'thread_id' => $second->id])->ok);

        $this->assertEquals('First draft.', $first->fresh()->body);
        $this->assertEquals('<div>Rewritten.</div>', $second->fresh()->body);
    }

    public function testItRejectsAThreadFromAnotherConversation()
    {
        $this->addDraft('The draft on the open conversation.');

        $other = factory(Conversation::class)->create([
            'mailbox_id'  => $this->mailbox->id,
            'customer_id' => $this->customer->id,
            'state'       => Conversation::STATE_PUBLISHED,
            'status'      => Conversation::STATUS_ACTIVE,
        ]);

        $foreign = $this->addDraft('Someone else\'s draft.', Thread::TYPE_MESSAGE, null, $other);

        $result = $this->update(['body' => 'Reaching across.', 'thread_id' => $foreign->id]);

        $this->assertFalse($result->ok);
        $this->assertEquals('Someone else\'s draft.', $foreign->fresh()->body, 'The tool must not reach outside the open conversation.');
    }

    public function testItRejectsAPublishedThread()
    {
        $this->addDraft('A draft, so the tool is available at all.');
        $published = $this->addThread('A reply the customer has already read.', Thread::TYPE_MESSAGE);

        $result = $this->update(['body' => 'Rewriting history.', 'thread_id' => $published->id]);

        $this->assertFalse($result->ok);
        $this->assertEquals('A reply the customer has already read.', $published->fresh()->body);
    }

    public function testItRejectsADraftThatWasSentInTheMeantime()
    {
        $draft = $this->addDraft('About to be sent.');

        // The agent pressed Send in another tab after the prompt was built.
        $draft->state = Thread::STATE_PUBLISHED;
        $draft->save();

        $result = $this->update(['body' => 'Too late.', 'thread_id' => $draft->id]);

        $this->assertFalse($result->ok);
        $this->assertEquals('About to be sent.', $draft->fresh()->body, 'A sent message must never be rewritten.');
    }

    public function testADraftNoteIsEditableThroughTheSameTool()
    {
        $note = $this->addDraft('Draft note.', Thread::TYPE_NOTE);

        $this->assertTrue($this->update(['body' => 'Better draft note.'])->ok);

        $note = $note->fresh();

        $this->assertEquals('<div>Better draft note.</div>', $note->body);
        $this->assertEquals(Thread::TYPE_NOTE, $note->type);
        $this->assertEquals(Thread::STATE_DRAFT, $note->state);
    }

    public function testTheEditorIsStampedOnlyWhenTheyAreNotTheAuthor()
    {
        $own = $this->addDraft('Written by the acting user.', Thread::TYPE_MESSAGE, $this->agent->id);

        $this->update(['body' => 'Edited by the author.']);

        $this->assertNull($own->fresh()->edited_by_user_id, 'Editing your own draft is not an "edited by" event.');

        $own->delete();

        $other = $this->addDraft('Written by somebody else.', Thread::TYPE_MESSAGE, $this->admin->id);

        $this->update(['body' => 'Edited by a colleague.']);

        $other = $other->fresh();

        // Core reads exactly these two to render "X edited Y's draft".
        $this->assertEquals($this->agent->id, $other->edited_by_user_id);
        $this->assertNotNull($other->edited_at);
    }

    public function testTheNewBodyIsSanitisedBeforeItIsStored()
    {
        $draft = $this->addDraft('Harmless.');

        $this->update(['body' => 'Hello <script>alert(1)</script> there']);

        $body = $draft->fresh()->body;

        $this->assertStringNotContainsString('<script', $body, 'Model output is untrusted and must not reach the editor as markup.');

        // The body is converted from Markdown by HTMLPurifier now rather than
        // escaped, so a script element is removed with its content instead of
        // being shown to the agent as text. Same rule as the chat panel's own
        // rendering — see OutputSanitisingTest::testScriptTagsAreRemoved().
        $this->assertStringNotContainsString('alert(1)', $body);
        $this->assertStringContainsString('Hello', $body);
        $this->assertStringContainsString('there', $body);
    }

    public function testAUserWithoutAccessCannotEditTheDraft()
    {
        $draft = $this->addDraft('Not yours.');

        $result = $this->update(['body' => 'Mine now.'], $this->outsider);

        $this->assertFalse($result->ok);
        $this->assertEquals('Not yours.', $draft->fresh()->body);
    }

    public function testItCanNeverBeExemptedFromConfirmation()
    {
        $this->addDraft('A draft.');

        // Even when an admin explicitly lists it as auto-runnable.
        $this->setSettings(['write_tools_autorun' => ['conversation_update_draft']]);

        $registry = $this->registry();
        $tool = $registry->find('conversation_update_draft');

        $this->assertNotNull($tool);
        $this->assertFalse($registry->mayAutoRun($tool));
        $this->assertContains('conversation_update_draft', ToolRegistry::neverAutoRun());
    }

    public function testItIsNotOfferedWhenThereIsNoDraft()
    {
        $names = array_column(
            array_column($this->registry()->toApiDefinitions(), 'function'),
            'name'
        );

        $this->assertNotContains('conversation_update_draft', $names, 'Nothing to update, so the tool should stay out of the payload.');

        $this->addDraft('Now there is one.');

        $names = array_column(
            array_column($this->registry()->toApiDefinitions(), 'function'),
            'name'
        );

        $this->assertContains('conversation_update_draft', $names);
    }

    // -- Reading ------------------------------------------------------------

    public function testItReadsTheDraftWithItsThreadId()
    {
        $draft = $this->addDraft('Dear customer,<br>we refunded the order.');

        $result = $this->getDrafts();

        $this->assertTrue($result->ok, $result->error);
        $this->assertEquals(1, $result->data['count']);

        $first = $result->data['drafts'][0];

        $this->assertEquals($draft->id, $first['thread_id']);
        $this->assertEquals('draft_reply', $first['kind']);
        $this->assertStringContainsString('we refunded the order.', $first['body']);
    }

    public function testNoDraftsIsASuccessfulAnswerNotAnError()
    {
        $result = $this->getDrafts();

        $this->assertTrue($result->ok);
        $this->assertEquals(0, $result->data['count']);
        $this->assertEquals([], $result->data['drafts']);
    }

    public function testItRefusesAConversationTheUserCannotSee()
    {
        $other_mailbox = factory(\App\Mailbox::class)->create();

        $hidden = factory(Conversation::class)->create([
            'mailbox_id'  => $other_mailbox->id,
            'customer_id' => $this->customer->id,
            'state'       => Conversation::STATE_PUBLISHED,
            'status'      => Conversation::STATUS_ACTIVE,
        ]);

        $this->addDraft('Confidential draft.', Thread::TYPE_MESSAGE, null, $hidden);

        $result = $this->getDrafts(['number' => $hidden->number]);

        $this->assertFalse($result->ok);
        // "Not found", not "forbidden": the difference is itself information.
        $this->assertStringContainsString('not found', $result->error);
        $this->assertStringNotContainsString('Confidential', $result->error);
    }

    public function testReadingAfterAnUpdateReturnsTheNewText()
    {
        $this->addDraft('The first version.');

        $this->update(['body' => 'The second version.']);

        // The whole reason drafts are a tool rather than a block in the system
        // message, which is built once per request and would still say "first".
        $body = $this->getDrafts()->data['drafts'][0]['body'];

        $this->assertStringContainsString('The second version.', $body);
        $this->assertStringNotContainsString('The first version.', $body);
    }

    // -- The dead end that started this -------------------------------------

    public function testCreatingASecondDraftIsRefusedAndPointsAtTheUpdateTool()
    {
        $existing = $this->addDraft('The draft that is already there.');

        $result = $this->registry()->execute(
            'conversation_create_draft_reply',
            \Helper::jsonEncodeSafe(['body' => 'A rival draft.']),
            ['confirmed' => true]
        );

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('conversation_update_draft', $result->error);
        $this->assertStringContainsString((string) $existing->id, $result->error);

        $this->assertEquals(1, $this->conversation->threads()->where('state', Thread::STATE_DRAFT)->count());
    }

    // -- The prompt ---------------------------------------------------------

    public function testThePromptNamesTheDraftWithoutCarryingItsBody()
    {
        $draft = $this->addDraft('A very distinctive sentence nobody has received.');

        $content = (new ContextBuilder($this->context()))->build()['content'];

        $this->assertStringContainsString('Unsent drafts: 1', $content);
        $this->assertStringContainsString('thread '.$draft->id, $content);
        $this->assertStringContainsString('conversation_get_drafts', $content);

        // Existence, not content: the body stays behind the tool.
        $this->assertStringNotContainsString('A very distinctive sentence', $content);
    }

    public function testDraftsStayOutOfTheConversationHistory()
    {
        $this->addThread('A published customer message.');
        $this->addDraft('An unsent draft reply.');

        $content = (new ContextBuilder($this->context()))->build()['content'];

        $this->assertStringContainsString('A published customer message.', $content);
        $this->assertStringNotContainsString('An unsent draft reply.', $content);
    }

    public function testThePromptSaysOutLoudWhenThereIsNoDraft()
    {
        $content = (new ContextBuilder($this->context()))->build()['content'];

        $this->assertStringContainsString('Unsent drafts: none.', $content);
        $this->assertStringContainsString('conversation_create_draft_reply', $content);
    }

    /**
     * The bug this file's last section is named after, one step further on.
     *
     * The model writes a draft, the agent discards it, and the agent asks for
     * another in the same chat. The successful create_draft_reply result is
     * still sitting in the history, so the prompt has to contradict it: a
     * missing "Unsent drafts" line is not a denial, and the model read it as
     * confirmation that its draft was still there and refused to write a new
     * one without calling a single tool.
     */
    public function testThePromptContradictsADraftThatHasSinceBeenDeleted()
    {
        $draft = $this->addDraft('The draft that was there a moment ago.');

        $this->assertStringContainsString(
            'thread '.$draft->id,
            (new ContextBuilder($this->context()))->build()['content']
        );

        $draft->delete();
        $this->conversation->refresh();

        $content = (new ContextBuilder($this->context()))->build()['content'];

        $this->assertStringContainsString('Unsent drafts: none.', $content);
        $this->assertStringNotContainsString('thread '.$draft->id, $content);
        $this->assertStringContainsString('no draft right now', $content);
    }
}
