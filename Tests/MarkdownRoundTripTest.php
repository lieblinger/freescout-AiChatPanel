<?php

namespace Modules\AiChatPanel\Tests;

use Modules\AiChatPanel\Services\Markdown\HtmlToMarkdown;
use Modules\AiChatPanel\Services\Markdown\MarkdownToHtml;
use Modules\AiChatPanel\Tests\Support\HtmlFixtures;

/**
 * The two converters, back to back.
 *
 * The property worth having is stability, not identity. md → html → md' may
 * differ — escaping appears, "1)" becomes "1.", a loose list tightens — but
 * md' → html' → md'' must give md' back, or the text drifts a little further
 * every time an answer is inserted, re-read and rewritten.
 *
 * Identity is not worth having: no consumer needs it. Two of them feed a
 * language model, which reads meaning; the third feeds a human who reviews the
 * draft before sending it.
 */
class MarkdownRoundTripTest extends AiChatPanelTestCase
{
    /** @var string */
    protected $document = "# Title\n\n"
        ."Some **bold**, *italic*, ~~struck~~ and `code`.\n\n"
        ."- one\n"
        ."    - nested\n"
        ."- two\n\n"
        ."1. first\n"
        ."2. second\n\n"
        ."> quoted\n\n"
        ."---\n\n"
        ."[link](https://example.com)\n\n"
        ."| a | b |\n"
        ."| :--- | ---: |\n"
        ."| 1 | 2 |";

    public function testTheSecondRoundTripChangesNothing()
    {
        $once = HtmlToMarkdown::fromEditor(MarkdownToHtml::toEditorHtml($this->document));
        $twice = HtmlToMarkdown::fromEditor(MarkdownToHtml::toEditorHtml($once));

        $this->assertEquals($once, $twice, 'The conversion is not a fixed point, so text drifts on every pass.');
    }

    public function testEveryConstructSurvivesOneRoundTrip()
    {
        $markdown = HtmlToMarkdown::fromEditor(MarkdownToHtml::toEditorHtml($this->document));

        $this->assertStringContainsString('# Title', $markdown);
        $this->assertStringContainsString('**bold**', $markdown);
        $this->assertStringContainsString('*italic*', $markdown);
        $this->assertStringContainsString('~~struck~~', $markdown);
        $this->assertStringContainsString('`code`', $markdown);
        $this->assertStringContainsString("- one\n    - nested", $markdown);
        $this->assertStringContainsString('1. first', $markdown);
        $this->assertStringContainsString('> quoted', $markdown);
        $this->assertStringContainsString('---', $markdown);
        $this->assertStringContainsString('[link](https://example.com)', $markdown);
        $this->assertStringContainsString('| a | b |', $markdown);
    }

    public function testCodeBlocksSurviveExceptTheirLanguage()
    {
        $markdown = HtmlToMarkdown::fromEditor(
            MarkdownToHtml::toEditorHtml("```php\n<?php echo 1;\n```")
        );

        $this->assertStringContainsString("<?php echo 1;", $markdown);

        // The language hint cannot survive: `class` is kept only on <table>, so
        // <code class="language-php"> has nowhere to live in a thread body.
        $this->assertStringNotContainsString('```php', $markdown);
    }

    public function testEditorHtmlRoundTripsToEditorShapedHtml()
    {
        $html = MarkdownToHtml::toEditorHtml(
            HtmlToMarkdown::fromEditor(HtmlFixtures::summernoteBody())
        );

        $this->assertStringContainsString('<div>', $html);
        $this->assertStringContainsString('<strong>there</strong>', $html);
        $this->assertStringContainsString('<ul style=', $html);
        $this->assertStringNotContainsString('<p>', $html);
    }

    public function testAThreadBodyBecomesADraftAndBackWithoutLoss()
    {
        // The path an answer actually takes: the model reads a customer mail as
        // Markdown, writes Markdown back, and it is stored as editor HTML.
        $markdown = HtmlToMarkdown::fromThread(HtmlFixtures::gmailReply());
        $html = MarkdownToHtml::toEditorHtml($markdown);

        $this->assertStringContainsString('Thanks, that worked.', $html);
        $this->assertStringContainsString('<li>', $html);
        $this->assertEquals(
            $this->withoutInterTagWhitespace($html),
            $this->withoutInterTagWhitespace(\Helper::purifyHtml($html))
        );
    }

    public function testEscapingIsIdempotent()
    {
        // A body full of Markdown metacharacters must not grow a backslash on
        // every pass.
        $html = '<div>2 * 3, _x_, [y], a `tick` and C:\\path</div>';

        $once = HtmlToMarkdown::fromEditor($html);
        $twice = HtmlToMarkdown::fromEditor(MarkdownToHtml::toEditorHtml($once));

        $this->assertEquals($once, $twice);
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
