<?php

namespace Modules\AiChatPanel\Tests;

use Modules\AiChatPanel\Services\Markdown\EditorHtmlProfile;
use Modules\AiChatPanel\Services\Markdown\HtmlToMarkdown;
use Modules\AiChatPanel\Services\Markdown\MarkdownToHtml;

/**
 * Markdown to editor HTML.
 *
 * Three invariants hold this together, and every style string in
 * EditorHtmlProfile exists to satisfy them:
 *
 *   E1  no blank line outside <pre>, so core's AutoFormat.AutoParagraph has
 *       nothing to split and cannot inject <p> into our <li> or <div>;
 *   E2  a decorative empty element carries a non-breaking space, or core's
 *       AutoFormat.RemoveEmpty deletes it;
 *   E3  core's purifier changes nothing but the whitespace between tags.
 *
 * E3 is the acceptance test for the whole profile. If it fails, the emitted
 * markup is wrong — do not loosen the assertion.
 */
class MarkdownToHtmlTest extends AiChatPanelTestCase
{
    /**
     * Everything a model might reasonably write, in one document.
     *
     * @var string
     */
    protected $kitchen_sink = "# Heading one\n\n"
        ."Some **bold**, *italic*, ~~struck~~ and `inline code`.\n"
        ."A second line of the same paragraph.\n\n"
        ."## Heading two\n\n"
        ."- one\n"
        ."  - nested\n"
        ."- two\n\n"
        ."1. first\n"
        ."2. second\n\n"
        ."> quoted text\n\n"
        ."---\n\n"
        ."[a link](https://example.com/page)\n\n"
        ."| a | b |\n"
        ."|:--|--:|\n"
        ."| 1 | 2 |\n\n"
        ."```php\n<?php echo 1;\n```\n";

    // -----------------------------------------------------------------------
    // Construct by construct
    // -----------------------------------------------------------------------

    public function testParagraphsBecomeDivs()
    {
        $html = MarkdownToHtml::toEditorHtml("First.\n\nSecond.");

        $this->assertStringContainsString('<div>First.</div>', $html);
        $this->assertStringContainsString('<div>Second.</div>', $html);

        // Summernote's own block element is <div>; core allows <p> but the
        // editor never produces one.
        $this->assertStringNotContainsString('<p>', $html);
    }

    /**
     * A paragraph break has to read as a paragraph break in the editor.
     *
     * <div> carries no margin, so two of them in a row are consecutive lines
     * and nothing separates them: a draft's salutation, body and sign-off used
     * to arrive stacked. Summernote writes <div><br></div> for the blank line
     * a person makes by pressing Enter twice, and that is what goes between.
     *
     * @return void
     */
    public function testParagraphBreaksSurviveAsBlankLines()
    {
        $html = MarkdownToHtml::toEditorHtml("First.\n\nSecond.\n\nThird.");

        $this->assertSame(
            2,
            substr_count($html, '<div><br></div>'),
            'A blank line between paragraphs no longer produces the empty paragraph the editor '
                .'renders as a blank line, so the paragraphs run together.'
        );

        $this->assertStringContainsString('<div>First.</div><div><br></div><div>Second.</div>', $html);
    }

    /**
     * A block that already carries a bottom margin must not get a spacer too.
     *
     * @return void
     */
    public function testBlocksWithTheirOwnSpacingGetNoSpacer()
    {
        $html = MarkdownToHtml::toEditorHtml("Intro.\n\n- one\n- two\n\nOutro.");

        // Before the list: the paragraph is flush, so it needs one.
        $this->assertStringContainsString('<div>Intro.</div><div><br></div><ul', $html);

        // After it: <ul> is styled margin:0 0 10px 0, so a spacer would double
        // the gap.
        $this->assertStringContainsString('</ul><div>Outro.</div>', $html);
    }

    /**
     * The spacer must not leak into the markdown on the way back.
     *
     * HtmlToMarkdown drops <div><br></div> as the editor's empty paragraph, so
     * a draft the user edits and the panel re-reads is unchanged by the trip.
     *
     * @return void
     */
    public function testTheSpacerRoundTripsAway()
    {
        $markdown = "Sehr geehrte Frau Meier,\n\nvielen Dank.\nBis bald.\n\nMit freundlichen Grüßen";

        $this->assertSame(
            $markdown,
            HtmlToMarkdown::convert(MarkdownToHtml::toEditorHtml($markdown)),
            'The empty paragraphs inserted for spacing come back as content, so every round '
                .'trip through the editor grows the draft.'
        );
    }

    public function testSingleNewlinesBecomeLineBreaks()
    {
        $html = MarkdownToHtml::toEditorHtml("one\ntwo");

        $this->assertStringContainsString('<br>', $html);
        $this->assertStringNotContainsString('<br />', $html);
    }

    public function testHeadingsCarryExplicitSizes()
    {
        for ($level = 1; $level <= 6; $level++) {
            $html = MarkdownToHtml::toEditorHtml(str_repeat('#', $level).' Title');

            $this->assertStringContainsString('<h'.$level.' style="font-size:', $html);
            $this->assertStringContainsString('font-weight:bold', $html);
        }
    }

    public function testEmphasisSurvives()
    {
        $html = MarkdownToHtml::toEditorHtml('**bold** and *italic*');

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
    }

    public function testStrikethroughBecomesSNotDel()
    {
        // <del> is not in core's HTML.Allowed; <s> is.
        $html = MarkdownToHtml::toEditorHtml('~~struck~~');

        $this->assertStringContainsString('<s>struck</s>', $html);
        $this->assertStringNotContainsString('<del', $html);
    }

    public function testListsIncludingNesting()
    {
        $html = MarkdownToHtml::toEditorHtml("- one\n  - nested\n- two");

        $this->assertStringContainsString('<ul style="margin:', $html);

        // No style on <li>: FreeScout's stylesheet gives list items no margin
        // of their own, so neither do we.
        $this->assertStringContainsString('<li>', $html);

        // The nested list lives inside its parent item, not beside it.
        $this->assertStringContainsString('nested', $html);
        $this->assertEquals(2, substr_count($html, '<ul '));
    }

    public function testOrderedLists()
    {
        $html = MarkdownToHtml::toEditorHtml("1. first\n2. second");

        $this->assertStringContainsString('<ol style="margin:', $html);
        $this->assertEquals(2, substr_count($html, '<li>'));
    }

    public function testLinksCarryNoTargetOrRel()
    {
        // rel is not in core's whitelist, so emitting one would mean our HTML
        // and core's re-purified copy disagree (E3).
        $html = MarkdownToHtml::toEditorHtml('[docs](https://example.com/page)');

        $this->assertStringContainsString('href="https://example.com/page"', $html);
        $this->assertStringNotContainsString('target=', $html);
        $this->assertStringNotContainsString('rel=', $html);
    }

    public function testBlockquotesAreStyled()
    {
        $html = MarkdownToHtml::toEditorHtml('> quoted');

        $this->assertStringContainsString('<blockquote style="', $html);

        // FreeScout's own blockquote rule (core/public/css/bootstrap.css:1488),
        // so a quote the assistant writes matches one an agent inserts.
        $this->assertStringContainsString('border-left:2px solid #e3e8eb', $html);
        $this->assertStringContainsString('quoted', $html);
    }

    public function testHorizontalRuleBecomesAStyledDiv()
    {
        // <hr> is not in core's whitelist.
        $html = MarkdownToHtml::toEditorHtml("before\n\n---\n\nafter");

        $this->assertStringNotContainsString('<hr', $html);
        $this->assertStringContainsString('border-top:1px solid', $html);

        // The non-breaking space is what stops AutoFormat.RemoveEmpty from
        // deleting the element. See testTheHorizontalRuleSurvivesRemoveEmpty().
        $this->assertStringContainsString("\xC2\xA0", $html);
    }

    public function testInlineCodeBecomesAStyledSpan()
    {
        // <code> is not in core's whitelist — today it is silently unwrapped
        // and the formatting is lost.
        $html = MarkdownToHtml::toEditorHtml('run `git status` first');

        $this->assertStringNotContainsString('<code', $html);
        $this->assertStringContainsString('font-family:monospace', $html);
        $this->assertStringContainsString('git status', $html);
    }

    public function testFencedCodeBecomesAStyledPre()
    {
        $html = MarkdownToHtml::toEditorHtml("```php\n<?php echo 1;\n```");

        $this->assertStringContainsString('<pre style="', $html);
        $this->assertStringContainsString('white-space:pre-wrap', $html);
        $this->assertStringNotContainsString('<code', $html);

        // The code itself is escaped, not rendered.
        $this->assertStringContainsString('&lt;?php echo 1;', $html);

        // The language hint cannot survive: class is kept only on <table>.
        $this->assertStringNotContainsString('language-php', $html);
    }

    public function testTablesGetAttributeBorders()
    {
        // border-collapse is not in core's CSS.AllowedProperties, so the
        // borders have to be attributes — which is what mail clients honour.
        $html = MarkdownToHtml::toEditorHtml("| a | b |\n|:--|--:|\n| 1 | 2 |");

        $this->assertStringContainsString('border="1" cellspacing="0" cellpadding="8"', $html);
        $this->assertStringContainsString('<th style="text-align:left;', $html);
        $this->assertStringContainsString('<td style="text-align:right;', $html);

        // Cell borders inline as well, for the mail client that has no
        // stylesheet to read .table-bordered from.
        $this->assertStringContainsString('border:1px solid #dddddd', $html);
    }

    public function testTablesCarrySummernotesOwnClass()
    {
        // 'table table-bordered' is Summernote's own default (tableClassName,
        // summernote.js:7238), so it is what a hand-inserted table carries.
        // <table> is the one element where core's purifier keeps a class, so
        // ours looks identical in the conversation view — and Summernote's
        // table controls treat both the same.
        $html = MarkdownToHtml::toEditorHtml("| a | b |\n|---|---|\n| 1 | 2 |");

        $this->assertStringContainsString('<table class="table table-bordered"', $html);
        $this->assertStringContainsString('class="table table-bordered"', \Helper::purifyHtml($html));
    }

    public function testImagesAreDropped()
    {
        $html = MarkdownToHtml::toEditorHtml('![alt](https://example.com/pixel.gif)');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('example.com/pixel.gif', $html);
    }

    public function testDataUriImagesNeverReachAThreadBody()
    {
        // core's URI.AllowedSchemes includes data:, and
        // Thread::replaceBase64ImagesWithAttachments() only runs on
        // user-submitted replies — so a base64 image from a tool-written draft
        // would be stored verbatim.
        $html = MarkdownToHtml::toEditorHtml('![x](data:image/png;base64,iVBORw0KGgo=)');

        $this->assertStringNotContainsString('data:image', $html);
        $this->assertStringNotContainsString('base64', $html);
    }

    public function testEmptyInputProducesEmptyOutput()
    {
        $this->assertEquals('', MarkdownToHtml::toEditorHtml(''));
        $this->assertEquals('', MarkdownToHtml::toEditorHtml('   '));
    }

    // -----------------------------------------------------------------------
    // Untrusted input
    // -----------------------------------------------------------------------

    public function testScriptTagsAreRemoved()
    {
        $html = MarkdownToHtml::toEditorHtml("Hello\n\n<script>alert('xss')</script>");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert(', $html);
    }

    public function testEventHandlerAttributesAreRemoved()
    {
        $html = MarkdownToHtml::toEditorHtml('<span onclick="alert(1)">hi</span>');

        $this->assertStringNotContainsString('onclick', $html);
    }

    public function testJavascriptUrlsAreStripped()
    {
        $html = MarkdownToHtml::toEditorHtml('[click me](javascript:alert(1))');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('click me', $html);
    }

    public function testIframesAndObjectsAreRemoved()
    {
        $html = MarkdownToHtml::toEditorHtml('<iframe src="https://evil.example.com"></iframe><object data="x"></object>');

        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<object', $html);
    }

    // -----------------------------------------------------------------------
    // The invariants
    // -----------------------------------------------------------------------

    public function testEditorHtmlEmitsNoDisallowedTags()
    {
        $html = MarkdownToHtml::toEditorHtml($this->kitchen_sink);

        foreach (['<code', '<hr', '<del', '<img', '<p>', '<p '] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $html,
                $forbidden.' is dropped by core/config/purifier.php, so emitting it loses the formatting.'
            );
        }
    }

    public function testEditorHtmlSurvivesCoresPurifierUnchanged()
    {
        // E3. Core purifies threads.body both when displaying it and when
        // rendering it into outgoing mail, so anything our profile emits that
        // core does not allow is lost between saving a draft and sending it.
        $this->assertSurvivesCorePurifier(MarkdownToHtml::toEditorHtml($this->kitchen_sink));
    }

    public function testEditorHtmlHasNoBlankLinesOutsidePre()
    {
        // E1. HTMLPurifier's AutoParagraph injector splits on a blank line, and
        // core has it enabled.
        $html = MarkdownToHtml::toEditorHtml($this->kitchen_sink);
        $without_pre = preg_replace('#<pre\b[^>]*>.*?</pre>#is', '', $html);

        $this->assertStringNotContainsString("\n\n", $without_pre);
    }

    public function testTheHorizontalRuleSurvivesRemoveEmpty()
    {
        // E2. Without the &nbsp; core's AutoFormat.RemoveEmpty deletes the div.
        $purified = \Helper::purifyHtml(MarkdownToHtml::toEditorHtml("above\n\n---\n\nbelow"));

        $this->assertStringContainsString('border-top:1px solid', $purified);
    }

    public function testCodeBlocksSurviveCoresPurifier()
    {
        // A <pre> is the one place a blank line is legitimate, so it is worth
        // its own pass through core.
        $this->assertSurvivesCorePurifier(
            MarkdownToHtml::toEditorHtml("```\nline one\n\nline three\n```")
        );
    }

    // -----------------------------------------------------------------------
    // The panel profile is untouched by all of this
    // -----------------------------------------------------------------------

    public function testPanelProfileStillProducesCanonicalHtml()
    {
        $html = MarkdownToHtml::toPanelHtml($this->kitchen_sink);

        $this->assertStringContainsString('<p>', $html);
        $this->assertStringContainsString('<h1>', $html);
        $this->assertStringContainsString('<pre><code>', $html);
        $this->assertStringContainsString('<hr', $html);
        $this->assertStringContainsString('<del>', $html);

        // Unstyled: the panel is styled by Public/css/module.css.
        $this->assertStringNotContainsString('font-family:monospace', $html);
    }

    public function testTheTwoProfilesDisagreeOnlyWhereTheyMust()
    {
        $this->assertEquals('div', EditorHtmlProfile::editor()->blockTag());
        $this->assertEquals('p', EditorHtmlProfile::panel()->blockTag());

        $this->assertTrue(EditorHtmlProfile::editor()->retargets());
        $this->assertFalse(EditorHtmlProfile::panel()->retargets());

        // Neither one lets an image through.
        $this->assertFalse(EditorHtmlProfile::editor()->allowsImages());
        $this->assertFalse(EditorHtmlProfile::panel()->allowsImages());
    }

    // -----------------------------------------------------------------------

    /**
     * Assert that core's purifier changes nothing but the whitespace between
     * tags.
     *
     * It is not byte-for-byte: core has AutoFormat.AutoParagraph on, and
     * HTMLPurifier's AutoParagraph injector emits "\n\n" separators between
     * top-level blocks even when it creates no paragraph. That whitespace is
     * cosmetic. A difference in any tag, attribute or declaration is not.
     *
     * @param string $html
     *
     * @return void
     */
    protected function assertSurvivesCorePurifier($html)
    {
        $this->assertEquals(
            $this->withoutInterTagWhitespace($html),
            $this->withoutInterTagWhitespace(\Helper::purifyHtml($html)),
            'core/config/purifier.php changed the markup, so it would not survive being displayed or sent.'
        );
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
