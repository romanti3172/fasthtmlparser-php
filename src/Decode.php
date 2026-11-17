<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * @internal HTML5 character reference decoder (WHATWG "character reference state"): all 2125 named references, the
 * legacy names without ';', numeric references with or without ';', and the spec replacements (0, surrogates,
 * > U+10FFFF → U+FFFD; 0x80–0x9F → Windows-1252).
 */
final class Decode
{
    private const ALNUM = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    // Replacements for numeric references 0x80..0x9F; 0 = keep the code point as is.
    private const WINDOWS_1252 = [
        0x20ac, 0, 0x201a, 0x0192, 0x201e, 0x2026, 0x2020, 0x2021, 0x02c6, 0x2030, 0x0160, 0x2039, 0x0152, 0, 0x017d, 0,
        0, 0x2018, 0x2019, 0x201c, 0x201d, 0x2022, 0x2013, 0x2014, 0x02dc, 0x2122, 0x0161, 0x203a, 0x0153, 0, 0x017e, 0x0178,
    ];

    /** the result of the match functions: the bytes consumed after '&' (0: not a reference) and the decoded text */
    private static int $consumed = 0;

    private function __construct()
    {
    }

    /** Whether a value read from the page differs from its source: it has a character reference or a CR. */
    public static function needsDecoding(string $s): bool
    {
        return strpbrk($s, "&\r") !== false;
    }

    /** HTML input stream preprocessing: CR LF and a lone CR become LF. */
    public static function normalizeNewlines(string $s): string
    {
        if (!str_contains($s, "\r")) {
            return $s;
        }
        return str_replace("\r", "\n", str_replace("\r\n", "\n", $s));
    }

    /**
     * Decodes the character references in $s; CR LF and a lone CR become LF. With $inAttribute, a legacy name
     * without ';' followed by '=' or an alphanumeric is left as is (so "?a=1&copy=2" stays intact).
     */
    public static function decode(string $s, bool $inAttribute): string
    {
        // Line breaks first, as a browser reads the page; a CR written as "&#13;" is decoded after that and stays.
        if (str_contains($s, "\r")) {
            $s = self::normalizeNewlines($s);
        }
        $amp = strpos($s, '&');
        if ($amp === false) {
            return $s;
        }
        $out = '';
        $pos = 0;
        $n = strlen($s);
        while ($amp !== false) {
            $out .= substr($s, $pos, $amp - $pos);
            $text = $amp + 1 < $n ? self::reference($s, $amp + 1, $inAttribute) : null;
            if ($text === null) {
                $out .= '&'; // not a reference: keep '&'
                $pos = $amp + 1;
            } else {
                $out .= $text;
                $pos = $amp + 1 + self::$consumed;
            }
            $amp = $pos < $n ? strpos($s, '&', $pos) : false;
        }
        return $out . substr($s, $pos);
    }

    /**
     * Decodes one character reference; $s[$at] is the char right after '&'. Returns the decoded text, or null when
     * there is no reference; $consumed gets the number of bytes consumed after '&'.
     */
    public static function decodeReference(string $s, int $at, ?int &$consumed): ?string
    {
        $text = $at < strlen($s) ? self::reference($s, $at, false) : null;
        $consumed = $text === null ? 0 : self::$consumed;
        return $text;
    }

    private static function reference(string $s, int $at, bool $inAttribute): ?string
    {
        if ($s[$at] === '#') {
            $cp = self::matchNumeric($s, $at);
            return $cp < 0 ? null : Chars::utf8($cp);
        }
        return self::matchNamed($s, $at, $inAttribute);
    }

    /** $s[$at] is '#'. Returns the code point (-1: not a reference) and sets $consumed. */
    private static function matchNumeric(string $s, int $at): int
    {
        $n = strlen($s);
        $i = $at + 1;
        $hex = $i < $n && ($s[$i] === 'x' || $s[$i] === 'X');
        if ($hex) {
            $i++;
        }
        $digits = $i;
        $value = 0;
        if ($hex) {
            for (; $i < $n; $i++) {
                $c = ord($s[$i]);
                if ($c >= 48 && $c <= 57) {
                    $d = $c - 48;
                } else {
                    $l = $c | 0x20;
                    if ($l < 97 || $l > 102) {
                        break;
                    }
                    $d = $l - 87;
                }
                $value = $value * 16 + $d;
                if ($value > 0x10ffff) {
                    $value = 0x110000; // saturate on long digit runs
                }
            }
        } else {
            for (; $i < $n; $i++) {
                $c = ord($s[$i]);
                if ($c < 48 || $c > 57) {
                    break;
                }
                $value = $value * 10 + $c - 48;
                if ($value > 0x10ffff) {
                    $value = 0x110000;
                }
            }
        }
        if ($i === $digits) {
            return -1; // "&#" / "&#x" without digits is plain text
        }
        if ($i < $n && $s[$i] === ';') {
            $i++;
        }
        if ($value === 0 || $value > 0x10ffff || ($value >= 0xd800 && $value <= 0xdfff)) {
            $value = 0xfffd;
        } elseif ($value >= 0x80 && $value <= 0x9f) {
            $mapped = self::WINDOWS_1252[$value - 0x80];
            if ($mapped !== 0) {
                $value = $mapped;
            }
        }
        self::$consumed = $i - $at;
        return $value;
    }

    /** $s[$at] is the char right after '&'. Returns the decoded text (null: not a reference) and sets $consumed. */
    private static function matchNamed(string $s, int $at, bool $inAttribute): ?string
    {
        $len = strlen($s);
        $n = strspn($s, self::ALNUM, $at, Entities::MAX_NAME + 1);
        if ($n < 2) {
            return null;
        }
        // Full match: "&name;".
        if ($at + $n < $len && $s[$at + $n] === ';') {
            $v = Entities::MAP[substr($s, $at, $n)] ?? null;
            if ($v !== null) {
                self::$consumed = $n + 1;
                return $v;
            }
        }
        // Longest legacy prefix without ';': "&copy2024" → "©2024", "&notit;" → "¬it;".
        for ($k = min($n, Entities::MAX_LEGACY_NAME); $k >= 2; $k--) {
            $name = substr($s, $at, $k);
            if (isset(Entities::LEGACY[$name])) {
                if ($inAttribute && $at + $k < $len) {
                    $c = $s[$at + $k];
                    if ($c === '=' || strspn($c, self::ALNUM) === 1) {
                        break;
                    }
                }
                self::$consumed = $k;
                return Entities::MAP[$name];
            }
        }
        return null;
    }
}
