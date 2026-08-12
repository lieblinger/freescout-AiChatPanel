<?php

namespace Modules\AiChatPanel\Tests;

use Modules\AiChatPanel\Services\MarkdownRenderer;

/**
 * Model output is untrusted.
 *
 * The model reads customer-written thread content, so "the model would not
 * produce that" is not an argument. Everything it writes is rendered through
 * Parsedown and then HTMLPurifier before it reaches a browser.
 */
class OutputSanitisingTest extends AiChatPanelTestCase
{
    public function testScriptTagsAreRemoved()
    {
        $html = MarkdownRenderer::render("Hello\n\n<script>alert('xss')</script>");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert(', $html);
    }

    public function testEventHandlerAttributesAreRemoved()
    {
        $html = MarkdownRenderer::render('<img src=x onerror="alert(1)">');

        $this->assertStringNotContainsString('onerror', $html);
    }

    public function testImagesAreRemovedEntirely()
    {
        // An image URL is a request from the agent's browser to an arbitrary
        // host, i.e. a tracking pixel at best. Core's own purifier config keeps
        // these, which is why the module has its own.
        $html = MarkdownRenderer::render('![alt](https://tracker.example.com/pixel.gif)');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('tracker.example.com', $html);
    }

    public function testJavascriptUrlsAreStripped()
    {
        $html = MarkdownRenderer::render('[click me](javascript:alert(1))');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('click me', $html);
    }

    public function testIframesAndObjectsAreRemoved()
    {
        $html = MarkdownRenderer::render('<iframe src="https://evil.example.com"></iframe><object data="x"></object>');

        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<object', $html);
    }

    public function testExternalLinksGetSafeRelAndTarget()
    {
        $html = MarkdownRenderer::render('[docs](https://example.com/page)');

        $this->assertStringContainsString('href="https://example.com/page"', $html);
        $this->assertStringContainsString('noopener', $html);
        $this->assertStringContainsString('target="_blank"', $html);
    }

    public function testOrdinaryMarkdownSurvives()
    {
        $html = MarkdownRenderer::render(
            "# Heading\n\nSome **bold** and `code`.\n\n- one\n- two\n\n```php\n<?php echo 1;\n```\n\n| a | b |\n|---|---|\n| 1 | 2 |"
        );

        $this->assertStringContainsString('<h1>', $html);
        $this->assertStringContainsString('<strong>', $html);
        $this->assertStringContainsString('<li>', $html);
        $this->assertStringContainsString('<table>', $html);

        // <code> inside <pre> must survive; core's purifier config strips it.
        $this->assertStringContainsString('<pre><code>', $html);
        $this->assertStringContainsString('&lt;?php echo 1;', $html);
    }

    public function testEmptyInputProducesEmptyOutput()
    {
        $this->assertEquals('', MarkdownRenderer::render(''));
        $this->assertEquals('', MarkdownRenderer::render('   '));
    }

    public function testAssistantTurnsArePersistedWithRenderedHtml()
    {
        $chat = \Modules\AiChatPanel\Entities\Chat::findOrCreateFor(
            $this->conversation->id,
            $this->agent->id
        );

        $message = \Modules\AiChatPanel\Entities\Message::create([
            'chat_id'   => $chat->id,
            'role'      => \Modules\AiChatPanel\Entities\Message::ROLE_ASSISTANT,
            'body'      => 'Hello <script>alert(1)</script>',
            'body_html' => MarkdownRenderer::render('Hello <script>alert(1)</script>'),
        ]);

        $panel = $message->toPanelArray();

        $this->assertStringNotContainsString('<script', $panel['html']);
        $this->assertStringContainsString('Hello', $panel['html']);
    }
}
