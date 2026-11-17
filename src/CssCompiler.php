<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * @internal Parses a selector list (Selectors Level 3 and the common Level 4 pseudo-classes). Identifiers and strings
 * follow the CSS syntax, escapes included (#a\:b, .\31 0, [title="a\"b"]).
 */
final class CssCompiler
{
    private const CSS_SPACE = " \t\n\x0c\r";

    public int $p = 0;
    private readonly int $n;

    public function __construct(private readonly string $s)
    {
        $this->n = strlen($s);
    }

    /** @return never */
    public function fail(string $msg): never
    {
        throw new CssSelectorException('invalid CSS selector ' . Chars::quote($this->s) . " at position {$this->p}: $msg");
    }

    private function peek(int $offset): string
    {
        $i = $this->p + $offset;
        return $i < $this->n ? $this->s[$i] : '';
    }

    private function skipSpace(): bool
    {
        $k = $this->p < $this->n ? strspn($this->s, self::CSS_SPACE, $this->p) : 0;
        $this->p += $k;
        return $k > 0;
    }

    private function expect(string $ch): void
    {
        if ($this->peek(0) !== $ch) {
            if ($this->p >= $this->n) {
                $this->fail("'$ch' expected");
            }
            $this->fail("'$ch' expected instead of '{$this->s[$this->p]}'");
        }
        $this->p++;
    }

    /** selector-list: complex (',' complex)* */
    public function parseList(bool $relative, bool $topLevel): array
    {
        $list = [];
        for (;;) {
            $list[] = $this->parseComplex($relative, $topLevel);
            $this->skipSpace();
            if ($this->peek(0) !== ',') {
                return $list;
            }
            $this->p++;
        }
    }

    /** complex: [combinator] compound (combinator compound)* */
    private function parseComplex(bool $relative, bool $topLevel): array
    {
        $this->skipSpace();
        $cx = ['lead' => CssQuery::COMB_NONE, 'c' => [], 'k' => []];
        if ($relative || $topLevel) {
            $comb = $this->readCombinator();
            if ($comb !== CssQuery::COMB_NONE) {
                if ($topLevel && $comb !== CssQuery::COMB_CHILD) {
                    $this->fail("a selector can start only with '>' (the children of the tag it is called on)");
                }
                $cx['lead'] = $comb;
            } elseif ($relative) {
                $cx['lead'] = CssQuery::COMB_DESCENDANT;
            }
        }
        $cx['c'][] = $this->parseCompound();
        for (;;) {
            $ws = $this->skipSpace();
            $ch = $this->peek(0);
            if ($ch === '' || $ch === ',' || $ch === ')') {
                return $cx; // end, ',' or ')'
            }
            $comb = $this->readCombinator();
            if ($comb === CssQuery::COMB_NONE) {
                if (!$ws) {
                    $this->fail("unexpected '{$this->s[$this->p]}'");
                }
                $comb = CssQuery::COMB_DESCENDANT;
            }
            $cx['k'][] = $comb;
            $cx['c'][] = $this->parseCompound();
        }
    }

    private function readCombinator(): int
    {
        $comb = match ($this->peek(0)) {
            '>' => CssQuery::COMB_CHILD,
            '+' => CssQuery::COMB_ADJACENT,
            '~' => CssQuery::COMB_SIBLING,
            default => CssQuery::COMB_NONE,
        };
        if ($comb !== CssQuery::COMB_NONE) {
            $this->p++;
            $this->skipSpace();
        }
        return $comb;
    }

    /** compound: [type | '*'] (#id | .class | [attribute] | :pseudo)* */
    private function parseCompound(): array
    {
        $cp = ['key' => -1, 'name' => '', 's' => []];
        $hasType = false;
        if ($this->peek(0) === '*') {
            $this->p++;
            $hasType = true;
        } elseif ($this->isIdentStart($this->p)) {
            $name = Chars::lower($this->readName());
            $hasType = true;
            $t = Parser::lookupTag($name);
            if ($t >= 0) {
                $cp['key'] = $t;
            } else {
                $cp['name'] = $name;
            }
        }
        if ($this->peek(0) === '|') {
            $this->fail('namespaces are not supported; escape the colon of a prefixed name: dc\\:creator');
        }

        for (;;) {
            $ch = $this->peek(0);
            if ($ch === '#') {
                $this->p++;
                if (!$this->isNameChar($this->p)) {
                    $this->fail("an id expected after '#'");
                }
                $cp['s'][] = CssQuery::idSelector($this->readName());
            } elseif ($ch === '.') {
                $this->p++;
                if (!$this->isIdentStart($this->p)) {
                    $this->fail("a class name expected after '.'");
                }
                $cp['s'][] = CssQuery::classSelector($this->readName());
            } elseif ($ch === '[') {
                $cp['s'][] = $this->parseAttribute();
            } elseif ($ch === ':') {
                $this->parsePseudo($cp);
            } else {
                break;
            }
        }

        if (!$hasType && $cp['s'] === []) {
            if ($this->p >= $this->n) {
                $this->fail('a selector expected');
            }
            $this->fail("unexpected '{$this->s[$this->p]}'");
        }
        return $cp;
    }

    /** '[' name [op value [i|s]] ']' */
    private function parseAttribute(): array
    {
        $this->p++; // '['
        $this->skipSpace();
        if (!$this->isIdentStart($this->p)) {
            $this->fail('an attribute name expected');
        }
        $name = $this->readName();
        $this->skipSpace();
        if ($this->peek(0) === ']') {
            $this->p++;
            return CssQuery::attributeSelector($name, '', '', false);
        }

        $ch = $this->peek(0);
        if ($ch === '=') {
            $op = '=';
            $this->p++;
        } elseif ($ch === '~' || $ch === '|' || $ch === '^' || $ch === '$' || $ch === '*') {
            $op = $ch;
            if ($this->peek(1) !== '=') {
                $this->fail("'=' expected after '$ch'");
            }
            $this->p += 2;
        } else {
            $this->fail("'=', '~=', '|=', '^=', '\$=', '*=' or ']' expected");
        }

        $this->skipSpace();
        if ($this->peek(0) === '"' || $this->peek(0) === "'") {
            $value = $this->readString();
        } elseif ($this->isIdentStart($this->p)) {
            $value = $this->readName();
        } else {
            $this->fail('an attribute value expected (quote it if it is not a plain word)');
        }

        $this->skipSpace();
        $flag = null;
        if ($this->isIdentStart($this->p)) {
            $f = $this->readName();
            if (Chars::equalFold($f, 'i')) {
                $flag = true;
            } elseif (Chars::equalFold($f, 's')) {
                $flag = false;
            } else {
                $this->fail("unknown attribute flag '$f'");
            }
            $this->skipSpace();
        }
        $this->expect(']');
        return CssQuery::attributeSelector($name, $op, $value, $flag);
    }

    private function parsePseudo(array &$cp): void
    {
        $this->p++; // ':'
        if ($this->peek(0) === ':') {
            $this->fail('pseudo-elements (::before, ::text) select no tags');
        }
        if (!$this->isIdentStart($this->p)) {
            $this->fail("a pseudo-class name expected after ':'");
        }
        $start = $this->p;
        $name = Chars::lower($this->readName());

        if ($this->peek(0) === '(') {
            $this->p++;
            $this->skipSpace();
            switch ($name) {
                case 'not':
                    $cp['s'][] = CssQuery::logical('not', $this->parseList(false, false));
                    break;
                case 'is':
                case 'where':
                case 'matches':
                case 'any':
                    $cp['s'][] = CssQuery::logical('is', $this->parseList(false, false));
                    break;
                case 'has':
                    $cp['s'][] = CssQuery::logical('has', $this->parseList(true, false));
                    break;
                case 'nth-child':
                case 'nth-last-child':
                case 'nth-of-type':
                case 'nth-last-of-type':
                    [$a, $b] = $this->parseNth();
                    $ofType = str_ends_with($name, 'of-type');
                    $of = null;
                    $this->skipSpace();
                    if (!$ofType && $this->isWord('of')) {
                        $this->p += 2;
                        $of = $this->parseList(false, false);
                    }
                    $cp['s'][] = CssQuery::nth($a, $b, str_contains($name, 'last'), $ofType, $of);
                    break;
                case 'contains':
                    if ($this->peek(0) === '"' || $this->peek(0) === "'") {
                        $text = $this->readString();
                    } elseif ($this->isIdentStart($this->p)) {
                        $text = $this->readName();
                    } else {
                        $this->fail('a string expected in :contains()');
                    }
                    $cp['s'][] = CssQuery::contains($text);
                    break;
                default:
                    $this->p = $start;
                    $this->fail("unknown pseudo-class ':$name()'");
            }
            $this->skipSpace();
            $this->expect(')');
            return;
        }

        switch ($name) {
            case 'first-child':
                $cp['s'][] = CssQuery::nth(0, 1, false, false, null);
                return;
            case 'last-child':
                $cp['s'][] = CssQuery::nth(0, 1, true, false, null);
                return;
            case 'only-child':
                $cp['s'][] = CssQuery::nth(0, 1, false, false, null);
                $cp['s'][] = CssQuery::nth(0, 1, true, false, null);
                return;
            case 'first-of-type':
                $cp['s'][] = CssQuery::nth(0, 1, false, true, null);
                return;
            case 'last-of-type':
                $cp['s'][] = CssQuery::nth(0, 1, true, true, null);
                return;
            case 'only-of-type':
                $cp['s'][] = CssQuery::nth(0, 1, false, true, null);
                $cp['s'][] = CssQuery::nth(0, 1, true, true, null);
                return;
            default:
                $pseudo = CssQuery::pseudo($name);
                if ($pseudo === null) {
                    $this->p = $start;
                    $this->fail("unknown pseudo-class ':$name'");
                }
                $cp['s'][] = $pseudo;
        }
    }

    /**
     * An+B: odd, even, 3, n, -n+3, 2n+1, +2n - 1
     *
     * @return array{int, int}
     */
    private function parseNth(): array
    {
        if ($this->isWord('odd')) {
            $this->p += 3;
            return [2, 1];
        }
        if ($this->isWord('even')) {
            $this->p += 4;
            return [2, 0];
        }
        $start = $this->p;
        $t = '';
        while ($this->p < $this->n) {
            $ch = $this->s[$this->p];
            if (!($ch === '+' || $ch === '-' || $ch === 'n' || $ch === 'N' || ctype_digit($ch) || str_contains(self::CSS_SPACE, $ch))) {
                break;
            }
            if (!str_contains(self::CSS_SPACE, $ch)) {
                $t .= $ch === 'N' ? 'n' : $ch;
            }
            $this->p++;
        }
        $n = strpos($t, 'n');
        if ($n === false) {
            $v = self::parseInt32($t);
            if ($v !== null) {
                return [0, $v];
            }
        } elseif (strpos($t, 'n', $n + 1) === false) {
            $aText = substr($t, 0, $n);
            $bText = substr($t, $n + 1);
            $a = $aText === '' || $aText === '+' ? 1 : ($aText === '-' ? -1 : self::parseInt32($aText));
            $b = 0;
            if ($bText !== '') {
                $ok = ($bText[0] === '+' || $bText[0] === '-') && strlen($bText) > 1 && ctype_digit($bText[1]);
                $b = $ok ? self::parseInt32($bText) : null;
            }
            if ($a !== null && $b !== null) {
                return [$a, $b];
            }
        }
        $this->p = $start;
        $this->fail('An+B expected (odd, even, 3, 2n+1, -n+3)');
    }

    private static function parseInt32(string $s): ?int
    {
        $i = $s !== '' && ($s[0] === '+' || $s[0] === '-') ? 1 : 0;
        if ($i === strlen($s) || strspn($s, '0123456789', $i) !== strlen($s) - $i) {
            return null;
        }
        $digits = ltrim(substr($s, $i), '0');
        if (strlen($digits) > 10) {
            return null;
        }
        $v = (int) $digits;
        if ($s[0] === '-') {
            $v = -$v;
        }
        return $v >= -2147483648 && $v <= 2147483647 ? $v : null;
    }

    /** The identifier at the position is $word (case-insensitive) and ends there. */
    private function isWord(string $word): bool
    {
        $len = strlen($word);
        return $this->p + $len <= $this->n && strncasecmp(substr($this->s, $this->p, $len), $word, $len) === 0
            && !$this->isNameChar($this->p + $len);
    }

    private function isEscape(int $p): bool
    {
        if ($p >= $this->n || $this->s[$p] !== '\\') {
            return false;
        }
        if ($p + 1 >= $this->n) {
            return true;
        }
        $c = $this->s[$p + 1];
        return $c !== "\n" && $c !== "\r" && $c !== "\x0c";
    }

    private static function isNameStart(string $c): bool
    {
        return ctype_alpha($c) || $c === '_' || $c >= "\x80";
    }

    private function isNameChar(int $p): bool
    {
        if ($p >= $this->n) {
            return false;
        }
        $c = $this->s[$p];
        return self::isNameStart($c) || ctype_digit($c) || $c === '-' || $this->isEscape($p);
    }

    private function isIdentStart(int $p): bool
    {
        if ($p >= $this->n) {
            return false;
        }
        if ($this->s[$p] === '-') {
            $p++;
            if ($p >= $this->n) {
                return false;
            }
            $c = $this->s[$p];
            return self::isNameStart($c) || $c === '-' || $this->isEscape($p);
        }
        return self::isNameStart($this->s[$p]) || $this->isEscape($p);
    }

    private function readName(): string
    {
        $b = '';
        while ($this->isNameChar($this->p)) {
            if ($this->s[$this->p] === '\\') {
                $b .= $this->readEscape();
            } else {
                $b .= $this->s[$this->p++];
            }
        }
        return $b;
    }

    private function readString(): string
    {
        $quote = $this->s[$this->p];
        $start = $this->p;
        $this->p++;
        $b = '';
        for (;;) {
            if ($this->p >= $this->n) {
                $this->p = $start;
                $this->fail('unterminated string');
            }
            $ch = $this->s[$this->p];
            if ($ch === $quote) {
                $this->p++;
                return $b;
            }
            if ($ch === '\\') {
                $next = $this->peek(1);
                if ($this->p + 1 < $this->n && ($next === "\n" || $next === "\x0c")) {
                    $this->p += 2; // an escaped line break continues the string
                } elseif ($this->p + 2 < $this->n && $next === "\r") {
                    $this->p += $this->peek(2) === "\n" ? 3 : 2;
                } else {
                    $b .= $this->readEscape();
                }
            } else {
                $b .= $ch;
                $this->p++;
            }
        }
    }

    /** '\' then up to 6 hex digits and one optional whitespace, or any other char as itself. */
    private function readEscape(): string
    {
        $this->p++; // '\'
        if ($this->p >= $this->n) {
            return "\u{FFFD}";
        }
        if (!ctype_xdigit($this->s[$this->p])) {
            $c = ord($this->s[$this->p]);
            $len = $c >= 0xf0 ? 4 : ($c >= 0xe0 ? 3 : ($c >= 0xc0 ? 2 : 1));
            $ch = substr($this->s, $this->p, $len);
            $this->p += strlen($ch);
            return $ch;
        }
        $digits = strspn($this->s, '0123456789abcdefABCDEF', $this->p, 6);
        $code = hexdec(substr($this->s, $this->p, $digits));
        $this->p += $digits;
        if ($this->p < $this->n && str_contains(self::CSS_SPACE, $this->s[$this->p])) {
            $this->p += $this->s[$this->p] === "\r" && $this->peek(1) === "\n" ? 2 : 1;
        }
        if ($code === 0 || $code > 0x10ffff || ($code >= 0xd800 && $code <= 0xdfff)) {
            return "\u{FFFD}";
        }
        return Chars::utf8($code);
    }
}
