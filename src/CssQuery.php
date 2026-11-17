<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * A compiled CSS selector (or a comma-separated list of them), matched right to left over the flat tag list as browsers
 * do. Compile once and reuse it on any number of documents; the string methods ($doc->css(...),
 * $node->cssFirst(...), ...) compile and cache selectors automatically.
 *
 * Supported: type, *, #id, .class, attribute selectors ([a], =, ~=, |=, ^=, $=, *=, with the i / s flags), the
 * combinators (descendant, >, +, ~), selector lists and the pseudo-classes :first-child, :last-child, :only-child,
 * :first-of-type, :last-of-type, :only-of-type, :nth-child(An+B [of S]), :nth-last-child(), :nth-of-type(),
 * :nth-last-of-type(), :not(), :is(), :where(), :has(), :empty, :root, :scope, :link, :any-link, :checked, :disabled,
 * :enabled, :required, :optional, and :contains("text"). Pseudo-elements and namespaces are not supported.
 *
 * HTML rules: tag and attribute names are case-insensitive; ids, classes and attribute values are case-sensitive,
 * except the values the HTML standard lists (type, rel, lang, method, ...). Only elements are selected, never comments.
 */
final class CssQuery implements \Stringable
{
    // combinators
    public const COMB_NONE = 0;
    public const COMB_DESCENDANT = 1; // "a b"
    public const COMB_CHILD = 2; // "a > b"
    public const COMB_ADJACENT = 3; // "a + b"
    public const COMB_SIBLING = 4; // "a ~ b"

    // simple selectors: [kind, ...]
    private const S_ID = 0; // [S_ID, id]
    private const S_CLASS = 1; // [S_CLASS, name]
    private const S_ATTR = 2; // [S_ATTR, name, op, value, fold]
    private const S_NTH = 3; // [S_NTH, a, b, fromEnd, ofType, of (a list or null)]
    private const S_NOT = 4; // [S_NOT, list]
    private const S_IS = 5; // [S_IS, list]
    private const S_HAS = 6; // [S_HAS, list]
    private const S_CONTAINS = 7; // [S_CONTAINS, text]
    private const S_PSEUDO = 8; // [S_PSEUDO, kind]

    private const OP_EXISTS = 0; // [a]
    private const OP_EQUALS = 1; // [a=v]
    private const OP_INCLUDES = 2; // [a~=v]
    private const OP_DASH = 3; // [a|=v]
    private const OP_PREFIX = 4; // [a^=v]
    private const OP_SUFFIX = 5; // [a$=v]
    private const OP_SUBSTRING = 6; // [a*=v]

    private const PSEUDO_ROOT = 0;
    private const PSEUDO_SCOPE = 1;
    private const PSEUDO_EMPTY = 2;
    private const PSEUDO_LINK = 3;
    private const PSEUDO_CHECKED = 4;
    private const PSEUDO_DISABLED = 5;
    private const PSEUDO_ENABLED = 6;
    private const PSEUDO_REQUIRED = 7;
    private const PSEUDO_OPTIONAL = 8;

    private const COMMENT = TagNames::KEYS['!--'];
    private const DOCTYPE = TagNames::KEYS['!doctype'];
    private const XML_DECLARATION = TagNames::KEYS['?xml'];

    private const CSS_SPACE = " \t\n\x0c\r";

    // HTML: the values of these attributes are compared ignoring ASCII case unless the selector has the 's' flag.
    private const CASE_INSENSITIVE_VALUES = ['accept' => 1, 'accept-charset' => 1, 'align' => 1, 'alink' => 1,
        'axis' => 1, 'bgcolor' => 1, 'charset' => 1, 'checked' => 1, 'clear' => 1, 'codetype' => 1, 'color' => 1,
        'compact' => 1, 'declare' => 1, 'defer' => 1, 'dir' => 1, 'direction' => 1, 'disabled' => 1, 'enctype' => 1,
        'face' => 1, 'frame' => 1, 'hreflang' => 1, 'http-equiv' => 1, 'lang' => 1, 'language' => 1, 'link' => 1,
        'media' => 1, 'method' => 1, 'multiple' => 1, 'nohref' => 1, 'noresize' => 1, 'noshade' => 1, 'nowrap' => 1,
        'readonly' => 1, 'rel' => 1, 'rev' => 1, 'rules' => 1, 'scope' => 1, 'scrolling' => 1, 'selected' => 1,
        'shape' => 1, 'target' => 1, 'text' => 1, 'type' => 1, 'valign' => 1, 'valuetype' => 1, 'vlink' => 1];

    /** @var array<string, CssQuery> */
    private static array $cache = [];

    /**
     * A selector list is a list of complex selectors: ['lead' => combinator, 'c' => compounds, 'k' => combinators];
     * k[i] stands between c[i] and c[i+1]. A compound is ['key' => Tag key or -1, 'name' => custom name ("": any
     * element when key is -1), 's' => simple selectors].
     */
    private function __construct(private readonly string $selector, private readonly array $list)
    {
    }

    /** Compiles a CSS selector; throws CssSelectorException on an invalid one. */
    public static function compile(string $selector): self
    {
        $c = new CssCompiler($selector);
        $list = $c->parseList(false, true);
        if ($c->p < strlen($selector)) {
            $c->fail("unexpected '" . $selector[$c->p] . "'");
        }
        return new self($selector, $list);
    }

    /** @internal compiles and caches */
    public static function cached(string $selector): self
    {
        $q = self::$cache[$selector] ?? null;
        if ($q === null) {
            $q = self::compile($selector);
            if (count(self::$cache) >= 1024) {
                self::$cache = []; // dynamically built selectors must not grow the cache forever
            }
            self::$cache[$selector] = $q;
        }
        return $q;
    }

    /** The source of the selector. */
    public function selector(): string
    {
        return $this->selector;
    }

    public function __toString(): string
    {
        return $this->selector;
    }

    /**
     * The matching tags in the document, in document order.
     *
     * @return list<HtmlNode>
     */
    public function all(HtmlDocument $d): array
    {
        return $this->scan($d, null, 0, PHP_INT_MAX, false);
    }

    /** The first matching tag in the document, or null. */
    public function first(HtmlDocument $d): ?HtmlNode
    {
        return $this->scan($d, null, 0, PHP_INT_MAX, true)[0] ?? null;
    }

    /**
     * The matching tags inside $n (as querySelectorAll).
     *
     * @return list<HtmlNode>
     */
    public function allIn(HtmlNode $n): array
    {
        return $this->scan($n->doc, $n, $n->index + 1, $n->end, false);
    }

    /** The first matching tag inside $n, or null. */
    public function firstIn(HtmlNode $n): ?HtmlNode
    {
        return $this->scan($n->doc, $n, $n->index + 1, $n->end, true)[0] ?? null;
    }

    /** Whether the tag matches (as element.matches(): :scope is the tag itself); false for comments and null. */
    public function matches(?HtmlNode $n): bool
    {
        return $n !== null && self::isElement($n) && self::matchesList($this->list, $n, $n, $n);
    }

    /** @return list<HtmlNode> */
    private function scan(HtmlDocument $d, ?HtmlNode $scope, int $from, int $end, bool $firstOnly): array
    {
        $out = [];
        $ends = $d->ends;
        $keys = $d->keys;
        $list = $this->list;
        // the name of the rightmost compound of each selector, checked on the arrays before a tag object is made
        $names = [];
        foreach ($list as $item) {
            $last = $item['c'][count($item['c']) - 1];
            if ($last['key'] < 0 && $last['name'] === '') {
                $names = null; // any element
                break;
            }
            $names[] = $last;
        }
        for ($i = $from, $count = $d->size; $i < $count; $i++) {
            if ($ends[$i] > $end) {
                break;
            }
            if ($names !== null) {
                $k = $keys[$i];
                $candidate = false;
                foreach ($names as $cp) {
                    if ($cp['key'] >= 0 ? $k === $cp['key'] : $k < 0 && $d->customs[$i] === $cp['name']) {
                        $candidate = true;
                        break;
                    }
                }
                if (!$candidate) {
                    continue;
                }
            } elseif (!self::isElementAt($d, $i)) {
                continue;
            }
            $n = $d->at($i);
            if (self::matchesList($list, $n, $scope, $scope)) {
                $out[] = $n;
                if ($firstOnly) {
                    break;
                }
            }
        }
        return $out;
    }

    /** @internal Not a comment, the doctype or the XML declaration. */
    public static function isElement(HtmlNode $n): bool
    {
        $key = $n->key;
        if ($key >= 0) {
            return $key !== self::COMMENT && $key !== self::DOCTYPE && $key !== self::XML_DECLARATION;
        }
        $custom = $n->custom;
        return $custom !== '' && $custom[0] !== '!' && $custom[0] !== '?';
    }

    /** @internal isElement() for the tag at index $i */
    public static function isElementAt(HtmlDocument $d, int $i): bool
    {
        $key = $d->keys[$i];
        if ($key >= 0) {
            return $key !== self::COMMENT && $key !== self::DOCTYPE && $key !== self::XML_DECLARATION;
        }
        $custom = $d->customs[$i];
        return $custom !== '' && $custom[0] !== '!' && $custom[0] !== '?';
    }

    private static function tree(HtmlDocument $d): CssTree
    {
        return $d->cssTree ??= new CssTree($d);
    }

    // ----- matching -----

    /**
     * $scope: the tag the query was called on (:scope, null for the document); $anchor: the tag a relative selector
     * starts from (:has(> a), or $node->css("> li"); null: the document).
     */
    private static function matchesList(array $list, HtmlNode $n, ?HtmlNode $scope, ?HtmlNode $anchor): bool
    {
        foreach ($list as $item) {
            if (self::matchesComplex($item, count($item['c']) - 1, $n, $scope, $anchor)) {
                return true;
            }
        }
        return false;
    }

    /** $n matches compound $i and the part to the left of it matches the tags the combinators lead to. */
    private static function matchesComplex(array $cx, int $i, HtmlNode $n, ?HtmlNode $scope, ?HtmlNode $anchor): bool
    {
        if (!self::matchesCompound($cx['c'][$i], $n, $scope, $anchor)) {
            return false;
        }
        if ($i === 0) {
            return $cx['lead'] === self::COMB_NONE || self::isRelated($cx['lead'], $anchor, $n);
        }
        switch ($cx['k'][$i - 1]) {
            case self::COMB_CHILD:
                $p = $n->parent();
                return $p !== null && self::matchesComplex($cx, $i - 1, $p, $scope, $anchor);
            case self::COMB_DESCENDANT:
                for ($p = $n->parent(); $p !== null; $p = $p->parent()) {
                    if (self::matchesComplex($cx, $i - 1, $p, $scope, $anchor)) {
                        return true;
                    }
                }
                return false;
            case self::COMB_ADJACENT:
                $p = self::tree($n->doc)->prev($n);
                return $p !== null && self::matchesComplex($cx, $i - 1, $p, $scope, $anchor);
            case self::COMB_SIBLING:
                $t = self::tree($n->doc);
                for ($p = $t->prev($n); $p !== null; $p = $t->prev($p)) {
                    if (self::matchesComplex($cx, $i - 1, $p, $scope, $anchor)) {
                        return true;
                    }
                }
                return false;
            default:
                return false;
        }
    }

    private static function matchesCompound(array $cp, HtmlNode $n, ?HtmlNode $scope, ?HtmlNode $anchor): bool
    {
        if ($cp['key'] >= 0) {
            if ($n->key !== $cp['key']) {
                return false;
            }
        } elseif ($cp['name'] !== '') {
            if ($n->key >= 0 || $n->custom !== $cp['name']) {
                return false;
            }
        } elseif (!self::isElement($n)) {
            return false;
        }
        foreach ($cp['s'] as $s) {
            if (!self::matchesSimple($s, $n, $scope, $anchor)) {
                return false;
            }
        }
        return true;
    }

    /** $n stands in the combinator relation to $anchor (null: the document). */
    private static function isRelated(int $c, ?HtmlNode $anchor, HtmlNode $n): bool
    {
        switch ($c) {
            case self::COMB_CHILD:
                return $anchor === null ? $n->parentIndex < 0 : $n->parentIndex === $anchor->index;
            case self::COMB_DESCENDANT:
                if ($anchor === null) {
                    return true;
                }
                for ($p = $n->parent(); $p !== null; $p = $p->parent()) {
                    if ($p === $anchor) {
                        return true;
                    }
                }
                return false;
            case self::COMB_ADJACENT:
                return $anchor !== null && self::tree($n->doc)->prev($n) === $anchor;
            case self::COMB_SIBLING:
                return $anchor !== null && $anchor->index < $n->index && $anchor->parentIndex === $n->parentIndex
                    && self::isElement($anchor);
            default:
                return false;
        }
    }

    /** :has(): whether a tag in the anchor's scope matches the relative selector. */
    private static function hasMatch(array $relative, HtmlNode $anchor, ?HtmlNode $scope): bool
    {
        $d = $anchor->doc;
        $ends = $d->ends;
        $count = $d->size;
        foreach ($relative as $item) {
            $last = count($item['c']) - 1;
            $i = $anchor->index + 1;
            if ($item['lead'] === self::COMB_DESCENDANT || $item['lead'] === self::COMB_CHILD) {
                // the tags inside the anchor
                for (; $i < $count && $ends[$i] <= $anchor->end; $i++) {
                    if (self::isElementAt($d, $i) && self::matchesComplex($item, $last, $d->at($i), $scope, $anchor)) {
                        return true;
                    }
                }
            } else {
                // the following siblings and the tags inside them, up to the end of the parent
                while ($i < $count && $ends[$i] <= $anchor->end) {
                    $i++;
                }
                $end = $anchor->parentIndex >= 0 ? $ends[$anchor->parentIndex] : PHP_INT_MAX;
                for (; $i < $count && $ends[$i] <= $end; $i++) {
                    if (self::isElementAt($d, $i) && self::matchesComplex($item, $last, $d->at($i), $scope, $anchor)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** Whether a whitespace-separated list contains $word. */
    private static function containsWord(string $list, string $word, bool $fold): bool
    {
        if ($word === '' || strlen($list) < strlen($word)) {
            return false;
        }
        if (!$fold && strpbrk($list, self::CSS_SPACE) === false) {
            return $list === $word;
        }
        $i = 0;
        $n = strlen($list);
        for (;;) {
            $i += strspn($list, self::CSS_SPACE, $i);
            if ($i >= $n) {
                return false;
            }
            $len = strcspn($list, self::CSS_SPACE, $i);
            if (self::equal(substr($list, $i, $len), $word, $fold)) {
                return true;
            }
            $i += $len;
            if ($i >= $n) {
                return false;
            }
        }
    }

    private static function equal(string $a, string $b, bool $fold): bool
    {
        return $fold ? Chars::equalFold($a, $b) : $a === $b;
    }

    private static function decoded(string $raw): string
    {
        return strpbrk($raw, "&\r") === false ? $raw : Decode::decode($raw, true);
    }

    private static function matchesSimple(array $s, HtmlNode $n, ?HtmlNode $scope, ?HtmlNode $anchor): bool
    {
        switch ($s[0]) {
            case self::S_ID:
                return Attrs::attrEquals(Attrs::attrRaw($n, 'id'), $s[1]);
            case self::S_CLASS:
                $raw = Attrs::attrRaw($n, 'class');
                return $raw !== '' && self::containsWord(self::decoded($raw), $s[1], false);
            case self::S_ATTR:
                return self::matchesAttribute($n, $s[1], $s[2], $s[3], $s[4]);
            case self::S_NTH:
                return self::matchesNth($n, $s[1], $s[2], $s[3], $s[4], $s[5], $scope, $anchor);
            case self::S_NOT:
                return !self::matchesList($s[1], $n, $scope, $anchor);
            case self::S_IS:
                return self::matchesList($s[1], $n, $scope, $anchor);
            case self::S_HAS:
                return self::hasMatch($s[1], $n, $scope);
            case self::S_CONTAINS:
                return str_contains($n->text(), $s[1]);
            default:
                return self::matchesPseudo($s[1], $n, $scope);
        }
    }

    private static function matchesAttribute(HtmlNode $n, string $name, int $op, string $v, bool $fold): bool
    {
        $raw = Attrs::attrRaw($n, $name);
        if ($raw === '') {
            // missing or empty: only these can match an empty value
            if ($op === self::OP_EXISTS) {
                return Attrs::hasAttribute($n, $name);
            }
            if ($op === self::OP_EQUALS || $op === self::OP_DASH) {
                return $v === '' && Attrs::hasAttribute($n, $name);
            }
            return false;
        }
        if ($op === self::OP_EXISTS) {
            return true;
        }
        $actual = self::decoded($raw);
        $vl = strlen($v);
        switch ($op) {
            case self::OP_EQUALS:
                return self::equal($actual, $v, $fold);
            case self::OP_INCLUDES:
                return strpbrk($v, self::CSS_SPACE) === false && self::containsWord($actual, $v, $fold);
            case self::OP_DASH:
                return self::equal($actual, $v, $fold)
                    || (strlen($actual) > $vl && $actual[$vl] === '-' && self::equal(substr($actual, 0, $vl), $v, $fold));
            case self::OP_PREFIX:
                return $v !== '' && strlen($actual) >= $vl && self::equal(substr($actual, 0, $vl), $v, $fold);
            case self::OP_SUFFIX:
                return $v !== '' && strlen($actual) >= $vl && self::equal(substr($actual, -$vl), $v, $fold);
            case self::OP_SUBSTRING:
                if ($v === '') {
                    return false;
                }
                if (!$fold) {
                    return str_contains($actual, $v);
                }
                if (Chars::isAscii($v)) {
                    return stripos($actual, $v) !== false;
                }
                return str_contains(Chars::lower($actual), Chars::lower($v));
            default:
                return false;
        }
    }

    /** :nth-child(An+B [of S]) and its relatives; :first-child is :nth-child(1), and so on. */
    private static function matchesNth(HtmlNode $n, int $a, int $b, bool $fromEnd, bool $ofType, ?array $of,
        ?HtmlNode $scope, ?HtmlNode $anchor): bool
    {
        $t = self::tree($n->doc);
        if ($of !== null) {
            if (!self::matchesList($of, $n, $scope, $anchor)) {
                return false;
            }
            $position = 1;
            for ($sib = $fromEnd ? $t->nextOf($n) : $t->prev($n); $sib !== null; $sib = $fromEnd ? $t->nextOf($sib) : $t->prev($sib)) {
                if (self::matchesList($of, $sib, $scope, $anchor)) {
                    $position++;
                }
            }
        } elseif ($ofType) {
            $tp = $t->typePositions();
            $position = $fromEnd ? $tp[1][$n->index] : $tp[0][$n->index];
        } else {
            $position = $fromEnd ? $t->fromEnd[$n->index] : $t->position[$n->index];
        }
        if ($position <= 0) {
            return false; // not an element
        }
        // position = a*n + b for some n >= 0
        if ($a === 0) {
            return $position === $b;
        }
        $diff = $position - $b;
        return $diff % $a === 0 && intdiv($diff, $a) >= 0;
    }

    private static function isFormControl(string $name): bool
    {
        return match ($name) {
            'button', 'input', 'select', 'textarea', 'optgroup', 'option', 'fieldset' => true,
            default => false,
        };
    }

    private static function isInput(string $name): bool
    {
        return $name === 'input' || $name === 'select' || $name === 'textarea';
    }

    /** the first top-level element: <html> on a page */
    private static function isRoot(HtmlNode $n): bool
    {
        return $n->parentIndex < 0 && self::tree($n->doc)->prev($n) === null;
    }

    /** No child elements and no text; comments are allowed. */
    private static function isEmpty(HtmlNode $n): bool
    {
        if ($n->innerStart <= 0 || $n->innerEnd <= 0) {
            return true; // a void or unclosed tag has no content
        }
        $position = $n->innerStart;
        $d = $n->doc;
        $ends = $d->ends;
        for ($i = $n->index + 1, $count = $d->size; $i < $count && $ends[$i] <= $n->end; $i++) {
            if (self::isElementAt($d, $i) || $d->starts[$i] > $position) {
                return false; // an element, or text before a comment
            }
            $position = max($position, $ends[$i] + 1);
        }
        return $position > $n->innerEnd;
    }

    private static function matchesPseudo(int $kind, HtmlNode $n, ?HtmlNode $scope): bool
    {
        $name = $n->name();
        return match ($kind) {
            self::PSEUDO_ROOT => self::isRoot($n),
            self::PSEUDO_SCOPE => $scope !== null ? $n === $scope : self::isRoot($n),
            self::PSEUDO_EMPTY => self::isEmpty($n),
            self::PSEUDO_LINK => ($name === 'a' || $name === 'area') && Attrs::hasAttribute($n, 'href'),
            self::PSEUDO_CHECKED => ($name === 'input' && Attrs::hasAttribute($n, 'checked'))
                || ($name === 'option' && Attrs::hasAttribute($n, 'selected')),
            self::PSEUDO_DISABLED => self::isFormControl($name) && Attrs::hasAttribute($n, 'disabled'),
            self::PSEUDO_ENABLED => self::isFormControl($name) && !Attrs::hasAttribute($n, 'disabled'),
            self::PSEUDO_REQUIRED => self::isInput($name) && Attrs::hasAttribute($n, 'required'),
            self::PSEUDO_OPTIONAL => self::isInput($name) && !Attrs::hasAttribute($n, 'required'),
            default => false,
        };
    }

    // ----- the parts the compiler builds -----

    /** @internal */
    public static function idSelector(string $id): array
    {
        return [self::S_ID, $id];
    }

    /** @internal */
    public static function classSelector(string $name): array
    {
        return [self::S_CLASS, $name];
    }

    /** @internal $op: '' (exists), '=', '~', '|', '^', '$', '*' */
    public static function attributeSelector(string $name, string $op, string $value, ?bool $flag): array
    {
        $code = match ($op) {
            '' => self::OP_EXISTS,
            '=' => self::OP_EQUALS,
            '~' => self::OP_INCLUDES,
            '|' => self::OP_DASH,
            '^' => self::OP_PREFIX,
            '$' => self::OP_SUFFIX,
            default => self::OP_SUBSTRING,
        };
        $fold = $flag ?? isset(self::CASE_INSENSITIVE_VALUES[Chars::lower($name)]);
        return [self::S_ATTR, $name, $code, $value, $op === '' ? false : $fold];
    }

    /** @internal */
    public static function nth(int $a, int $b, bool $fromEnd, bool $ofType, ?array $of): array
    {
        return [self::S_NTH, $a, $b, $fromEnd, $ofType, $of];
    }

    /** @internal $kind: not, is, has */
    public static function logical(string $kind, array $list): array
    {
        return [$kind === 'not' ? self::S_NOT : ($kind === 'has' ? self::S_HAS : self::S_IS), $list];
    }

    /** @internal */
    public static function contains(string $text): array
    {
        return [self::S_CONTAINS, $text];
    }

    /** @internal a pseudo-class without arguments, or null for an unknown one */
    public static function pseudo(string $name): ?array
    {
        $kind = match ($name) {
            'root' => self::PSEUDO_ROOT,
            'scope' => self::PSEUDO_SCOPE,
            'empty' => self::PSEUDO_EMPTY,
            'link', 'any-link' => self::PSEUDO_LINK,
            'checked' => self::PSEUDO_CHECKED,
            'disabled' => self::PSEUDO_DISABLED,
            'enabled' => self::PSEUDO_ENABLED,
            'required' => self::PSEUDO_REQUIRED,
            'optional' => self::PSEUDO_OPTIONAL,
            default => null,
        };
        return $kind === null ? null : [self::S_PSEUDO, $kind];
    }
}
