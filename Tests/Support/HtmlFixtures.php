<?php

namespace Modules\AiChatPanel\Tests\Support;

/**
 * Real-shaped mail HTML.
 *
 * Hand-written fixtures are always tidier than what arrives in a mailbox, and
 * tidy input is exactly what a converter is not tested by. These keep the
 * things that actually break one: layout tables, Word's namespaced elements,
 * inline styles on everything, and a quoted reply chain.
 */
class HtmlFixtures
{
    /**
     * A Gmail reply, with the quote block Gmail appends.
     *
     * @return string
     */
    public static function gmailReply()
    {
        return '<div dir="ltr">Thanks, that worked.<div><br></div>'
            .'<div>Two follow-ups:</div>'
            .'<div><ul><li>when does the licence renew?</li><li>can we add a seat?</li></ul></div>'
            .'</div>'
            .'<div class="gmail_quote">'
            .'<div dir="ltr" class="gmail_attr">On Mon, 3 Feb 2025 at 14:02, Support &lt;support@example.com&gt; wrote:</div>'
            .'<blockquote class="gmail_quote" style="margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex">'
            .'<div>Please try clearing the cache.</div>'
            .'</blockquote></div>';
    }

    /**
     * A paste out of Word, as Outlook sends it.
     *
     * @return string
     */
    public static function wordPaste()
    {
        return '<div class="WordSection1">'
            .'<p class="MsoNormal"><span style="font-size:11.0pt;font-family:&quot;Calibri&quot;,sans-serif">'
            .'Please find the <b>revised</b> terms below.<o:p></o:p></span></p>'
            .'<p class="MsoListParagraph" style="text-indent:-18.0pt;mso-list:l0 level1 lfo1">'
            .'<span style="mso-list:Ignore">1.<span style="font:7.0pt &quot;Times New Roman&quot;">&nbsp;&nbsp;&nbsp;</span></span>'
            .'<span>Payment within 30 days<o:p></o:p></span></p>'
            .'<p class="MsoNormal"><o:p>&nbsp;</o:p></p>'
            .'</div>';
    }

    /**
     * An Apple Mail message: <br> for line breaks, no block elements at all.
     *
     * @return string
     */
    public static function appleMail()
    {
        return '<html><head><meta http-equiv="Content-Type" content="text/html charset=us-ascii">'
            .'</head><body style="word-wrap:break-word;-webkit-nbsp-mode:space">'
            .'Hi,<br><br>the invoice number is <b>INV-2201</b>.<br><br>Best,<br>Ada'
            .'</body></html>';
    }

    /**
     * A newsletter: three nested layout tables, a tracking pixel, a stylesheet.
     *
     * @return string
     */
    public static function newsletter()
    {
        return '<style type="text/css">.btn{background:#f00}.wrap{width:100%}</style>'
            .'<table width="100%" cellpadding="0" cellspacing="0" class="wrap"><tr><td align="center">'
            .'<table width="600" cellpadding="0" cellspacing="0"><tr><td>'
            .'<table cellpadding="0" cellspacing="0"><tr><td>'
            .'<h1 style="font-size:24px">March release</h1>'
            .'<p>We shipped <b>three</b> things this month.</p>'
            .'<p><a href="https://example.com/blog" style="color:#06c">Read the notes</a></p>'
            .'</td></tr></table>'
            .'</td></tr></table>'
            .'</td></tr></table>'
            .'<img src="https://track.example.com/o.gif?id=1" width="1" height="1" alt="">';
    }

    /**
     * A real data table: two columns, a header row, no block content in cells.
     *
     * @return string
     */
    public static function dataTable()
    {
        return '<table border="1"><thead><tr>'
            .'<th align="left">Item</th><th align="right">Price</th>'
            .'</tr></thead><tbody>'
            .'<tr><td>Licence</td><td align="right">120.00</td></tr>'
            .'<tr><td>Support</td><td align="right">40.00</td></tr>'
            .'</tbody></table>';
    }

    /**
     * What Summernote itself produces: <div> blocks and the empty-paragraph
     * sentinel between them.
     *
     * @return string
     */
    public static function summernoteBody()
    {
        return '<div>Hi <b>there</b>,</div>'
            .'<div><br></div>'
            .'<div>Here is what I found:</div>'
            .'<ul><li>the licence renews in March</li><li>a seat costs 40.00</li></ul>'
            .'<div><br></div>'
            .'<div>Let me know how you would like to proceed.<br>Ada</div>';
    }
}
