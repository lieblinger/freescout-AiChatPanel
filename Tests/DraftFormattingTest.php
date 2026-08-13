<?php

namespace Modules\AiChatPanel\Tests;

use App\Thread;
use Modules\AiChatPanel\Services\Tools\Builtin\AddNoteTool;
use Modules\AiChatPanel\Services\Tools\Builtin\CreateDraftReplyTool;
use Modules\AiChatPanel\Services\Tools\Builtin\UpdateDraftTool;
use Modules\AiChatPanel\Services\Tools\ToolException;

/**
 * What the write tools actually store.
 *
 * They used to store nl2br(htmlspecialchars($body)), so a draft the model wrote
 * in Markdown reached the customer's mailbox with literal asterisks and hyphens
 * in it. The body now goes through the same converter an inserted answer does,
 * and the result has to survive core's purifier — which runs again when the
 * draft is displayed and once more when it is sent.
 */
class DraftFormattingTest extends AiChatPanelTestCase
{
    /** @var string */
    protected $markdown = "Hi there,\n\n"
        ."Here is what I found:\n\n"
        ."- the licence renews in **March**\n"
        ."- a seat costs 40.00\n\n"
        ."See [the pricing page](https://example.com/pricing) for the rest.";

    public function testADraftReplyIsStoredAsFormattedHtml()
    {
        $result = (new CreateDraftReplyTool())->handle(
            ['body' => $this->markdown],
            $this->context()
        );

        $this->assertTrue($result->ok);

        $body = $this->draft()->body;

        $this->assertStringContainsString('<strong>March</strong>', $body);
        $this->assertStringContainsString('<li>', $body);
        $this->assertStringContainsString('href="https://example.com/pricing"', $body);

        // The old behaviour, and the bug: escaped Markdown in the mailbox.
        $this->assertStringNotContainsString('&lt;', $body);
        $this->assertStringNotContainsString('**March**', $body);
    }

    public function testADraftReplySurvivesCoresPurifier()
    {
        (new CreateDraftReplyTool())->handle(['body' => $this->markdown], $this->context());

        $body = $this->draft()->body;

        // The invariant that makes the formatting reach the customer: core
        // purifies this body again on display and on send.
        $this->assertEquals(
            $this->withoutInterTagWhitespace($body),
            $this->withoutInterTagWhitespace(\Helper::purifyHtml($body))
        );
    }

    public function testANoteIsStoredAsFormattedHtml()
    {
        $result = (new AddNoteTool())->handle(
            ['body' => "Checked the account.\n\n- plan: **Pro**\n- seats: 4"],
            $this->context()
        );

        $this->assertTrue($result->ok);

        $note = $this->conversation->threads()->where('type', Thread::TYPE_NOTE)->first();

        $this->assertNotNull($note);
        $this->assertStringContainsString('<strong>Pro</strong>', $note->body);
        $this->assertStringContainsString('<li>', $note->body);
    }

    public function testNotesMayUseTheConstructsAnEmailShouldNot()
    {
        // A note is internal, so a table and a code block are reasonable there.
        (new AddNoteTool())->handle(
            ['body' => "| key | value |\n|---|---|\n| plan | Pro |\n\n```\nGET /api/v1/account\n```"],
            $this->context()
        );

        $note = $this->conversation->threads()->where('type', Thread::TYPE_NOTE)->first();

        $this->assertStringContainsString('<table class="table table-bordered" border="1"', $note->body);
        $this->assertStringContainsString('<pre style=', $note->body);
        $this->assertStringContainsString('GET /api/v1/account', $note->body);
    }

    public function testAModelWritingMarkupGetsItRemoved()
    {
        (new CreateDraftReplyTool())->handle(
            ['body' => "Hello\n\n<script>alert(1)</script><img src=x onerror=\"alert(2)\">"],
            $this->context()
        );

        $body = $this->draft()->body;

        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('onerror', $body);
        $this->assertStringNotContainsString('<img', $body);
        $this->assertStringContainsString('Hello', $body);
    }

    public function testABodyThatIsNothingButMarkupIsStillStored()
    {
        // Storing an empty thread would be worse than storing escaped text.
        (new CreateDraftReplyTool())->handle(
            ['body' => '<script>alert(1)</script>'],
            $this->context()
        );

        $body = $this->draft()->body;

        $this->assertNotEquals('', trim(strip_tags($body)));
        $this->assertStringNotContainsString('<script', $body);
    }

    public function testAnEmptyBodyIsStillRefused()
    {
        $this->expectException(ToolException::class);

        (new CreateDraftReplyTool())->handle(['body' => '   '], $this->context());
    }

    public function testStillOnlyOneDraftAtATime()
    {
        (new CreateDraftReplyTool())->handle(['body' => 'First draft.'], $this->context());

        $this->expectException(ToolException::class);

        (new CreateDraftReplyTool())->handle(['body' => 'Second draft.'], $this->context());
    }

    public function testEditingADraftKeepsItFormatted()
    {
        // The full loop the assistant actually runs: create, read back with
        // get_drafts, write the same text again. Nothing may be lost on the way
        // round, or a draft loses its formatting every time it is edited.
        (new CreateDraftReplyTool())->handle(['body' => $this->markdown], $this->context());

        $read_back = \Modules\AiChatPanel\Services\Context\ThreadFormatter::draftBody($this->draft());

        $this->assertStringContainsString('**March**', $read_back);
        $this->assertStringContainsString('- the licence renews', $read_back);
        $this->assertStringContainsString('[the pricing page](https://example.com/pricing)', $read_back);

        (new UpdateDraftTool())->handle(['body' => $read_back], $this->context());

        $body = $this->draft()->fresh()->body;

        $this->assertStringContainsString('<strong>March</strong>', $body);
        $this->assertStringContainsString('<li>', $body);
        $this->assertStringContainsString('href="https://example.com/pricing"', $body);
    }

    public function testTheToolsTellTheModelToWriteMarkdown()
    {
        $reply = (new CreateDraftReplyTool())->parameters();
        $note = (new AddNoteTool())->parameters();

        $update = (new UpdateDraftTool())->parameters();

        $this->assertStringContainsString('Markdown', $reply['properties']['body']['description']);
        $this->assertStringContainsString('Markdown', $note['properties']['body']['description']);
        $this->assertStringContainsString('Markdown', $update['properties']['body']['description']);

        // The old description promised the opposite, and the model believed it.
        $this->assertStringNotContainsString('Plain text', $reply['properties']['body']['description']);
    }

    // -----------------------------------------------------------------------

    /**
     * @return Thread
     */
    protected function draft()
    {
        $draft = $this->conversation->threads()
            ->where('state', Thread::STATE_DRAFT)
            ->first();

        $this->assertNotNull($draft, 'No draft thread was created.');

        return $draft;
    }

    /**
     * @param string $html
     *
     * @return string
     */
    protected function withoutInterTagWhitespace($html)
    {
        return trim(preg_replace('/>\s+</', '><', (string) $html));
    }
}
