<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * @internal Text with the visible line structure kept (HtmlNode::textLineBreaks()).
 */
final class TextLines
{
    private const BREAK_SPACE = 1; // table cell: word separator
    private const BREAK_LINE = 2; // <br> and block elements (div, li, tr, ...): new line
    private const BREAK_PARAGRAPH = 3; // p, h1-h6: new paragraph (blank line)
    private const BREAK_SKIP_CONTENT = 4; // script, style, noscript: contents are not text
    // inline tags (b, span, a, ...) are removed, words are not split

    private const BR = TagNames::KEYS['br'];

    private const SPACE_RUNS = '/(' . Chars::UNICODE_SPACE . '+)/';
    private const SPACE_BYTES = "\t\n\x0b\x0c\r \xC2\xE1\xE2\xE3";

    /** @var array<int, int> */
    private static array $kinds = [];

    private string $out = '';
    private int $pendingBreaks = 0; // new lines requested since the last text char
    private bool $pendingSpace = false;

    private static function kinds(): array
    {
        if (self::$kinds === []) {
            $k = [];
            foreach (['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $t) {
                $k[TagNames::KEYS[$t]] = self::BREAK_PARAGRAPH;
            }
            foreach (['br', 'div', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'table', 'caption', 'tr', 'thead', 'tbody', 'tfoot',
                'section', 'article', 'aside', 'nav', 'header', 'footer', 'main', 'address', 'blockquote', 'pre', 'hr',
                'figure', 'figcaption', 'form', 'fieldset', 'legend', 'details', 'summary', 'dialog', 'option', 'html',
                'head', 'body', 'title', 'hgroup', 'search', 'menu', 'center', 'dir', 'listing', 'xmp', 'plaintext',
                'multicol', 'frameset', 'marquee'] as $t) {
                $k[TagNames::KEYS[$t]] = self::BREAK_LINE;
            }
            $k[TagNames::KEYS['td']] = self::BREAK_SPACE;
            $k[TagNames::KEYS['th']] = self::BREAK_SPACE;
            foreach (['script', 'style', 'noscript', 'template', 'noframes', 'noembed'] as $t) {
                $k[TagNames::KEYS[$t]] = self::BREAK_SKIP_CONTENT;
            }
            self::$kinds = $k;
        }
        return self::$kinds;
    }

    /** Writes text, collapsing whitespace and turning requested breaks into trimmed lines. */
    private function chars(string $s): void
    {
        if (strpbrk($s, self::SPACE_BYTES) === false) {
            $this->text($s);
            return;
        }
        foreach (preg_split(self::SPACE_RUNS, $s, -1, PREG_SPLIT_DELIM_CAPTURE) as $k => $part) {
            if ($k % 2 === 1) {
                $this->pendingSpace = true;
            } elseif ($part !== '') {
                $this->text($part);
            }
        }
    }

    private function text(string $s): void
    {
        if ($this->out !== '') {
            // nothing before the first text char: leading breaks and spaces are trimmed
            if ($this->pendingBreaks > 0) {
                $this->out .= $this->pendingBreaks >= 2 ? "\n\n" : "\n"; // at most one blank line
            } elseif ($this->pendingSpace) {
                $this->out .= ' ';
            }
        }
        $this->pendingBreaks = 0;
        $this->pendingSpace = false;
        $this->out .= $s;
    }

    /** The text of an HTML fragment with the visible line structure kept. Lines are joined with "\n". */
    public static function clearTagsLineBreaks(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (str_contains($value, "\r")) {
            $value = Decode::normalizeNewlines($value); // values are read as a browser reads the page
        }
        $kinds = self::kinds();
        $w = new self();
        $n = strlen($value);
        $i = 0;
        while ($i < $n) {
            $c = $value[$i];

            if ($c === '&') {
                // '&': decoded chars go through the same whitespace/text logic (&nbsp; collapses, &lt; is plain text)
                $decoded = Decode::decodeReference($value, $i + 1, $consumed);
                if ($decoded !== null) {
                    $w->chars($decoded);
                    $i += 1 + $consumed;
                } else {
                    $w->chars('&');
                    $i++;
                }
                continue;
            }

            if ($c !== '<' || !Parser::isMarkupStart($value, $i, $n)) {
                // plain text up to the next '&' or '<'
                $j = $i + 1;
                if ($j < $n) {
                    $j += strcspn($value, '&<', $j);
                }
                $w->chars(substr($value, $i, $j - $i));
                $i = $j;
                continue;
            }

            if ($i + 4 <= $n && $value[$i + 1] === '!' && $value[$i + 2] === '-' && $value[$i + 3] === '-') {
                $end = $i + 4 <= $n ? strpos($value, '-->', $i + 4) : false;
                if ($end === false) {
                    break;
                }
                $i = $end + 3;
                continue;
            }

            $closeTag = strpos($value, '>', $i);
            if ($closeTag === false) {
                break; // unterminated tag: the rest is markup
            }
            $tag = substr($value, $i + 1, $closeTag - $i - 1);
            $i = $closeTag + 1;

            $closing = $tag !== '' && $tag[0] === '/';
            $name = $closing ? substr($tag, 1) : $tag;
            $name = substr($name, 0, strcspn($name, " \t\r\n\x0c/"));
            $known = Parser::lookupTag($name);
            if ($known < 0) {
                continue; // unknown tag: just removed
            }

            switch ($kinds[$known] ?? 0) {
                case self::BREAK_LINE:
                    if ($known === self::BR) {
                        $w->pendingBreaks++; // every <br> counts
                    } else {
                        $w->pendingBreaks = max($w->pendingBreaks, 1);
                    }
                    break;
                case self::BREAK_PARAGRAPH:
                    $w->pendingBreaks = max($w->pendingBreaks, 2);
                    break;
                case self::BREAK_SPACE:
                    $w->chars(' ');
                    break;
                case self::BREAK_SKIP_CONTENT:
                    if (!$closing && !str_ends_with(Chars::trimRightAscii($tag), '/')) {
                        // jump to the closing tag; it is removed on the next iteration
                        $end = Chars::indexFold($value, '</' . TagNames::NAMES[$known], $i, $n);
                        $i = $end < 0 ? $n : $end;
                    }
                    break;
                default:
                    break;
            }
        }
        return $w->out;
    }
}
