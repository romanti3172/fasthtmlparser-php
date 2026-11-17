<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * @internal Visible text: tags, comments and invisible content removed, whitespace collapsed, entities decoded.
 */
final class Text
{
    private const SPACE_RUNS = '/' . Chars::UNICODE_SPACE . '+/';
    /** the first bytes of every whitespace char */
    private const SPACE_BYTES = "\t\n\x0b\x0c\r \xC2\xE1\xE2\xE3";

    private function __construct()
    {
    }

    /**
     * $s[$i] is '<'. Returns the index of the last char of what is not text there: a comment, a tag,
     * or a tag together with invisible content (script, style, ...) up to its end tag. -1: an unterminated tag.
     */
    public static function skipMarkup(string $s, int $i): int
    {
        $n = strlen($s);
        if ($i + 4 <= $n && $s[$i + 1] === '!' && $s[$i + 2] === '-' && $s[$i + 3] === '-') {
            return Parser::findCommentEnd($s, $i + 4, $n);
        }
        $tagEnd = strpos($s, '>', $i + 1);
        if ($tagEnd === false) {
            return -1;
        }
        $endTag = self::invisibleContentEnd($s, $i + 1, $tagEnd);
        if ($endTag !== null) {
            return Parser::findRawTextEnd($s, $tagEnd + 1, $endTag, $n) - 1;
        }
        return $tagEnd;
    }

    /**
     * For the inside of an opening tag $s[$from, $to), the end tag ("</script") when the element's content
     * is never visible text (script, style, noscript, template, noframes, noembed), otherwise null.
     */
    private static function invisibleContentEnd(string $s, int $from, int $to): ?string
    {
        if ($from >= $to) {
            return null;
        }
        $first = ord($s[$from]) | 0x20;
        if ($first !== 0x73 && $first !== 0x74 && $first !== 0x6e) { // s, t, n
            return null;
        }
        $n = 1;
        while ($from + $n < $to && $n <= 8) {
            $c = ord($s[$from + $n]) | 0x20;
            if ($c < 0x61 || $c > 0x7a) {
                break;
            }
            $n++;
        }
        if ($from + $n < $to) {
            $c = $s[$from + $n];
            if ($c !== '/' && strspn($c, Chars::SPACE) !== 1) {
                return null;
            }
        }
        $end = match (Chars::asciiLower(substr($s, $from, $n))) {
            'style' => '</style',
            'script' => '</script',
            'noembed' => '</noembed',
            'noscript' => '</noscript',
            'template' => '</template',
            'noframes' => '</noframes',
            default => null,
        };
        if ($end === null) {
            return null;
        }
        $e = $to;
        while ($e > $from && strspn($s[$e - 1], Chars::SPACE) === 1) {
            $e--;
        }
        if ($s[$e - 1] === '/') {
            return null; // <script src="x" />: no content
        }
        return $end;
    }

    /** The text runs of $s with tags, comments and invisible content removed, whitespace not yet collapsed. */
    private static function textRuns(string $s): string
    {
        $lt = strpos($s, '<');
        if ($lt === false) {
            return $s;
        }
        $n = strlen($s);
        $out = '';
        $from = 0;
        do {
            if (!Parser::isMarkupStart($s, $lt, $n)) {
                $lt = strpos($s, '<', $lt + 1);
                continue;
            }
            $out .= substr($s, $from, $lt - $from);
            $last = self::skipMarkup($s, $lt);
            if ($last < 0) {
                return $out; // an unterminated tag: the rest is markup
            }
            $from = $last + 1;
            $lt = strpos($s, '<', $from);
        } while ($lt !== false);
        return $from === 0 ? $s : $out . substr($s, $from);
    }

    /** Whitespace runs as one ' ', trimmed. */
    private static function collapse(string $s): string
    {
        if (strpbrk($s, self::SPACE_BYTES) === false) {
            return $s;
        }
        return trim(preg_replace(self::SPACE_RUNS, ' ', $s), ' ');
    }

    /** The visible text of an HTML fragment. */
    public static function clearTags(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $text = self::collapse(self::textRuns($s));
        return str_contains($text, '&') ? Decode::decode($text, false) : $text;
    }

    /** Whether the visible text of the body ($value) equals $expected; $n gives the full text. */
    public static function isText(string $value, string $expected, HtmlNode $n): bool
    {
        if ($value === '' || $expected === '') {
            return false;
        }
        // whitespace is collapsed even where text() keeps it (a tag without children), as the .NET version compares
        $text = self::collapse(self::textRuns($value));
        $amp = strpos($text, '&');
        if ($amp === false) {
            return $text === $expected;
        }
        // Text with entities must be decoded before comparing: the full text, when the part before it matches.
        return str_starts_with($expected, rtrim(substr($text, 0, $amp), ' ')) && $n->text() === $expected;
    }
}
