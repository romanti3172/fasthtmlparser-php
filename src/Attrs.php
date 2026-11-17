<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * @internal Attributes are read from the source of the opening tag when they are asked for.
 */
final class Attrs
{
    // the result of readValue(): value start (-1: no '='), length, and the position after the value
    public static int $valueStart = 0;
    public static int $valueLength = 0;
    public static int $valueNext = 0;

    private function __construct()
    {
    }

    /** The decoded value of the first attribute with the name (case-insensitive); "" when missing or without a value. */
    public static function attrValue(HtmlNode $n, string $name): string
    {
        $raw = self::attrRaw($n, $name);
        return strpbrk($raw, "&\r") === false ? $raw : Decode::decode($raw, true);
    }

    /** Whether one of the space-separated classes equals $cls (case-insensitive). */
    public static function hasClass(HtmlNode $n, string $cls): bool
    {
        if ($cls === '') {
            return false;
        }
        $value = self::attrRaw($n, 'class');
        if (strpbrk($value, "&\r") !== false) {
            $value = Decode::decode($value, true);
        }
        $vl = strlen($value);
        $cl = strlen($cls);
        if ($vl === $cl) {
            return Chars::equalFold($value, $cls);
        }
        if ($cl > $vl) {
            return false;
        }
        for ($from = 0; $from <= $vl - $cl;) {
            $i = stripos($value, $cls, $from);
            if ($i === false) {
                return false;
            }
            $after = $i + $cl;
            if (($i === 0 || strspn($value, Chars::SPACE, $i - 1, 1) === 1)
                && ($after === $vl || strspn($value, Chars::SPACE, $after, 1) === 1)) {
                return true;
            }
            $from = $i + 1;
        }
        return false;
    }

    /**
     * The raw value of the first attribute with the name, as a browser reads the tag: quoted or unquoted
     * (href=/p?a=1), "" for an attribute without a value or a missing one.
     */
    public static function attrRaw(HtmlNode $n, string $attribute): string
    {
        // a comment has no attributes, whatever its text looks like
        if ($attribute === '' || $n->key === Parser::COMMENT) {
            return '';
        }
        $src = $n->doc->source;
        $start = $n->start;
        $end = self::openTagEnd($n); // $src[$start, $end): "<name attributes", without the closing '>'

        // The first place where the name stands as a word of its own. Most tags have none.
        $pos = self::nextWord($src, $end, $attribute, $start + 1);
        if ($pos < 0) {
            return '';
        }

        // That word is the attribute unless it is inside the value of another one (title="see id=5", x= id).
        // Nearly always that is ruled out at once, and the value is read in place.
        $firstWord = $n->key >= 0 && $pos - $start === strlen(TagNames::NAMES[$n->key]) + 2;
        if ($firstWord || self::isNameForSure($src, $start, $pos)) {
            self::readValue($src, $pos + strlen($attribute), $end);
            return self::$valueStart < 0 ? '' : substr($src, self::$valueStart, self::$valueLength);
        }

        // Otherwise the attributes are read one by one, up to the one that starts at that word.
        $s = new AttrScanner($n);
        while ($s->next()) {
            // a word the scanner has passed was inside a value: the next one takes its place
            while ($pos < $s->nameStart) {
                $pos = self::nextWord($src, $end, $attribute, $pos + 1);
                if ($pos < 0) {
                    return '';
                }
            }
            if ($pos === $s->nameStart && $s->nameLength === strlen($attribute)) {
                return $s->valueStart < 0 ? '' : substr($src, $s->valueStart, $s->valueLength);
            }
        }
        return '';
    }

    /** The next place in $src[start, $end) at or after $from where the name stands as a word of its own, or -1. */
    private static function nextWord(string $src, int $end, string $attribute, int $from): int
    {
        $n = strlen($attribute);
        while ($from < $end) {
            // searched in the tag only: a search of the whole page would run on to the next tag that has the name
            $found = stripos(substr($src, $from, $end - $from), $attribute);
            if ($found === false) {
                break;
            }
            $found += $from;
            $after = $found + $n;
            // starts a name: after whitespace, a quote, '/' or '='; ends a name: at whitespace, '=', '/' or '>'
            if (strspn($src, " \t\n\x0b\x0c\r\"'/=", $found - 1, 1) === 1
                && ($after === $end || strspn($src, " \t\n\x0b\x0c\r=/>", $after, 1) === 1)) {
                return $found;
            }
            $from = $found + 1;
        }
        return -1;
    }

    /**
     * Whether the word at $pos of the opening tag that starts at $start is an attribute name, judged by the chars
     * before it alone. False means "not sure".
     */
    private static function isNameForSure(string $src, int $start, int $pos): bool
    {
        // After whitespace, and not as the unquoted value in "x= word".
        $last = $pos - 1;
        if (strspn($src, Chars::SPACE, $last, 1) !== 1) {
            return false;
        }
        while ($last > $start && strspn($src, Chars::SPACE, $last, 1) === 1) {
            $last--;
        }
        if ($src[$last] === '=') {
            return false;
        }
        // Not inside a quoted value: it is enough that the last quote of each kind before the word does not open a value.
        return !self::opensValue($src, $start, self::lastIndexIn($src, '"', $start, $last))
            && !self::opensValue($src, $start, self::lastIndexIn($src, "'", $start, $last));
    }

    /** The last position of $ch in $src[$start, $last]; -1 when there is none. Never looks before the tag. */
    private static function lastIndexIn(string $src, string $ch, int $start, int $last): int
    {
        $i = strrpos(substr($src, $start, $last - $start + 1), $ch);
        return $i === false ? -1 : $start + $i;
    }

    /** Whether the quote at $quote (or -1: none) stands right after an '='. */
    private static function opensValue(string $src, int $start, int $quote): bool
    {
        if ($quote < 0) {
            return false;
        }
        $k = $quote - 1; // >= $start: the tag starts with '<'
        while ($k > $start && strspn($src, Chars::SPACE, $k, 1) === 1) {
            $k--;
        }
        return $src[$k] === '=';
    }

    /** Compares the decoded value; decodes only when the raw value has '&' or CR. */
    public static function attrEquals(string $raw, string $value): bool
    {
        return strpbrk($raw, "&\r") !== false ? Decode::decode($raw, true) === $value : $raw === $value;
    }

    /** Whether the decoded value starts with $prefix. */
    public static function attrStartsWith(string $raw, string $prefix): bool
    {
        return str_starts_with(strpbrk($raw, "&\r") !== false ? Decode::decode($raw, true) : $raw, $prefix);
    }

    /** The position of the '>' that ends the opening tag. */
    public static function openTagEnd(HtmlNode $n): int
    {
        $end = $n->innerStart > 0 && $n->innerEnd > 0 ? $n->innerStart - 1 : $n->end;
        return min($end, strlen($n->doc->source));
    }

    /** Reads the value that follows an attribute name at $pos (before $end) into $valueStart, $valueLength, $valueNext. */
    public static function readValue(string $s, int $pos, int $end): void
    {
        $p = $pos + ($pos < $end ? strspn($s, Chars::SPACE, $pos, $end - $pos) : 0);
        if ($p >= $end || $s[$p] !== '=') {
            self::$valueStart = -1;
            self::$valueLength = 0;
            self::$valueNext = $pos;
            return;
        }
        $p++;
        if ($p < $end) {
            $p += strspn($s, Chars::SPACE, $p, $end - $p);
        }
        $q = $p < $end ? $s[$p] : '';
        if ($q === '"' || $q === "'") {
            $start = $p + 1;
            $close = $start < $end ? strpos($s, $q, $start) : false;
            if ($close === false || $close >= $end) {
                self::$valueStart = $start;
                self::$valueLength = max(0, $end - $start);
                self::$valueNext = $end;
            } else {
                self::$valueStart = $start;
                self::$valueLength = $close - $start;
                self::$valueNext = $close + 1;
            }
            return;
        }
        $start = $p;
        if ($p < $end) {
            $p += strcspn($s, Chars::SPACE, $p, $end - $p);
        }
        self::$valueStart = $start;
        self::$valueLength = $p - $start;
        self::$valueNext = $p;
    }

    /** Whether the element has the attribute, with or without a value. */
    public static function hasAttribute(HtmlNode $n, string $name): bool
    {
        if (self::attrRaw($n, $name) !== '') {
            return true;
        }
        if ($n->key === Parser::COMMENT) {
            return false;
        }
        $s = new AttrScanner($n);
        while ($s->next()) {
            if (Chars::equalFold($s->name(), $name)) {
                return true;
            }
        }
        return false;
    }
}
