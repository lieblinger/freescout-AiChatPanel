<?php

namespace Modules\AiChatPanel\Services\Markdown;

/**
 * Thread and editor HTML to Markdown, for the prompt.
 *
 * Written by hand rather than with league/html-to-markdown: the module has no
 * vendor/ of its own, and adding a package to core/composer.json for one module
 * is a core change we would then carry through every upstream sync.
 *
 * Two things make real mail different from clean HTML, and both are handled
 * deliberately:
 *
 *   - <div> is the block element, not <p>, and <div><br></div> is Summernote's
 *     empty paragraph. Treating it as content would put a stray blank line in
 *     the prompt for every one the agent typed.
 *   - Layout tables are everywhere. Rendering one as a GFM pipe table produces
 *     something the model has to decode rather than read, so a <table> becomes
 *     a pipe table only when it looks like data (see isDataTable()).
 *
 * Nothing here throws. A conversion bug must show up as a worse prompt, never
 * as a broken panel — hence the \Helper::htmlToText() fallback, which is what
 * this replaced.
 */
class HtmlToMarkdown
{
    /**
     * Beyond this, fall straight back to htmlToText(). One pathological mail
     * must not be able to spend the whole request budget in the DOM.
     */
    const MAX_INPUT = 1048576;

    /** Elements dropped together with their content. */
    protected static $dropped = [
        'script', 'style', 'head', 'title', 'meta', 'link', 'noscript', 'iframe',
        'object', 'embed', 'applet', 'template', 'svg', 'math', 'form', 'button',
        'select', 'textarea', 'input', 'option', 'map', 'area', 'canvas', 'audio',
        'video', 'colgroup', 'col',
    ];

    /** @var array */
    protected $options;

    /** @var int Inside how many table cells we currently are. */
    protected $in_cell = 0;

    /**
     * @param array $options
     */
    protected function __construct(array $options)
    {
        $this->options = $options + [
            // 'placeholder' | 'markdown' | 'drop'
            'images' => 'placeholder',
            'tables' => true,
        ];
    }

    /**
     * A stored thread body, as Markdown.
     *
     * @param string $html
     *
     * @return string
     */
    public static function fromThread($html)
    {
        return self::convert($html);
    }

    /**
     * What the agent currently has in the reply editor, as Markdown.
     *
     * @param string $html
     *
     * @return string
     */
    public static function fromEditor($html)
    {
        return self::convert($html);
    }

    /**
     * @param string $html
     * @param array  $options
     *
     * @return string
     */
    public static function convert($html, array $options = [])
    {
        $html = (string) $html;

        if (trim($html) === '') {
            return '';
        }

        if (mb_strlen($html) > self::MAX_INPUT) {
            return self::fallback($html);
        }

        // \Throwable, not \Exception: a malformed document can reach the DOM
        // recursion limit, and that is an Error. This method promises never to
        // throw, and the callers rely on it.
        try {
            // Removes <script> and <style> WITH their content. Without this a
            // newsletter's stylesheet would land in the prompt as text and cost
            // hundreds of tokens.
            $document = Dom::load(\Helper::stripDangerousTags($html));

            if (!$document) {
                return self::fallback($html);
            }

            $body = Dom::body($document);

            if (!$body) {
                return self::fallback($html);
            }

            $converter = new self($options);

            return $converter->tidy(
                $converter->joinBlocks($converter->blocks($body))
            );
        } catch (\Throwable $e) {
            \Helper::logException($e, '[AiChatPanel] HTML to Markdown conversion failed: ');

            return self::fallback($html);
        }
    }

    // -----------------------------------------------------------------------
    // Blocks
    // -----------------------------------------------------------------------

    /**
     * Split a container's children into blocks.
     *
     * Consecutive inline children are gathered into one paragraph, which is
     * what makes loose text in a <body> or a <td> come out as prose rather than
     * as one word per line.
     *
     * @param \DOMNode $parent
     *
     * @return array Each entry is ['kind' => string, 'text' => string].
     */
    protected function blocks(\DOMNode $parent)
    {
        $blocks = [];
        $buffer = '';

        foreach ($parent->childNodes as $child) {
            if (!$this->isBlockNode($child)) {
                $buffer .= $this->inlineNode($child);
                continue;
            }

            $blocks = $this->flush($blocks, $buffer);
            $buffer = '';

            foreach ($this->blockNode($child) as $block) {
                if ($block['text'] !== '') {
                    $blocks[] = $block;
                }
            }
        }

        return $this->flush($blocks, $buffer);
    }

    /**
     * @param array  $blocks
     * @param string $buffer
     *
     * @return array
     */
    protected function flush(array $blocks, $buffer)
    {
        $paragraph = $this->paragraph($buffer);

        if ($paragraph !== '') {
            $blocks[] = ['kind' => 'text', 'text' => $paragraph];
        }

        return $blocks;
    }

    /**
     * Join blocks with a blank line.
     *
     * Inside a list item a nested list follows its text directly, because a
     * blank line there makes the list loose and costs a line per item.
     * Everywhere else the blank line is mandatory: "text" on one line and
     * "- item" on the next is one paragraph to Parsedown, not a paragraph and
     * a list, and two adjacent lists would merge into one nested list.
     *
     * @param array $blocks
     * @param bool  $tight
     *
     * @return string
     */
    protected function joinBlocks(array $blocks, $tight = false)
    {
        $out = '';

        foreach ($blocks as $index => $block) {
            if ($index > 0) {
                $out .= ($tight && $block['kind'] === 'list') ? "\n" : "\n\n";
            }

            $out .= $block['text'];
        }

        return $out;
    }

    /**
     * @param \DOMNode $node
     *
     * @return bool
     */
    protected function isBlockNode(\DOMNode $node)
    {
        if (!$node instanceof \DOMElement) {
            return false;
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, self::$dropped, true)) {
            return true;
        }

        return Dom::isBlock($tag);
    }

    /**
     * @param \DOMElement $element
     *
     * @return array Zero or more ['kind' =>, 'text' =>] entries.
     */
    protected function blockNode(\DOMElement $element)
    {
        $tag = strtolower($element->nodeName);

        if (in_array($tag, self::$dropped, true)) {
            return [];
        }

        switch ($tag) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $text = $this->collapseSpaces($this->inline($element));

                return $text === '' ? [] : [[
                    'kind' => 'text',
                    'text' => str_repeat('#', (int) substr($tag, 1)).' '.$text,
                ]];

            case 'hr':
                return [['kind' => 'text', 'text' => '---']];

            case 'ul':
            case 'ol':
                return [['kind' => 'list', 'text' => $this->listBlock($element)]];

            case 'pre':
                return [['kind' => 'text', 'text' => $this->preBlock($element)]];

            case 'blockquote':
                return [['kind' => 'text', 'text' => $this->quoteBlock($element)]];

            case 'table':
                return $this->tableBlocks($element);

            case 'li':
                // A stray <li> outside a list. Keep the text, drop the marker.
                return $this->blocks($element);

            default:
                // Our own horizontal rule coming back the other way. <hr> is
                // not in core's whitelist, so MarkdownToHtml emits a bordered
                // div holding a non-breaking space.
                if ($this->isRuleDiv($element)) {
                    return [['kind' => 'text', 'text' => '---']];
                }

                // div, p, section, tr, td and every unknown block-ish element:
                // keep the children, drop the tag. This is also what makes
                // <div><br></div> — Summernote's empty paragraph — disappear
                // instead of adding a blank line.
                return $this->blocks($element);
        }
    }

    /**
     * Whether an element is the styled div MarkdownToHtml emits for a rule.
     *
     * @param \DOMElement $element
     *
     * @return bool
     */
    protected function isRuleDiv(\DOMElement $element)
    {
        if (Dom::styleOf($element, 'border-top') === '') {
            return false;
        }

        return trim(str_replace("\xC2\xA0", '', $element->textContent)) === '';
    }

    /**
     * @param \DOMElement $element
     *
     * @return string
     */
    protected function listBlock(\DOMElement $element)
    {
        $ordered = strtolower($element->nodeName) === 'ol';
        $index = (int) $element->getAttribute('start');
        $index = $index > 0 ? $index : 1;

        $items = [];

        foreach ($element->childNodes as $child) {
            if (!$child instanceof \DOMElement || strtolower($child->nodeName) !== 'li') {
                continue;
            }

            $content = $this->joinBlocks($this->blocks($child), true);

            if (trim($content) === '') {
                continue;
            }

            $marker = $ordered ? ($index++).'. ' : '- ';
            $lines = explode("\n", $content);
            $item = $marker.array_shift($lines);

            // Four spaces is the one indent that is a legal continuation under
            // both a "- " marker and a "1. " marker.
            foreach ($lines as $line) {
                $item .= "\n".($line === '' ? '' : '    '.$line);
            }

            $items[] = $item;
        }

        return implode("\n", $items);
    }

    /**
     * @param \DOMElement $element
     *
     * @return string
     */
    protected function preBlock(\DOMElement $element)
    {
        $language = '';

        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'code') {
                if (preg_match('/(?:^|\s)language-([\w+-]+)/', $child->getAttribute('class'), $m)) {
                    $language = $m[1];
                }
            }
        }

        $code = str_replace("\xC2\xA0", ' ', $element->textContent);
        $code = preg_replace("/\r\n?/", "\n", $code);
        $code = trim($code, "\n");

        // Widen the fence past the longest run of backticks in the code, or the
        // block ends early.
        $longest = 0;

        if (preg_match_all('/`+/', $code, $matches)) {
            foreach ($matches[0] as $run) {
                $longest = max($longest, strlen($run));
            }
        }

        $fence = str_repeat('`', max(3, $longest + 1));

        return $fence.$language."\n".$code."\n".$fence;
    }

    /**
     * @param \DOMElement $element
     *
     * @return string
     */
    protected function quoteBlock(\DOMElement $element)
    {
        $inner = $this->joinBlocks($this->blocks($element));

        if (trim($inner) === '') {
            return '';
        }

        $lines = explode("\n", $inner);

        foreach ($lines as $i => $line) {
            $lines[$i] = $line === '' ? '>' : '> '.$line;
        }

        return implode("\n", $lines);
    }

    // -----------------------------------------------------------------------
    // Tables
    // -----------------------------------------------------------------------

    /**
     * @param \DOMElement $table
     *
     * @return array
     */
    protected function tableBlocks(\DOMElement $table)
    {
        $rows = $this->tableRows($table);

        if ($this->options['tables'] && $this->isDataTable($table, $rows)) {
            $text = $this->pipeTable($rows);

            return $text === '' ? [] : [['kind' => 'text', 'text' => $text]];
        }

        // A layout table. Keep the content, lose the grid.
        $blocks = [];

        foreach ($rows as $row) {
            foreach ($row as $cell) {
                foreach ($this->blocks($cell) as $block) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * @param \DOMElement $table
     *
     * @return array Array of arrays of cell elements.
     */
    protected function tableRows(\DOMElement $table)
    {
        $rows = [];

        foreach ($this->descendantRows($table) as $tr) {
            $cells = [];

            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = $cell;
                }
            }

            if ($cells) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    /**
     * Rows belonging to this table, not to a table nested inside it.
     *
     * @param \DOMElement $table
     *
     * @return array
     */
    protected function descendantRows(\DOMElement $table)
    {
        $rows = [];

        foreach ($table->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if ($tag === 'tr') {
                $rows[] = $child;
            } elseif (in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                foreach ($child->childNodes as $row) {
                    if ($row instanceof \DOMElement && strtolower($row->nodeName) === 'tr') {
                        $rows[] = $row;
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Whether a table carries data or layout.
     *
     * Mail is full of tables used as a grid system. Rendering one of those as a
     * pipe table gives the model something to decode instead of something to
     * read, so the bar is deliberately high.
     *
     * @param \DOMElement $table
     * @param array       $rows
     *
     * @return bool
     */
    protected function isDataTable(\DOMElement $table, array $rows)
    {
        if (count($rows) < 2) {
            return false;
        }

        $columns = 0;

        foreach ($rows as $row) {
            $columns = max($columns, count($row));
        }

        if ($columns < 2) {
            return false;
        }

        if ($table->getElementsByTagName('table')->length > 0) {
            return false;
        }

        foreach ($rows as $row) {
            foreach ($row as $cell) {
                foreach ($cell->childNodes as $child) {
                    if ($this->isBlockNode($child) && !in_array(strtolower($child->nodeName), ['p', 'div'], true)) {
                        return false;
                    }

                    // A <p>/<div> is fine, as long as it is only inline itself.
                    if ($child instanceof \DOMElement && in_array(strtolower($child->nodeName), ['p', 'div'], true)) {
                        foreach ($child->childNodes as $grandchild) {
                            if ($this->isBlockNode($grandchild)) {
                                return false;
                            }
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param array $rows
     *
     * @return string
     */
    protected function pipeTable(array $rows)
    {
        $columns = 0;

        foreach ($rows as $row) {
            $columns = max($columns, count($row));
        }

        $header = array_shift($rows);
        $alignments = [];
        $rendered = [];

        for ($i = 0; $i < $columns; $i++) {
            $cell = isset($header[$i]) ? $header[$i] : null;
            $rendered[$i] = $cell ? $this->cell($cell) : '';
            $alignments[$i] = $cell ? $this->alignmentOf($cell) : '';
        }

        $lines = ['| '.implode(' | ', $rendered).' |'];
        $rules = [];

        foreach ($alignments as $alignment) {
            switch ($alignment) {
                case 'center':
                    $rules[] = ':---:';
                    break;
                case 'right':
                    $rules[] = '---:';
                    break;
                case 'left':
                    $rules[] = ':---';
                    break;
                default:
                    $rules[] = '---';
            }
        }

        $lines[] = '| '.implode(' | ', $rules).' |';

        foreach ($rows as $row) {
            $cells = [];

            for ($i = 0; $i < $columns; $i++) {
                $cells[$i] = isset($row[$i]) ? $this->cell($row[$i]) : '';
            }

            $lines[] = '| '.implode(' | ', $cells).' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param \DOMElement $cell
     *
     * @return string
     */
    protected function cell(\DOMElement $cell)
    {
        $this->in_cell++;
        $text = $this->collapseSpaces($this->inline($cell));
        $this->in_cell--;

        // An empty cell is <br> in Summernote (dom.blank), and a row added with
        // the editor's own table controls is full of them. Passing that through
        // would put the string "<br>" in the prompt as if it were content.
        if (trim(str_replace('<br>', '', $text)) === '') {
            return '';
        }

        return str_replace('|', '\\|', $text);
    }

    /**
     * @param \DOMElement $cell
     *
     * @return string
     */
    protected function alignmentOf(\DOMElement $cell)
    {
        $align = strtolower(trim($cell->getAttribute('align')));

        if ($align === '') {
            $align = strtolower(Dom::styleOf($cell, 'text-align'));
        }

        return in_array($align, ['left', 'right', 'center'], true) ? $align : '';
    }

    // -----------------------------------------------------------------------
    // Inline
    // -----------------------------------------------------------------------

    /**
     * @param \DOMNode $parent
     *
     * @return string
     */
    protected function inline(\DOMNode $parent)
    {
        $text = '';

        foreach ($parent->childNodes as $child) {
            $text .= $this->inlineNode($child);
        }

        return $text;
    }

    /**
     * @param \DOMNode $node
     *
     * @return string
     */
    protected function inlineNode(\DOMNode $node)
    {
        if ($node instanceof \DOMText) {
            return $this->escapeInline($node->nodeValue);
        }

        if ($node instanceof \DOMComment) {
            return '';
        }

        if (!$node instanceof \DOMElement) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, self::$dropped, true)) {
            return '';
        }

        switch ($tag) {
            case 'br':
                // A single newline, not the two-space hard break: Parsedown
                // runs with breaks enabled, so this round-trips to <br>, and it
                // survives ThreadFormatter::collapse() stripping line-trailing
                // whitespace.
                return $this->in_cell ? '<br>' : "\n";

            case 'strong':
            case 'b':
                return $this->wrap($node, '**');

            case 'em':
            case 'i':
                return $this->wrap($node, '*');

            case 's':
            case 'strike':
            case 'del':
                return $this->wrap($node, '~~');

            case 'u':
                // Markdown has no underline. Passing the tag through is the one
                // place raw HTML is emitted, and it round-trips: both profiles
                // allow <u>.
                $inner = $this->inline($node);

                return trim($inner) === '' ? '' : '<u>'.$inner.'</u>';

            case 'code':
                return $this->codeSpan($node);

            case 'a':
                return $this->link($node);

            case 'img':
                return $this->image($node);

            default:
                // Our own inline code coming back the other way: core's
                // whitelist has no <code>, so MarkdownToHtml emits a monospace
                // span instead.
                if (stripos(Dom::styleOf($node, 'font-family'), 'monospace') !== false) {
                    return $this->codeSpan($node);
                }

                // span, font, small, big, o:p and everything else unknown:
                // keep the text, drop the tag.
                return $this->inline($node);
        }
    }

    /**
     * @param \DOMElement $element
     * @param string      $marker
     *
     * @return string
     */
    protected function wrap(\DOMElement $element, $marker)
    {
        $inner = $this->inline($element);

        if (trim($inner) === '') {
            return $inner;
        }

        // Markers must sit against the text, or they are not markers at all.
        preg_match('/^(\s*)(.*?)(\s*)$/us', $inner, $m);

        return $m[1].$marker.$m[2].$marker.$m[3];
    }

    /**
     * @param \DOMElement $element
     *
     * @return string
     */
    protected function codeSpan(\DOMElement $element)
    {
        $code = str_replace("\xC2\xA0", ' ', $element->textContent);
        $code = trim(preg_replace('/\s+/u', ' ', $code));

        if ($code === '') {
            return '';
        }

        $longest = 0;

        if (preg_match_all('/`+/', $code, $matches)) {
            foreach ($matches[0] as $run) {
                $longest = max($longest, strlen($run));
            }
        }

        if ($longest === 0) {
            return '`'.$code.'`';
        }

        $fence = str_repeat('`', $longest + 1);

        return $fence.' '.$code.' '.$fence;
    }

    /**
     * @param \DOMElement $element
     *
     * @return string
     */
    protected function link(\DOMElement $element)
    {
        $text = $this->inline($element);
        $href = trim($element->getAttribute('href'));

        if ($href === '' || trim($text) === '') {
            return $text;
        }

        // A scheme we would not render is a scheme the model has no use for.
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $href, $m)
            && !in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true)) {
            return $text;
        }

        $plain = trim($this->unescape($text));

        if ($plain === $href || 'mailto:'.$plain === $href) {
            return '<'.$href.'>';
        }

        if (preg_match('/[\s()]/', $href)) {
            $href = '<'.$href.'>';
        }

        $title = trim($element->getAttribute('title'));

        if ($title !== '') {
            return '['.trim($text).']('.$href.' "'.str_replace('"', '', $title).'")';
        }

        return '['.trim($text).']('.$href.')';
    }

    /**
     * @param \DOMElement $element
     *
     * @return string
     */
    protected function image(\DOMElement $element)
    {
        $src = trim($element->getAttribute('src'));
        $alt = trim($element->getAttribute('alt'));

        // cid: is an inline attachment and data: is megabytes of base64. The
        // model can do nothing with either, and both cost tokens.
        $useless = $src === ''
            || stripos($src, 'cid:') === 0
            || stripos($src, 'data:') === 0;

        if ($this->options['images'] === 'drop') {
            return '';
        }

        if ($this->options['images'] === 'markdown' && !$useless) {
            return '!['.$alt.']('.$src.')';
        }

        return $alt === '' ? '[image]' : '[image: '.$alt.']';
    }

    // -----------------------------------------------------------------------
    // Text
    // -----------------------------------------------------------------------

    /**
     * Escape what Markdown would otherwise read as markup.
     *
     * Contextual on purpose. Escaping every underscore turns snake_case_names
     * into noise the model has to see through, and escaping a line of dashes
     * would break the quote and signature detection in ThreadFormatter, which
     * matches on exactly those lines.
     *
     * @param string $text
     *
     * @return string
     */
    protected function escapeInline($text)
    {
        $text = (string) $text;

        if ($text === '') {
            return '';
        }

        // A rule or a sigdashes line is left exactly as it is.
        if (preg_match('/^\s*[_\-=]{3,}\s*$/', $text)) {
            return $text;
        }

        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        $text = str_replace(
            ['\\', '`', '*', '[', ']'],
            ['\\\\', '\\`', '\\*', '\\[', '\\]'],
            $text
        );

        // "<" only where it looks like a tag; "\<" is not a Parsedown escape
        // sequence and would show up as a literal backslash.
        $text = preg_replace_callback('/<(?=[a-zA-Z\/!])/', function () {
            return '&lt;';
        }, $text);

        // Underscores only at word boundaries, so snake_case survives.
        return preg_replace_callback(
            '/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u',
            function () {
                return '\\_';
            },
            $text
        );
    }

    /**
     * Undo escapeInline(), for comparing link text against its href.
     *
     * @param string $text
     *
     * @return string
     */
    protected function unescape($text)
    {
        return str_replace(
            ['\\_', '\\`', '\\*', '\\[', '\\]', '&lt;', '\\\\'],
            ['_', '`', '*', '[', ']', '<', '\\'],
            (string) $text
        );
    }

    /**
     * Turn accumulated inline text into a paragraph.
     *
     * @param string $text
     *
     * @return string
     */
    protected function paragraph($text)
    {
        $text = $this->collapseSpaces($text);

        if ($text === '') {
            return '';
        }

        return $this->escapeLineStarts($text);
    }

    /**
     * Escape the characters that only mean something at the start of a line.
     *
     * @param string $text
     *
     * @return string
     */
    protected function escapeLineStarts($text)
    {
        $lines = explode("\n", $text);

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*[_\-=]{3,}\s*$/', $line)) {
                continue;
            }

            $line = preg_replace('/^(\s*)(#{1,6}\s)/', '$1\\\\$2', $line);
            $line = preg_replace('/^(\s*)(>)/', '$1\\\\$2', $line);
            $line = preg_replace('/^(\s*)([-+])(\s)/', '$1\\\\$2$3', $line);
            $line = preg_replace('/^(\s*)(\d{1,9})([.)])(\s)/', '$1$2\\\\$3$4', $line);

            $lines[$i] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param string $text
     *
     * @return string
     */
    protected function collapseSpaces($text)
    {
        $text = preg_replace('/[ \t]+/', ' ', (string) $text);
        $text = preg_replace('/ *\n */', "\n", $text);

        return trim($text);
    }

    /**
     * @param string $markdown
     *
     * @return string
     */
    protected function tidy($markdown)
    {
        $markdown = preg_replace("/\r\n?/", "\n", (string) $markdown);
        $markdown = preg_replace('/[ \t]+$/m', '', $markdown);
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown);

        return trim($markdown);
    }

    /**
     * What this replaced. Still the right answer when the DOM will not parse.
     *
     * @param string $html
     *
     * @return string
     */
    protected static function fallback($html)
    {
        return trim(\Helper::htmlToText($html, false, ['width' => 0]));
    }
}
