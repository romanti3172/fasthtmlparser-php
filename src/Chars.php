<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * @internal Character helpers shared by the parser, the text readers and the query engines. Strings are UTF-8 bytes;
 * positions are byte offsets.
 */
final class Chars
{
    /** ASCII whitespace as the parser sees it: space, \t, \n, \v, \f, \r. */
    public const SPACE = " \t\n\x0b\x0c\r";

    /**
     * One whitespace char as .NET char.IsWhiteSpace sees it, as a byte pattern (no /u: it works on any bytes):
     * ASCII, U+0085, U+00A0, U+1680, U+2000–U+200A, U+2028, U+2029, U+202F, U+205F, U+3000.
     */
    public const UNICODE_SPACE = '(?:[\t-\r ]|\xC2[\x85\xA0]|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80)';

    private static ?bool $mb = null;

    private function __construct()
    {
    }

    public static function isSpace(string $c): bool
    {
        return $c === ' ' || ($c >= "\t" && $c <= "\r" && $c !== '');
    }

    /** The byte length of the whitespace char (char.IsWhiteSpace) that starts at $i, or 0. */
    public static function spaceAt(string $s, int $i, int $limit): int
    {
        if ($i >= $limit) {
            return 0;
        }
        $c = ord($s[$i]);
        if ($c < 0x80) {
            return $c === 32 || ($c >= 9 && $c <= 13) ? 1 : 0;
        }
        if ($c === 0xc2) {
            if ($i + 1 < $limit) {
                $d = ord($s[$i + 1]);
                return $d === 0x85 || $d === 0xa0 ? 2 : 0;
            }
            return 0;
        }
        if ($c < 0xe1 || $c > 0xe3 || $i + 2 >= $limit) {
            return 0;
        }
        $d = ord($s[$i + 1]);
        $e = ord($s[$i + 2]);
        if ($c === 0xe1) {
            return $d === 0x9a && $e === 0x80 ? 3 : 0;
        }
        if ($c === 0xe3) {
            return $d === 0x80 && $e === 0x80 ? 3 : 0;
        }
        if ($d === 0x80) {
            return ($e >= 0x80 && $e <= 0x8a) || $e === 0xa8 || $e === 0xa9 || $e === 0xaf ? 3 : 0;
        }
        return $d === 0x81 && $e === 0x9f ? 3 : 0;
    }

    /** The byte length of the whitespace char (char.IsWhiteSpace) that ends right before $end (not before $from), or 0. */
    public static function spaceBefore(string $s, int $end, int $from): int
    {
        if ($end <= $from) {
            return 0;
        }
        $c = ord($s[$end - 1]);
        if ($c < 0x80) {
            return $c === 32 || ($c >= 9 && $c <= 13) ? 1 : 0;
        }
        if ($c > 0xbf) {
            return 0;
        }
        if ($end - 2 >= $from && $s[$end - 2] === "\xC2") {
            return $c === 0x85 || $c === 0xa0 ? 2 : 0;
        }
        if ($end - 3 >= $from) {
            return self::spaceAt($s, $end - 3, $end) === 3 ? 3 : 0;
        }
        return 0;
    }

    /** Trims Unicode whitespace (char.IsWhiteSpace) from both ends. */
    public static function trimSpace(string $s): string
    {
        $t = trim($s, self::SPACE);
        if ($t === '') {
            return '';
        }
        if ($t[0] < "\xC2" && $t[-1] < "\x80") {
            return $t;
        }
        $start = 0;
        $end = strlen($t);
        while (($n = self::spaceAt($t, $start, $end)) > 0) {
            $start += $n;
        }
        while (($n = self::spaceBefore($t, $end, $start)) > 0) {
            $end -= $n;
        }
        return $start === 0 && $end === strlen($t) ? $t : substr($t, $start, $end - $start);
    }

    /** Trims ASCII whitespace from the end. */
    public static function trimRightAscii(string $s): string
    {
        return rtrim($s, self::SPACE);
    }

    public static function hasMultibyteSupport(): bool
    {
        return self::$mb ??= function_exists('mb_strtolower');
    }

    /** Lower case: ASCII, and Unicode with mbstring. */
    public static function lower(string $s): string
    {
        if (self::isAscii($s) || !self::hasMultibyteSupport()) {
            return self::asciiLower($s);
        }
        return mb_strtolower($s, 'UTF-8');
    }

    /** Upper case: ASCII, and Unicode with mbstring. */
    public static function upper(string $s): string
    {
        if (!self::isAscii($s) && self::hasMultibyteSupport()) {
            return mb_strtoupper($s, 'UTF-8');
        }
        return strtr($s, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    public static function asciiLower(string $s): string
    {
        return strtr($s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    public static function isAscii(string $s): bool
    {
        return !preg_match('/[\x80-\xff]/', $s);
    }

    /** Case-insensitive comparison (ASCII fast path, Unicode lower case otherwise). */
    public static function equalFold(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (strlen($a) === strlen($b) && strcasecmp($a, $b) === 0 && self::isAscii($a)) {
            return true;
        }
        if (self::isAscii($a) && self::isAscii($b)) {
            return false;
        }
        if (self::utf16Length($a) !== self::utf16Length($b)) {
            return false;
        }
        return self::lower($a) === self::lower($b);
    }

    /** Whether $s[$at..] starts with the ASCII string $sub, ignoring ASCII case. */
    public static function startsWithFold(string $s, int $at, string $sub): bool
    {
        $n = strlen($sub);
        if ($at < 0 || $at + $n > strlen($s)) {
            return false;
        }
        return $n === 0 || strncasecmp(substr($s, $at, $n), $sub, $n) === 0;
    }

    /** Case-insensitive (ASCII) search for $sub in $s[$from, $to); -1 when it is not there. */
    public static function indexFold(string $s, string $sub, int $from, int $to): int
    {
        $n = strlen($sub);
        if ($n === 0) {
            return $from;
        }
        if ($from + $n > $to) {
            return -1;
        }
        $i = stripos($s, $sub, $from);
        return $i === false || $i + $n > $to ? -1 : $i;
    }

    /** Whether $s[$at..] starts with $sub (case-sensitive) and the match ends before $limit. */
    public static function startsWithAt(string $s, int $at, string $sub, int $limit): bool
    {
        $n = strlen($sub);
        return $at >= 0 && $at + $n <= $limit && substr_compare($s, $sub, $at, $n) === 0;
    }

    public static function isHexChar(string $c): bool
    {
        return $c !== '' && strspn($c, '0123456789abcdefABCDEF') === 1;
    }

    /** $s[$from, $to) clamped to the string, "" when $from >= $to. */
    public static function slice(string $s, int $from, int $to): string
    {
        $len = strlen($s);
        if ($to > $len) {
            $to = $len;
        }
        if ($from < 0) {
            $from = 0;
        }
        return $from >= $to ? '' : substr($s, $from, $to - $from);
    }

    /** The length in UTF-16 code units, as .NET counts. */
    public static function utf16Length(string $s): int
    {
        $n = strlen($s);
        if (!preg_match('/[\x80-\xff]/', $s)) {
            return $n;
        }
        return $n - preg_match_all('/[\x80-\xbf]/', $s) + preg_match_all('/[\xf0-\xf7]/', $s);
    }

    /** The UTF-8 encoding of a code point. */
    public static function utf8(int $cp): string
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xc0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3f));
        }
        if ($cp < 0x10000) {
            return chr(0xe0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3f)) . chr(0x80 | ($cp & 0x3f));
        }
        return chr(0xf0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3f)) . chr(0x80 | (($cp >> 6) & 0x3f))
            . chr(0x80 | ($cp & 0x3f));
    }

    /** The chars of a string (one UTF-8 sequence each; a byte of invalid UTF-8 is a char of its own). */
    public static function chars(string $s): array
    {
        if ($s === '') {
            return [];
        }
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        return $chars === false ? str_split($s) : $chars;
    }

    /** The code point of one UTF-8 char. */
    public static function codePoint(string $ch): int
    {
        $c = ord($ch[0]);
        $n = strlen($ch);
        if ($c < 0x80 || $n === 1) {
            return $c;
        }
        if ($c < 0xe0) {
            return (($c & 0x1f) << 6) | (ord($ch[1]) & 0x3f);
        }
        if ($c < 0xf0) {
            return (($c & 0x0f) << 12) | ((ord($ch[1]) & 0x3f) << 6) | (ord($ch[2]) & 0x3f);
        }
        return (($c & 0x07) << 18) | ((ord($ch[1]) & 0x3f) << 12) | ((ord($ch[2]) & 0x3f) << 6) | (ord($ch[3]) & 0x3f);
    }

    /** The string as valid UTF-8: invalid sequences become U+FFFD. */
    public static function scrub(string $s): string
    {
        if (preg_match('//u', $s)) {
            return $s;
        }
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n;) {
            if (preg_match('/\G(?:[\x00-\x7f]|[\xc2-\xdf][\x80-\xbf]|\xe0[\xa0-\xbf][\x80-\xbf]|[\xe1-\xec\xee\xef][\x80-\xbf]{2}'
                . '|\xed[\x80-\x9f][\x80-\xbf]|\xf0[\x90-\xbf][\x80-\xbf]{2}|[\xf1-\xf3][\x80-\xbf]{3}|\xf4[\x80-\x8f][\x80-\xbf]{2})+/',
                $s, $m, 0, $i)) {
                $out .= $m[0];
                $i += strlen($m[0]);
                continue;
            }
            // the maximal subpart of an invalid sequence is one U+FFFD (as the WHATWG decoder)
            $out .= "\u{FFFD}";
            $c = ord($s[$i]);
            $i++;
            $need = $c >= 0xc2 && $c <= 0xdf ? 1 : ($c >= 0xe0 && $c <= 0xef ? 2 : ($c >= 0xf0 && $c <= 0xf4 ? 3 : 0));
            $lo = $c === 0xe0 ? 0xa0 : ($c === 0xf0 ? 0x90 : 0x80);
            $hi = $c === 0xed ? 0x9f : ($c === 0xf4 ? 0x8f : 0xbf);
            for ($k = 0; $k < $need && $i < $n; $k++) {
                $d = ord($s[$i]);
                if ($d < $lo || $d > $hi) {
                    break;
                }
                $lo = 0x80;
                $hi = 0xbf;
                $i++;
            }
        }
        return $out;
    }

    /** A string as JSON writes it, for error messages. */
    public static function quote(string $s): string
    {
        return json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
