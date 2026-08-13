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

    /**
     * module.js builds its markup as strings, and its escapeHtml() is text-node
     * escaping: it leaves " and ' alone, which is correct between tags and
     * wrong inside an attribute. data-body carries raw model output, so a
     * single quote in an answer would have closed the attribute and let the
     * rest of the answer be parsed as further attributes — an onmouseover=
     * among them.
     *
     * There is no JavaScript test harness here, so guard the rule statically:
     * every value interpolated into a double-quoted attribute goes through
     * escapeAttr(), never escapeHtml().
     *
     * @return void
     */
    public function testAttributeValuesInTheClientRendererAreQuoteEscaped()
    {
        $js = file_get_contents(__DIR__.'/../Public/js/module.js');

        $this->assertStringContainsString(
            'function escapeAttr(',
            $js,
            'escapeAttr() is gone; attribute values have nothing escaping their quotes.'
        );

        // It has to add the quote characters on top of escapeHtml, or it is
        // just escapeHtml under another name.
        $this->assertMatchesRegularExpression(
            '/function escapeAttr\([^)]*\)\s*\{[^}]*&quot;[^}]*\}/s',
            $js,
            'escapeAttr() no longer escapes the double quote.'
        );

        // Every `foo="' + something` in the file, with what follows it.
        preg_match_all('/="\'\s*\+\s*(\w+)\(/', $js, $matches, PREG_OFFSET_CAPTURE);

        $this->assertNotEmpty($matches[1], 'The attribute-building pattern changed; this guard no longer checks anything.');

        foreach ($matches[1] as $match) {
            $this->assertEquals(
                'escapeAttr',
                $match[0],
                'An attribute value at offset '.$match[1].' of module.js is built with '
                .$match[0].'(), which does not escape quotes. Use escapeAttr().'
            );
        }
    }
}
