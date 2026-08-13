<?php

namespace Modules\AiChatPanel\Tests;

use Modules\AiChatPanel\Services\Markdown\HtmlToMarkdown;
use Modules\AiChatPanel\Tests\Support\HtmlFixtures;

/**
 * Thread and editor HTML to Markdown.
 *
 * What matters here is that the model receives the structure the writer meant:
 * a list stays a list, a link keeps its target, and a layout table does not
 * turn into a grid of pipes the model has to decode.
 */
class HtmlToMarkdownTest extends AiChatPanelTestCase
{
    // -----------------------------------------------------------------------
    // Summernote's own shapes
    // -----------------------------------------------------------------------

    public function testDivsAreParagraphs()
    {
        $markdown = HtmlToMarkdown::fromEditor('<div>first</div><div>second</div>');

        $this->assertEquals("first\n\nsecond", $markdown);
    }

    public function testTheEmptyParagraphSentinelIsNotContent()
    {
        // <div><br></div> is Summernote's emptyPara. Treating it as content
        // would add a blank line to the prompt for every one the agent typed.
        $markdown = HtmlToMarkdown::fromEditor('<div>a</div><div><br></div><div>b</div>');

        $this->assertEquals("a\n\nb", $markdown);
    }

    public function testLineBreaksBecomeNewlines()
    {
        $markdown = HtmlToMarkdown::fromEditor('<div>a<br>b</div>');

        $this->assertEquals("a\nb", $markdown);
    }

    public function testEmphasis()
    {
        $markdown = HtmlToMarkdown::fromEditor('<div><b>bold</b> <i>italic</i> <s>struck</s></div>');

        $this->assertEquals('**bold** *italic* ~~struck~~', $markdown);
    }

    public function testMarkersSitAgainstTheirText()
    {
        // "** bold **" is not emphasis in any Markdown dialect.
        $markdown = HtmlToMarkdown::fromEditor('<div>a <b> bold </b> b</div>');

        $this->assertEquals('a **bold** b', $markdown);
    }

    public function testUnderlinePassesThroughAsHtml()
    {
        // Markdown has no underline. The tag round-trips because both profiles
        // allow <u>.
        $markdown = HtmlToMarkdown::fromEditor('<div>plain <u>under</u></div>');

        $this->assertEquals('plain <u>under</u>', $markdown);
    }

    public function testNestedListsIndentByFourSpaces()
    {
        $markdown = HtmlToMarkdown::fromEditor('<ul><li>one<ul><li>nested</li></ul></li><li>two</li></ul>');

        $this->assertEquals("- one\n    - nested\n- two", $markdown);
    }

    public function testOrderedListsKeepTheirNumbering()
    {
        $markdown = HtmlToMarkdown::fromEditor('<ol start="3"><li>three</li><li>four</li></ol>');

        $this->assertEquals("3. three\n4. four", $markdown);
    }

    public function testBlockquotesNest()
    {
        $markdown = HtmlToMarkdown::fromEditor(
            '<blockquote><div>outer</div><blockquote><div>inner</div></blockquote></blockquote>'
        );

        $this->assertStringContainsString('> outer', $markdown);
        $this->assertStringContainsString('> > inner', $markdown);
    }

    public function testHeadings()
    {
        $markdown = HtmlToMarkdown::fromEditor('<h1>One</h1><h3>Three</h3>');

        $this->assertEquals("# One\n\n### Three", $markdown);
    }

    public function testLinks()
    {
        $markdown = HtmlToMarkdown::fromEditor(
            '<div><a href="https://example.com/a" target="_blank">text</a></div>'
        );

        $this->assertEquals('[text](https://example.com/a)', $markdown);
    }

    public function testALinkWhoseTextIsItsUrlBecomesAnAutolink()
    {
        $markdown = HtmlToMarkdown::fromEditor('<div><a href="https://example.com">https://example.com</a></div>');

        $this->assertEquals('<https://example.com>', $markdown);
    }

    public function testAnUnusableSchemeKeepsOnlyTheText()
    {
        $markdown = HtmlToMarkdown::fromEditor('<div><a href="javascript:alert(1)">click</a></div>');

        $this->assertEquals('click', $markdown);
        $this->assertStringNotContainsString('javascript', $markdown);
    }

    // -----------------------------------------------------------------------
    // Code
    // -----------------------------------------------------------------------

    public function testPreBecomesAFencedBlock()
    {
        $markdown = HtmlToMarkdown::fromEditor('<pre><code class="language-php">echo 1;</code></pre>');

        $this->assertEquals("```php\necho 1;\n```", $markdown);
    }

    public function testTheFenceWidensPastBackticksInTheCode()
    {
        $markdown = HtmlToMarkdown::fromEditor('<pre>a ``` b</pre>');

        $this->assertStringStartsWith('````', $markdown);
        $this->assertStringContainsString('a ``` b', $markdown);
    }

    public function testAMonospaceSpanIsRecognisedAsInlineCode()
    {
        // This is what MarkdownToHtml emits for `code`, because core's
        // whitelist has no <code> outside <pre>.
        $markdown = HtmlToMarkdown::fromEditor(
            '<div>run <span style="font-family:monospace; background-color:#f4f4f4;">git status</span></div>'
        );

        $this->assertEquals('run `git status`', $markdown);
    }

    public function testABorderedEmptyDivIsRecognisedAsARule()
    {
        $markdown = HtmlToMarkdown::fromEditor(
            '<div>a</div><div style="border-top:1px solid #cccccc; height:1px;">&nbsp;</div><div>b</div>'
        );

        $this->assertEquals("a\n\n---\n\nb", $markdown);
    }

    // -----------------------------------------------------------------------
    // Tables
    // -----------------------------------------------------------------------

    public function testADataTableBecomesAPipeTable()
    {
        $markdown = HtmlToMarkdown::fromThread(HtmlFixtures::dataTable());

        $this->assertStringContainsString('| Item | Price |', $markdown);
        $this->assertStringContainsString('| :--- | ---: |', $markdown);
        $this->assertStringContainsString('| Licence | 120.00 |', $markdown);
    }

    public function testPipesInsideACellAreEscaped()
    {
        $markdown = HtmlToMarkdown::fromThread(
            '<table><tr><th>a</th><th>b</th></tr><tr><td>1</td><td>x | y</td></tr></table>'
        );

        $this->assertStringContainsString('x \\| y', $markdown);
    }

    public function testCellsEmptiedByTheEditorDoNotBecomeLiteralMarkup()
    {
        // Summernote fills a new cell with dom.blank, i.e. <br>. A row added
        // with the editor's own table controls is therefore all <br>, and the
        // model must not read that as content.
        $markdown = HtmlToMarkdown::fromEditor(
            '<table><tr><th>a</th><th>b</th></tr>'
            .'<tr><td>1</td><td>2</td></tr>'
            .'<tr><td><br></td><td><br></td></tr></table>'
        );

        $this->assertStringNotContainsString('<br>', $markdown);
        $this->assertStringContainsString('|  |  |', $markdown);
    }

    public function testALayoutTableIsUnwrapped()
    {
        // Rendering a mail's grid system as a pipe table gives the model
        // something to decode instead of something to read.
        $markdown = HtmlToMarkdown::fromThread(HtmlFixtures::newsletter());

        $this->assertStringNotContainsString('|', $markdown);
        $this->assertStringContainsString('# March release', $markdown);
        $this->assertStringContainsString('We shipped **three** things', $markdown);
        $this->assertStringContainsString('[Read the notes](https://example.com/blog)', $markdown);
    }

    public function testAStylesheetDoesNotReachThePrompt()
    {
        $markdown = HtmlToMarkdown::fromThread(HtmlFixtures::newsletter());

        $this->assertStringNotContainsString('background:#f00', $markdown);
        $this->assertStringNotContainsString('.wrap', $markdown);
    }

    // -----------------------------------------------------------------------
    // Text handling
    // -----------------------------------------------------------------------

    public function testEntitiesAreDecoded()
    {
        $markdown = HtmlToMarkdown::fromThread('<div>caf&eacute; &amp; co &#8217;quoted&#8217;</div>');

        $this->assertEquals('café & co ’quoted’', $markdown);
    }

    public function testNonBreakingSpacesBecomeOrdinarySpaces()
    {
        $markdown = HtmlToMarkdown::fromThread('<div>a&nbsp;&nbsp;b</div>');

        $this->assertEquals('a b', $markdown);
        $this->assertStringNotContainsString("\xC2\xA0", $markdown);
    }

    public function testSnakeCaseIsNotEscaped()
    {
        // Escaping every underscore turns identifiers into noise the model has
        // to see through.
        $markdown = HtmlToMarkdown::fromThread('<div>the max_context_tokens setting</div>');

        $this->assertEquals('the max_context_tokens setting', $markdown);
    }

    public function testEmphasisMarkersInProseAreEscaped()
    {
        $markdown = HtmlToMarkdown::fromThread('<div>2 * 3 and _emphasis_ and [x]</div>');

        $this->assertEquals('2 \\* 3 and \\_emphasis\\_ and \\[x\\]', $markdown);
    }

    public function testBlockMarkersAreEscapedOnlyAtLineStart()
    {
        $markdown = HtmlToMarkdown::fromThread(
            '<div># not a heading</div><div>- not a list</div><div>1. not ordered</div><div>a # b</div>'
        );

        $this->assertStringContainsString('\\# not a heading', $markdown);
        $this->assertStringContainsString('\\- not a list', $markdown);
        $this->assertStringContainsString('1\\. not ordered', $markdown);
        $this->assertStringContainsString('a # b', $markdown);
    }

    public function testASeparatorLineIsNeverEscaped()
    {
        // ThreadFormatter::stripQuotedText() and stripSignature() match on
        // exactly these lines; escaping them would silently disable both.
        $markdown = HtmlToMarkdown::fromThread('<div>text</div><div>______________</div><div>more</div>');

        $this->assertStringContainsString("\n______________\n", $markdown);
    }

    public function testTagLikeTextIsNeutralised()
    {
        // "\<" is not a Parsedown escape sequence, so it has to be an entity.
        $markdown = HtmlToMarkdown::fromThread('<div>use the &lt;body&gt; element</div>');

        $this->assertStringContainsString('&lt;body', $markdown);
        $this->assertStringNotContainsString('\\<', $markdown);
    }

    // -----------------------------------------------------------------------
    // Images and unknown markup
    // -----------------------------------------------------------------------

    public function testInlineAndDataImagesBecomePlaceholders()
    {
        $markdown = HtmlToMarkdown::fromThread(
            '<div><img src="cid:abc123" alt="logo"> and <img src="data:image/png;base64,iVBOR" alt="x"></div>'
        );

        $this->assertStringContainsString('[image: logo]', $markdown);
        $this->assertStringNotContainsString('cid:', $markdown);
        $this->assertStringNotContainsString('base64', $markdown);
    }

    public function testRemoteImagesCanBeKeptAsMarkdown()
    {
        $markdown = HtmlToMarkdown::convert(
            '<div><img src="https://example.com/a.png" alt="chart"></div>',
            ['images' => 'markdown']
        );

        $this->assertEquals('![chart](https://example.com/a.png)', $markdown);
    }

    public function testUnknownElementsAreUnwrapped()
    {
        $markdown = HtmlToMarkdown::fromThread('<section><font color="red">red</font> <span>span</span></section>');

        $this->assertEquals('red span', $markdown);
    }

    public function testScriptsAndStylesAreDroppedWithTheirContent()
    {
        $markdown = HtmlToMarkdown::fromThread(
            '<div>keep</div><script>alert(1)</script><style>.a{color:red}</style>'
        );

        $this->assertEquals('keep', $markdown);
    }

    // -----------------------------------------------------------------------
    // Real mail
    // -----------------------------------------------------------------------

    public function testAGmailReplyKeepsItsStructure()
    {
        $markdown = HtmlToMarkdown::fromThread(HtmlFixtures::gmailReply());

        $this->assertStringContainsString('Thanks, that worked.', $markdown);
        $this->assertStringContainsString('- when does the licence renew?', $markdown);
        $this->assertStringContainsString('- can we add a seat?', $markdown);

        // The quote itself is ThreadFormatter's job, not the converter's, but
        // the markers it looks for have to survive.
        $this->assertStringContainsString('wrote:', $markdown);
    }

    public function testAWordPasteComesOutAsProse()
    {
        $markdown = HtmlToMarkdown::fromThread(HtmlFixtures::wordPaste());

        $this->assertStringContainsString('Please find the **revised** terms below.', $markdown);
        $this->assertStringContainsString('Payment within 30 days', $markdown);
        $this->assertStringNotContainsString('mso-list', $markdown);
        $this->assertStringNotContainsString('Calibri', $markdown);
    }

    public function testAnAppleMailMessageKeepsItsLineBreaks()
    {
        $markdown = HtmlToMarkdown::fromThread(HtmlFixtures::appleMail());

        $this->assertStringContainsString('the invoice number is **INV-2201**.', $markdown);
        $this->assertStringContainsString("Best,\nAda", $markdown);
        $this->assertStringNotContainsString('word-wrap', $markdown);
    }

    public function testASummernoteBodyComesBackAsCleanMarkdown()
    {
        $markdown = HtmlToMarkdown::fromEditor(HtmlFixtures::summernoteBody());

        $this->assertEquals(
            "Hi **there**,\n\n"
            // The blank line before the list is mandatory: without it
            // Parsedown reads "- the licence..." as one more line of the
            // paragraph, and the list stops being a list.
            ."Here is what I found:\n\n"
            ."- the licence renews in March\n"
            ."- a seat costs 40.00\n\n"
            ."Let me know how you would like to proceed.\nAda",
            $markdown
        );
    }

    // -----------------------------------------------------------------------
    // Degrading, not breaking
    // -----------------------------------------------------------------------

    public function testEmptyInputProducesEmptyOutput()
    {
        $this->assertEquals('', HtmlToMarkdown::fromThread(''));
        $this->assertEquals('', HtmlToMarkdown::fromThread('   '));
        $this->assertEquals('', HtmlToMarkdown::fromEditor('<div><br></div>'));
    }

    public function testAnOversizedBodyFallsBackToPlainText()
    {
        $html = '<div>'.str_repeat('word ', 300000).'</div>';

        $this->assertGreaterThan(HtmlToMarkdown::MAX_INPUT, mb_strlen($html));
        $this->assertStringContainsString('word', HtmlToMarkdown::fromThread($html));
    }

    public function testPlainTextInputSurvives()
    {
        $this->assertEquals('just words', HtmlToMarkdown::fromThread('just words'));
    }
}
