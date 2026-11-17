<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * A compiled XPath 1.0 expression over the flat tag list: the root, elements, comments, text nodes (runs of source
 * text between tags) and attributes (positions in the opening tag). Compile once and reuse it on any number of
 * documents; the string methods ($node->xpath(...), $doc->xpathValue(...), ...) compile and cache expressions
 * automatically.
 *
 * All axes (except namespace), predicates, |, the operators and the XPath 1.0 functions are supported, plus
 * ends-with(), lower-case() and upper-case(). HTML rules apply: names are case-insensitive, the string-value of an
 * element is its visible text (as HtmlNode::text()), attribute values and text are decoded. Variables are not
 * supported. On a node, relative paths start at the node; '/' and '//' search the whole document.
 */
final class XPathQuery implements \Stringable
{
    // value types
    public const TYPE_NODE_SET = 0;
    public const TYPE_NUMBER = 1;
    public const TYPE_STRING = 2;
    public const TYPE_BOOLEAN = 3;
    private const TYPE_NAMES = ['node-set', 'number', 'string', 'boolean'];

    // expressions: ['op' => E_*, 'type' => TYPE_*, 'pos' => uses position() or last(), ...]
    public const E_LITERAL = 0; // value
    public const E_NUMBER = 1; // value
    public const E_AND = 2; // l, r
    public const E_OR = 3; // l, r
    public const E_COMPARE = 4; // cmp, l, r
    public const E_ARITH = 5; // arith, l, r
    public const E_NEGATE = 6; // e
    public const E_UNION = 7; // l, r
    public const E_ATTR_EQUALS = 8; // name, value, negate: @name = 'literal' compared in place
    public const E_ATTR_EXISTS = 9; // name: [@name]
    public const E_PATH = 10; // start (expr or null), absolute, steps
    public const E_FILTER = 11; // primary, preds
    public const E_FUNCTION = 12; // fn, args

    public const OP_EQ = 0;
    public const OP_NEQ = 1;
    public const OP_LT = 2;
    public const OP_LE = 3;
    public const OP_GT = 4;
    public const OP_GE = 5;

    public const ARITH_ADD = 0;
    public const ARITH_SUB = 1;
    public const ARITH_MUL = 2;
    public const ARITH_DIV = 3;
    public const ARITH_MOD = 4;

    // node tests: ['k' => TK_*, 'name' => ..., 'q' => Query for TK_NAME]
    public const TK_NAME = 0;
    public const TK_PREFIX = 1; // dc:*
    public const TK_WILDCARD = 2; // *
    public const TK_NODE = 3; // node()
    public const TK_TEXT = 4; // text()
    public const TK_COMMENT = 5; // comment()
    public const TK_PI = 6; // processing-instruction(): never matches
    public const TK_CONTAINER = 7; // internal: the root and elements (// before a child or attribute step)

    // steps: ['axis' => AXIS_*, 'test' => node test, 'preds' => list of expressions, 'positional' => bool]
    public const AXIS_CHILD = 0;
    public const AXIS_DESCENDANT = 1;
    public const AXIS_DESCENDANT_OR_SELF = 2;
    public const AXIS_SELF = 3;
    public const AXIS_PARENT = 4;
    public const AXIS_ANCESTOR = 5;
    public const AXIS_ANCESTOR_OR_SELF = 6;
    public const AXIS_FOLLOWING_SIBLING = 7;
    public const AXIS_PRECEDING_SIBLING = 8;
    public const AXIS_FOLLOWING = 9;
    public const AXIS_PRECEDING = 10;
    public const AXIS_ATTRIBUTE = 11;

    public const ANY_NODE_TEST = ['k' => self::TK_NODE, 'name' => '', 'q' => null];
    public const CONTAINERS_TEST = ['k' => self::TK_CONTAINER, 'name' => '', 'q' => null];

    /** @var array<string, XPathQuery> */
    private static array $cache = [];

    // the state of the evaluation: the document and every element, comment and text node in document order
    private static ?HtmlDocument $doc = null;
    /** @var list<XNode>|null */
    private static ?array $all = null;

    private function __construct(private readonly string $expression, private readonly array $expr)
    {
    }

    /** Compiles an XPath 1.0 expression; throws XPathException on an invalid one. */
    public static function compile(string $expression): self
    {
        return new self($expression, (new XPathCompiler($expression))->parse());
    }

    /** @internal compiles and caches */
    public static function cached(string $expression): self
    {
        $q = self::$cache[$expression] ?? null;
        if ($q === null) {
            $q = self::compile($expression);
            if (count(self::$cache) >= 1024) {
                self::$cache = []; // dynamically built expressions must not grow the cache forever
            }
            self::$cache[$expression] = $q;
        }
        return $q;
    }

    /** The source of the expression. */
    public function expression(): string
    {
        return $this->expression;
    }

    public function __toString(): string
    {
        return $this->expression;
    }

    /**
     * The selected tags (elements and comments) in document order, evaluated on the document. Throws XPathException
     * when the expression does not give a node-set (use value()).
     *
     * @return list<HtmlNode>
     */
    public function nodes(HtmlDocument $d): array
    {
        return $this->tagsOf(XNode::root($d));
    }

    /**
     * nodes() evaluated on a tag: relative paths start at it.
     *
     * @return list<HtmlNode>
     */
    public function nodesFrom(HtmlNode $n): array
    {
        return $this->tagsOf(XNode::ofTag($n));
    }

    /**
     * The string-values of the selected nodes, or the result of an expression that gives a number, string or
     * boolean (count(//a), concat(...)) as one value.
     *
     * @return list<string>
     */
    public function values(HtmlDocument $d): array
    {
        return $this->valuesOf(XNode::root($d));
    }

    /**
     * values() evaluated on a tag.
     *
     * @return list<string>
     */
    public function valuesFrom(HtmlNode $n): array
    {
        return $this->valuesOf(XNode::ofTag($n));
    }

    /** The string-value of the first selected node, or the result of the expression; null when it selects no node. */
    public function value(HtmlDocument $d): ?string
    {
        return $this->valueOf(XNode::root($d));
    }

    /** value() evaluated on a tag. */
    public function valueFrom(HtmlNode $n): ?string
    {
        return $this->valueOf(XNode::ofTag($n));
    }

    private function evaluate(XNode $context): mixed
    {
        self::$doc = $context->doc;
        self::$all = null;
        try {
            return self::evalExpr($this->expr, $context, 1, 1);
        } finally {
            self::$doc = null;
            self::$all = null;
        }
    }

    private function tagsOf(XNode $context): array
    {
        if ($this->expr['type'] !== self::TYPE_NODE_SET) {
            throw new XPathException('the XPath ' . Chars::quote($this->expression) . ' gives a '
                . self::TYPE_NAMES[$this->expr['type']] . ', not tags; use xpathValue');
        }
        $tags = [];
        foreach ($this->evaluate($context) as $n) {
            if ($n->kind === XNode::ELEMENT || $n->kind === XNode::COMMENT) {
                $tags[] = $n->tag;
            }
        }
        return $tags;
    }

    private function valuesOf(XNode $context): array
    {
        $result = $this->evaluate($context);
        if (!is_array($result)) {
            return [self::toStr($result)];
        }
        $out = [];
        foreach ($result as $n) {
            $out[] = self::stringValue($n);
        }
        return $out;
    }

    private function valueOf(XNode $context): ?string
    {
        $result = $this->evaluate($context);
        if (is_array($result)) {
            return $result === [] ? null : self::stringValue($result[0]);
        }
        return self::toStr($result);
    }

    // ----- node tests -----

    private static function matchesTag(array $test, HtmlNode $n): bool
    {
        return self::matchesTagAt($test, $n->doc, $n->index);
    }

    /** The node test on the tag at index $i, read from the arrays of the document. */
    private static function matchesTagAt(array $test, HtmlDocument $d, int $i): bool
    {
        switch ($test['k']) {
            case self::TK_NAME:
                return $test['q']->nameMatchesAt($d, $i);
            case self::TK_PREFIX:
                $k = $d->keys[$i];
                $name = $k >= 0 ? TagNames::NAMES[$k] : $d->customs[$i];
                $len = strlen($test['name']);
                return CssQuery::isElementAt($d, $i) && strlen($name) >= $len && Chars::equalFold(substr($name, 0, $len), $test['name']);
            case self::TK_WILDCARD:
            case self::TK_CONTAINER:
                return CssQuery::isElementAt($d, $i);
            case self::TK_NODE:
                return CssQuery::isElementAt($d, $i) || $d->keys[$i] === Parser::COMMENT;
            case self::TK_COMMENT:
                return $d->keys[$i] === Parser::COMMENT;
            default:
                return false;
        }
    }

    private static function matchesText(array $test): bool
    {
        return $test['k'] === self::TK_NODE || $test['k'] === self::TK_TEXT;
    }

    private static function matchesTags(array $test): bool
    {
        return $test['k'] !== self::TK_TEXT && $test['k'] !== self::TK_PI;
    }

    private static function matchesAttribute(array $test, string $attribute): bool
    {
        switch ($test['k']) {
            case self::TK_NAME:
                return Chars::equalFold($attribute, $test['name']);
            case self::TK_PREFIX:
                $len = strlen($test['name']);
                return strlen($attribute) >= $len && Chars::equalFold(substr($attribute, 0, $len), $test['name']);
            case self::TK_WILDCARD:
            case self::TK_NODE:
                return true;
            default:
                return false;
        }
    }

    private static function matches(array $test, XNode $x, bool $attributeAxis): bool
    {
        switch ($x->kind) {
            case XNode::ROOT:
                return $test['k'] === self::TK_NODE || $test['k'] === self::TK_CONTAINER;
            case XNode::ELEMENT:
            case XNode::COMMENT:
                return self::matchesTag($test, $x->tag);
            case XNode::TEXT:
                return self::matchesText($test);
            default:
                if ($attributeAxis) {
                    return self::matchesAttribute($test, $x->attrName());
                }
                return $test['k'] === self::TK_NODE;
        }
    }

    // ----- navigation -----

    /** Appends the children of the root or an element that match the test, text nodes included, in document order. */
    private static function addChildren(XNode $x, array $test, array &$out): void
    {
        $wantText = self::matchesText($test);
        $wantTags = self::matchesTags($test);
        if (!$wantText && !$wantTags) {
            return;
        }
        if ($x->kind === XNode::ROOT) {
            $first = 0;
            $end = PHP_INT_MAX;
            $childDepth = 0;
            $p = 0;
            $textEnd = strlen($x->doc->source);
        } elseif ($x->kind === XNode::ELEMENT && $x->tag->innerStart > 0 && $x->tag->innerEnd > 0) {
            $t = $x->tag;
            $first = $t->index + 1;
            $end = $t->end;
            $childDepth = $t->depth + 1;
            $p = $t->innerStart;
            $textEnd = $t->innerEnd + 1;
        } else {
            return;
        }
        $doc = $x->doc;
        $ends = $doc->ends;
        $depths = $doc->depths;
        $starts = $doc->starts;
        $parent = $x->kind === XNode::ELEMENT ? $x->tag : null;
        for ($i = $first, $count = $doc->size; $i < $count; $i++) {
            if ($ends[$i] > $end) {
                break;
            }
            if ($depths[$i] !== $childDepth) {
                continue;
            }
            $start = $starts[$i];
            if ($wantText && $start > $p) {
                $e = min($start, $textEnd);
                if ($e > $p) {
                    $out[] = new XNode(XNode::TEXT, $doc, $parent, $p, $e - $p);
                }
            }
            if ($wantTags && self::matchesTagAt($test, $doc, $i)) {
                $out[] = XNode::ofTag($doc->at($i));
            }
            $p = $ends[$i] + 1;
        }
        if ($wantText && $textEnd > $p) {
            $out[] = new XNode(XNode::TEXT, $doc, $parent, $p, $textEnd - $p);
        }
    }

    /** Appends the descendants of the root or an element that match the test, in document order. */
    private static function addDescendants(XNode $x, array $test, array &$out): void
    {
        if (self::matchesText($test)) {
            self::addDescendantsWithText($x, $test, $out);
            return;
        }
        if (!self::matchesTags($test)) {
            return;
        }
        if ($x->kind === XNode::ROOT) {
            $first = 0;
            $end = PHP_INT_MAX;
        } elseif ($x->kind === XNode::ELEMENT && $x->tag->innerStart > 0 && $x->tag->innerEnd > 0) {
            $first = $x->tag->index + 1;
            $end = $x->tag->end;
        } else {
            return;
        }
        // tags only: one linear scan of the flat list
        $d = $x->doc;
        $ends = $d->ends;
        $count = $d->size;
        if ($test['k'] === self::TK_NAME) {
            $q = $test['q'];
            for ($i = $first; $i < $count; $i++) {
                if ($ends[$i] > $end) {
                    break;
                }
                if ($q->nameMatchesAt($d, $i)) {
                    $out[] = XNode::ofTag($d->at($i));
                }
            }
            return;
        }
        for ($i = $first; $i < $count; $i++) {
            if ($ends[$i] > $end) {
                break;
            }
            if (self::matchesTagAt($test, $d, $i)) {
                $out[] = XNode::ofTag($d->at($i));
            }
        }
    }

    private static function addDescendantsWithText(XNode $x, array $test, array &$out): void
    {
        $children = [];
        self::addChildren($x, self::ANY_NODE_TEST, $children);
        foreach ($children as $child) {
            if (self::matches($test, $child, false)) {
                $out[] = $child;
            }
            if ($child->kind === XNode::ELEMENT) {
                self::addDescendantsWithText($child, $test, $out);
            }
        }
    }

    private static function parentNode(XNode $x): ?XNode
    {
        switch ($x->kind) {
            case XNode::ROOT:
                return null;
            case XNode::TEXT:
            case XNode::ATTRIBUTE:
                return $x->tag !== null ? XNode::ofTag($x->tag) : XNode::root($x->doc);
            default:
                $t = $x->tag;
                return $t->depth > 0 && $t->parentIndex >= 0 ? XNode::ofTag($x->doc->at($t->parentIndex)) : XNode::root($x->doc);
        }
    }

    private static function addAttributes(XNode $x, array $test, array &$out): void
    {
        if ($x->kind !== XNode::ELEMENT) {
            return;
        }
        $s = new AttrScanner($x->tag);
        while ($s->next()) {
            if (self::matchesAttribute($test, $s->name())) {
                $out[] = new XNode(XNode::ATTRIBUTE, $x->doc, $x->tag, $s->nameStart, $s->nameLength, $s->valueStart, $s->valueLength);
            }
        }
    }

    /** The raw value of the first attribute with the name; null when the element has none. */
    private static function attributeOf(XNode $x, string $name): ?string
    {
        if ($x->kind !== XNode::ELEMENT) {
            return null;
        }
        $s = new AttrScanner($x->tag);
        while ($s->next()) {
            if (Chars::equalFold($s->name(), $name)) {
                return $s->rawValue();
            }
        }
        return null;
    }

    /** The root and elements give their visible text; text nodes and attributes their decoded value; comments their content. */
    private static function stringValue(XNode $x): string
    {
        switch ($x->kind) {
            case XNode::ROOT:
                return Text::clearTags($x->doc->source);
            case XNode::ELEMENT:
                return $x->tag->text();
            case XNode::ATTRIBUTE:
                return $x->valueStart < 0 ? '' : Decode::decode(substr($x->doc->source, $x->valueStart, $x->valueLength), true);
            case XNode::TEXT:
                $text = substr($x->doc->source, $x->start, $x->length);
                if ($x->tag !== null && isset(Parser::RAW_TEXT_ENDS[$x->tag->key])) {
                    return Decode::normalizeNewlines($text);
                }
                return Decode::decode($text, false);
            default:
                // comment
                $t = $x->tag;
                $source = Chars::slice($t->doc->source, $t->start, $t->end + 1);
                if (str_starts_with($source, '<!--')) {
                    $source = substr($source, 4);
                }
                if (str_ends_with($source, '-->')) {
                    $source = substr($source, 0, -3);
                }
                return $source;
        }
    }

    /** name() / local-name(): the lower-case tag or attribute name; "" for other nodes. */
    private static function nodeName(XNode $x): string
    {
        if ($x->kind === XNode::ELEMENT) {
            return $x->tag->name();
        }
        if ($x->kind === XNode::ATTRIBUTE) {
            return Chars::lower($x->attrName());
        }
        return '';
    }

    // ----- values: a node-set (list<XNode>), float, string or bool -----

    private static function toBoolean(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_float($v)) {
            return $v != 0 && !is_nan($v);
        }
        if (is_string($v)) {
            return $v !== '';
        }
        return $v !== [];
    }

    private static function toNumber(mixed $v): float
    {
        if (is_float($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? 1.0 : 0.0;
        }
        if (is_string($v)) {
            return self::stringToNumber($v);
        }
        return $v === [] ? NAN : self::stringToNumber(self::stringValue($v[0]));
    }

    /** Optional '-', digits with an optional decimal point; anything else is NaN. */
    private static function stringToNumber(string $value): float
    {
        $s = Chars::trimSpace($value);
        $body = str_starts_with($s, '-') ? substr($s, 1) : $s;
        $digits = strspn($body, '0123456789.');
        if ($digits !== strlen($body) || substr_count($body, '.') > 1 || strlen($body) - substr_count($body, '.') === 0) {
            return NAN;
        }
        return (float) $s;
    }

    private static function toStr(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_float($v)) {
            return self::numberToString($v);
        }
        return $v === [] ? '' : self::stringValue($v[0]);
    }

    /** The shortest round-trip digits, written without an exponent (XPath has none), as JavaScript writes a number. */
    public static function numberToString(float $d): string
    {
        if (is_nan($d)) {
            return 'NaN';
        }
        if (is_infinite($d)) {
            return $d > 0 ? 'Infinity' : '-Infinity';
        }
        if ($d == 0) {
            return '0';
        }
        if ($d == floor($d) && abs($d) < 1e15) {
            return (string) (int) $d;
        }
        // the fewest significant digits that read back as d, rounded from the exact binary value
        $s = '';
        for ($precision = 1; $precision <= 17; $precision++) {
            $s = sprintf('%.' . ($precision - 1) . 'e', $d);
            if ((float) $s === $d) {
                break;
            }
        }
        preg_match('/^-?(\d)(?:\.(\d+))?e([+-]?\d+)$/', $s, $m);
        $digits = rtrim($m[1] . ($m[2] ?? ''), '0');
        $pointAt = (int) $m[3] + 1; // position of the decimal point in digits
        $out = $d < 0 ? '-' : '';
        $len = strlen($digits);
        if ($pointAt <= 0) {
            $out .= '0.' . str_repeat('0', -$pointAt) . $digits;
        } elseif ($pointAt >= $len) {
            $out .= $digits . str_repeat('0', $pointAt - $len);
        } else {
            $out .= substr($digits, 0, $pointAt) . '.' . substr($digits, $pointAt);
        }
        return $out;
    }

    private static function compareKeys(XNode $a, XNode $b): int
    {
        return ($a->major <=> $b->major) ?: ($a->minor <=> $b->minor);
    }

    /** Puts the nodes in document order without duplicates. */
    private static function sortUnique(array $nodes): array
    {
        $count = count($nodes);
        if ($count < 2) {
            return $nodes;
        }
        $sorted = true;
        for ($i = 1; $i < $count; $i++) {
            $a = $nodes[$i - 1];
            $b = $nodes[$i];
            if ($a->major > $b->major || ($a->major === $b->major && $a->minor >= $b->minor)) {
                $sorted = false;
                break;
            }
        }
        if ($sorted) {
            return $nodes;
        }
        usort($nodes, self::compareKeys(...)); // stable
        $out = [$nodes[0]];
        $last = $nodes[0];
        for ($i = 1; $i < $count; $i++) {
            $n = $nodes[$i];
            if ($n->major !== $last->major || $n->minor !== $last->minor) {
                $out[] = $n;
                $last = $n;
            }
        }
        return $out;
    }

    private static function allNodes(): array
    {
        if (self::$all === null) {
            $all = [];
            self::addDescendants(XNode::root(self::$doc), self::ANY_NODE_TEST, $all);
            self::$all = $all;
        }
        return self::$all;
    }

    // ----- evaluation -----

    private static function evalNodes(array $e, XNode $node, int $position, int $size): array
    {
        $v = self::evalExpr($e, $node, $position, $size);
        if (is_array($v)) {
            return $v;
        }
        throw new XPathException('the XPath expression does not give a node-set');
    }

    private static function evalString(array $e, XNode $node, int $position, int $size): string
    {
        return self::toStr(self::evalExpr($e, $node, $position, $size));
    }

    private static function evalNumber(array $e, XNode $node, int $position, int $size): float
    {
        return self::toNumber(self::evalExpr($e, $node, $position, $size));
    }

    private static function evalBool(array $e, XNode $node, int $position, int $size): bool
    {
        switch ($e['op']) {
            case self::E_LITERAL:
                return $e['value'] !== '';
            case self::E_AND:
                return self::evalBool($e['l'], $node, $position, $size) && self::evalBool($e['r'], $node, $position, $size);
            case self::E_OR:
                return self::evalBool($e['l'], $node, $position, $size) || self::evalBool($e['r'], $node, $position, $size);
            case self::E_COMPARE:
                return self::compare($e['cmp'], self::evalExpr($e['l'], $node, $position, $size), self::evalExpr($e['r'], $node, $position, $size));
            case self::E_ATTR_EQUALS:
                // '!=' is true when the attribute exists and differs (a missing attribute is an empty node-set)
                $raw = self::attributeOf($node, $e['name']);
                return $raw !== null && Attrs::attrEquals($raw, $e['value']) !== $e['negate'];
            case self::E_ATTR_EXISTS:
                return self::attributeOf($node, $e['name']) !== null;
            default:
                return self::toBoolean(self::evalExpr($e, $node, $position, $size));
        }
    }

    /** A number compares with the position, anything else is converted to boolean. */
    private static function evalPredicate(array $e, XNode $node, int $position, int $size): bool
    {
        if ($e['type'] === self::TYPE_BOOLEAN) {
            return self::evalBool($e, $node, $position, $size);
        }
        $v = self::evalExpr($e, $node, $position, $size);
        if (is_float($v)) {
            return $v == $position;
        }
        return self::toBoolean($v);
    }

    private static function evalExpr(array $e, XNode $node, int $position, int $size): mixed
    {
        switch ($e['op']) {
            case self::E_LITERAL:
            case self::E_NUMBER:
                return $e['value'];
            case self::E_AND:
            case self::E_OR:
            case self::E_COMPARE:
            case self::E_ATTR_EQUALS:
            case self::E_ATTR_EXISTS:
                return self::evalBool($e, $node, $position, $size);
            case self::E_ARITH:
                $x = self::evalNumber($e['l'], $node, $position, $size);
                $y = self::evalNumber($e['r'], $node, $position, $size);
                return match ($e['arith']) {
                    self::ARITH_ADD => $x + $y,
                    self::ARITH_SUB => $x - $y,
                    self::ARITH_MUL => $x * $y,
                    self::ARITH_DIV => fdiv($x, $y),
                    default => fmod($x, $y),
                };
            case self::E_NEGATE:
                return -self::evalNumber($e['e'], $node, $position, $size);
            case self::E_UNION:
                $a = self::evalNodes($e['l'], $node, $position, $size);
                $b = self::evalNodes($e['r'], $node, $position, $size);
                return self::sortUnique(array_merge($a, $b));
            case self::E_PATH:
                if ($e['start'] !== null) {
                    $current = self::evalNodes($e['start'], $node, $position, $size);
                } elseif ($e['absolute']) {
                    $current = [XNode::root(self::$doc)];
                } else {
                    $current = [$node];
                }
                foreach ($e['steps'] as $s) {
                    if ($current === []) {
                        break;
                    }
                    $current = self::applyStep($s, $current);
                }
                return $current;
            case self::E_FILTER:
                return self::filter(self::evalNodes($e['primary'], $node, $position, $size), $e['preds']);
            default:
                return self::evalFunction($e, $node, $position, $size);
        }
    }

    private static function compare(int $op, mixed $a, mixed $b): bool
    {
        if (is_array($a)) {
            if (is_array($b)) {
                if ($a === [] || $b === []) {
                    return false;
                }
                $valuesB = [];
                foreach ($b as $n) {
                    $valuesB[] = self::stringValue($n);
                }
                foreach ($a as $x) {
                    $value = self::stringValue($x);
                    foreach ($valuesB as $other) {
                        if (self::compareAtoms($op, $value, $other)) {
                            return true;
                        }
                    }
                }
                return false;
            }
            return self::compareWithNodes($op, $a, $b, true);
        }
        if (is_array($b)) {
            return self::compareWithNodes($op, $b, $a, false);
        }
        return self::compareAtoms($op, $a, $b);
    }

    private static function compareWithNodes(int $op, array $nodes, mixed $other, bool $nodesOnLeft): bool
    {
        if (is_bool($other)) {
            $asBool = $nodes !== [];
            return $nodesOnLeft ? self::compareAtoms($op, $asBool, $other) : self::compareAtoms($op, $other, $asBool);
        }
        $isNumber = is_float($other);
        foreach ($nodes as $n) {
            $s = self::stringValue($n);
            $value = $isNumber ? self::stringToNumber($s) : $s;
            if ($nodesOnLeft ? self::compareAtoms($op, $value, $other) : self::compareAtoms($op, $other, $value)) {
                return true;
            }
        }
        return false;
    }

    private static function compareAtoms(int $op, mixed $a, mixed $b): bool
    {
        if ($op === self::OP_EQ || $op === self::OP_NEQ) {
            if (is_bool($a) || is_bool($b)) {
                $eq = self::toBoolean($a) === self::toBoolean($b);
            } elseif (is_float($a) || is_float($b)) {
                $eq = self::toNumber($a) == self::toNumber($b);
            } else {
                $eq = self::toStr($a) === self::toStr($b);
            }
            return $eq === ($op === self::OP_EQ);
        }
        $x = self::toNumber($a);
        $y = self::toNumber($b);
        return match ($op) {
            self::OP_LT => $x < $y,
            self::OP_LE => $x <= $y,
            self::OP_GT => $x > $y,
            default => $x >= $y,
        };
    }

    // ----- steps -----

    private static function isReverse(int $axis): bool
    {
        return $axis === self::AXIS_ANCESTOR || $axis === self::AXIS_ANCESTOR_OR_SELF || $axis === self::AXIS_PRECEDING
            || $axis === self::AXIS_PRECEDING_SIBLING;
    }

    private static function applyStep(array $step, array $input): array
    {
        if (count($input) === 1) {
            $out = [];
            self::selectFrom($step, $input[0], $out);
            return $out;
        }

        // Nested input nodes (//div//a on divs inside divs, //a/ancestor::* on links in one list) give the same
        // nodes again and again: n nested nodes would collect about n² nodes before the duplicates are removed.
        // When the predicates do not depend on the position, a node passes them or not whichever input node it
        // comes from, so the input nodes whose results are already collected are skipped: the work stays linear.
        if (!$step['positional']) {
            switch ($step['axis']) {
                case self::AXIS_DESCENDANT:
                case self::AXIS_DESCENDANT_OR_SELF:
                    if (!self::matchesText($step['test']) && self::inDocumentOrder($input)) {
                        return self::sortUnique(self::selectDescendantsOfNested($step, $input));
                    }
                    break;
                case self::AXIS_ANCESTOR:
                case self::AXIS_ANCESTOR_OR_SELF:
                    return self::sortUnique(self::selectSharedAncestors($step, $input));
                default:
                    break;
            }
        }

        $out = [];
        foreach ($input as $x) {
            self::selectFrom($step, $x, $out);
        }
        return self::sortUnique($out);
    }

    /**
     * The descendant axes (tags only) on input nodes in document order. A tag that lies in the part of the tag
     * list already scanned for an earlier input node is skipped: that scan has tested it and every tag its own
     * scan would test.
     */
    private static function selectDescendantsOfNested(array $step, array $input): array
    {
        $ends = self::$doc->ends;
        $count = self::$doc->size;
        $out = [];
        $scannedTo = -1; // the tags before this index were scanned for an earlier input node
        foreach ($input as $x) {
            if ($x->kind === XNode::ELEMENT || $x->kind === XNode::COMMENT) {
                $t = $x->tag;
                if ($t->index < $scannedTo) {
                    continue;
                }
                self::selectFrom($step, $x, $out);
                // where addDescendants stops for this tag
                if ($x->kind === XNode::ELEMENT && $t->innerStart > 0 && $t->innerEnd > 0) {
                    $k = $t->index + 1;
                    while ($k < $count && $ends[$k] <= $t->end) {
                        $k++;
                    }
                    $scannedTo = max($scannedTo, $k);
                }
            } else {
                self::selectFrom($step, $x, $out);
                if ($x->kind === XNode::ROOT) {
                    $scannedTo = PHP_INT_MAX;
                }
            }
        }
        return $out;
    }

    /**
     * The walk up from an input node stops at an ancestor already reached from an earlier one, because that walk
     * has gone on to the root. Only the root and elements are marked: they are the only parents.
     */
    private static function selectSharedAncestors(array $step, array $input): array
    {
        $visited = [];
        $out = [];
        $test = $step['test'];
        foreach ($input as $x) {
            $start = count($out);
            $current = $x;
            if ($step['axis'] === self::AXIS_ANCESTOR_OR_SELF) {
                if (($current->kind === XNode::ROOT || $current->kind === XNode::ELEMENT) && isset($visited[$current->major])) {
                    continue;
                }
                if ($current->kind === XNode::ROOT || $current->kind === XNode::ELEMENT) {
                    $visited[$current->major] = true;
                }
                if (self::matches($test, $current, false)) {
                    $out[] = $current;
                }
            }
            for (;;) {
                $p = self::parentNode($current);
                if ($p === null || isset($visited[$p->major])) {
                    break;
                }
                $visited[$p->major] = true;
                $current = $p;
                if (self::matches($test, $current, false)) {
                    $out[] = $current;
                }
            }
            if ($step['preds'] !== [] && count($out) > $start) {
                $kept = self::filter(array_slice($out, $start), $step['preds']);
                array_splice($out, $start, count($out) - $start, $kept);
            }
        }
        return $out;
    }

    /** Appends the nodes of the axis that pass the test and the predicates, in document order. */
    private static function selectFrom(array $step, XNode $x, array &$out): void
    {
        $reverse = self::isReverse($step['axis']);
        if ($step['preds'] === [] && !$reverse) {
            self::collect($step, $x, $out);
            return;
        }
        $selected = [];
        self::collect($step, $x, $selected);
        $selected = self::filter($selected, $step['preds']);
        if ($reverse) {
            $selected = array_reverse($selected);
        }
        foreach ($selected as $n) {
            $out[] = $n;
        }
    }

    /** Appends the nodes of the axis that pass the node test, in axis order (reverse axes: nearest first). */
    private static function collect(array $step, XNode $x, array &$out): void
    {
        $test = $step['test'];
        switch ($step['axis']) {
            case self::AXIS_CHILD:
                self::addChildren($x, $test, $out);
                return;
            case self::AXIS_DESCENDANT:
                self::addDescendants($x, $test, $out);
                return;
            case self::AXIS_DESCENDANT_OR_SELF:
                if (self::matches($test, $x, false)) {
                    $out[] = $x;
                }
                self::addDescendants($x, $test, $out);
                return;
            case self::AXIS_SELF:
                if (self::matches($test, $x, false)) {
                    $out[] = $x;
                }
                return;
            case self::AXIS_PARENT:
                $p = self::parentNode($x);
                if ($p !== null && self::matches($test, $p, false)) {
                    $out[] = $p;
                }
                return;
            case self::AXIS_ANCESTOR:
            case self::AXIS_ANCESTOR_OR_SELF:
                $current = $x;
                if ($step['axis'] === self::AXIS_ANCESTOR_OR_SELF && self::matches($test, $current, false)) {
                    $out[] = $current;
                }
                while (($current = self::parentNode($current)) !== null) {
                    if (self::matches($test, $current, false)) {
                        $out[] = $current;
                    }
                }
                return;
            case self::AXIS_FOLLOWING_SIBLING:
            case self::AXIS_PRECEDING_SIBLING:
                self::collectSiblings($x, $test, $out, $step['axis'] === self::AXIS_FOLLOWING_SIBLING);
                return;
            case self::AXIS_FOLLOWING:
                self::collectFollowing($x, $test, $out);
                return;
            case self::AXIS_PRECEDING:
                self::collectPreceding($x, $test, $out);
                return;
            case self::AXIS_ATTRIBUTE:
                self::addAttributes($x, $test, $out);
                return;
            default:
                return;
        }
    }

    private static function inDocumentOrder(array $nodes): bool
    {
        $count = count($nodes);
        for ($i = 1; $i < $count; $i++) {
            if (self::compareKeys($nodes[$i - 1], $nodes[$i]) >= 0) {
                return false;
            }
        }
        return true;
    }

    private static function collectSiblings(XNode $x, array $test, array &$out, bool $following): void
    {
        if ($x->kind === XNode::ROOT || $x->kind === XNode::ATTRIBUTE) {
            return;
        }
        if (!self::matchesText($test) && $x->kind !== XNode::TEXT) {
            // tags only: walk the flat list; deeper tags are inside the siblings, a shallower one ends the parent
            $t = $x->tag;
            $d = $t->doc;
            $depths = $d->depths;
            $count = $d->size;
            $stepBy = $following ? 1 : -1;
            for ($i = $t->index + $stepBy; $i >= 0 && $i < $count; $i += $stepBy) {
                if ($depths[$i] < $t->depth) {
                    break;
                }
                if ($depths[$i] === $t->depth && self::matchesTagAt($test, $d, $i)) {
                    $out[] = XNode::ofTag($d->at($i));
                }
            }
            return;
        }
        $parent = self::parentNode($x);
        if ($parent === null) {
            return;
        }
        $siblings = [];
        self::addChildren($parent, self::ANY_NODE_TEST, $siblings);
        $index = -1;
        foreach ($siblings as $i => $s) {
            if (self::compareKeys($s, $x) === 0) {
                $index = $i;
                break;
            }
        }
        if ($index < 0) {
            return;
        }
        if ($following) {
            for ($i = $index + 1, $count = count($siblings); $i < $count; $i++) {
                if (self::matches($test, $siblings[$i], false)) {
                    $out[] = $siblings[$i];
                }
            }
        } else {
            for ($i = $index - 1; $i >= 0; $i--) {
                if (self::matches($test, $siblings[$i], false)) {
                    $out[] = $siblings[$i];
                }
            }
        }
    }

    private static function collectFollowing(XNode $x, array $test, array &$out): void
    {
        if ($x->kind === XNode::ROOT) {
            return;
        }
        $after = $x->rangeEnd();
        if (self::matchesText($test)) {
            foreach (self::allNodes() as $n) {
                if ($n->rangeStart() > $after && self::matches($test, $n, false)) {
                    $out[] = $n;
                }
            }
            return;
        }
        $d = self::$doc;
        $starts = $d->starts;
        $first = $x->kind === XNode::ELEMENT || $x->kind === XNode::COMMENT ? $x->tag->index + 1 : 0;
        for ($i = $first, $count = $d->size; $i < $count; $i++) {
            if ($starts[$i] > $after && self::matchesTagAt($test, $d, $i)) {
                $out[] = XNode::ofTag($d->at($i));
            }
        }
    }

    private static function collectPreceding(XNode $x, array $test, array &$out): void
    {
        if ($x->kind === XNode::ROOT) {
            return;
        }
        $before = $x->rangeStart();
        if (self::matchesText($test)) {
            $all = self::allNodes();
            for ($i = count($all) - 1; $i >= 0; $i--) {
                if ($all[$i]->rangeEnd() < $before && self::matches($test, $all[$i], false)) {
                    $out[] = $all[$i];
                }
            }
            return;
        }
        $d = self::$doc;
        $ends = $d->ends;
        $last = $x->kind === XNode::ELEMENT || $x->kind === XNode::COMMENT ? $x->tag->index - 1 : $d->size - 1;
        for ($i = $last; $i >= 0; $i--) {
            if ($ends[$i] < $before && self::matchesTagAt($test, $d, $i)) {
                $out[] = XNode::ofTag($d->at($i));
            }
        }
    }

    /** Applies the predicates one after another; positions are 1-based in the order of the list. */
    private static function filter(array $nodes, array $predicates): array
    {
        foreach ($predicates as $p) {
            $size = count($nodes);
            $kept = [];
            foreach ($nodes as $i => $n) {
                if (self::evalPredicate($p, $n, $i + 1, $size)) {
                    $kept[] = $n;
                }
            }
            $nodes = $kept;
        }
        return $nodes;
    }

    // ----- functions -----

    private static function round(float $v): float
    {
        if (is_nan($v) || is_infinite($v)) {
            return $v;
        }
        return floor($v + 0.5);
    }

    /** Splits on Unicode whitespace and joins with one space. */
    private static function normalizeSpace(string $s): string
    {
        $parts = preg_split('/' . Chars::UNICODE_SPACE . '+/', $s, -1, PREG_SPLIT_NO_EMPTY);
        return implode(' ', $parts);
    }

    private static function evalFunction(array $e, XNode $node, int $position, int $size): mixed
    {
        $args = $e['args'];
        $arg = static fn (int $i): string => self::evalString($args[$i], $node, $position, $size);
        $contextOrArg = static fn (): string => $args === [] ? self::stringValue($node) : $arg(0);
        switch ($e['fn']) {
            case 'last':
                return (float) $size;
            case 'position':
                return (float) $position;
            case 'count':
                return (float) count(self::evalNodes($args[0], $node, $position, $size));
            case 'name':
            case 'local-name':
                $name = '';
                if ($args === []) {
                    $name = self::nodeName($node);
                } else {
                    $set = self::evalNodes($args[0], $node, $position, $size);
                    if ($set !== []) {
                        $name = self::nodeName($set[0]);
                    }
                }
                if ($e['fn'] === 'local-name') {
                    $colon = strrpos($name, ':');
                    return $colon === false ? $name : substr($name, $colon + 1);
                }
                return $name;
            case 'string':
                return $contextOrArg();
            case 'concat':
                $s = '';
                foreach ($args as $a) {
                    $s .= self::evalString($a, $node, $position, $size);
                }
                return $s;
            case 'starts-with':
                return str_starts_with($arg(0), $arg(1));
            case 'ends-with':
                return str_ends_with($arg(0), $arg(1));
            case 'contains':
                return str_contains($arg(0), $arg(1));
            case 'substring-before':
                $s = $arg(0);
                $i = strpos($s, $arg(1));
                return $i === false ? '' : substr($s, 0, $i);
            case 'substring-after':
                $s = $arg(0);
                $t = $arg(1);
                $i = strpos($s, $t);
                return $i === false ? '' : substr($s, $i + strlen($t));
            case 'substring':
                return self::substring($e, $node, $position, $size);
            case 'string-length':
                return (float) Chars::utf16Length($contextOrArg()); // UTF-16 units, as .NET counts
            case 'normalize-space':
                return self::normalizeSpace($contextOrArg());
            case 'translate':
                $s = $arg(0);
                $from = Chars::chars($arg(1));
                $to = Chars::chars($arg(2));
                $map = [];
                foreach ($from as $k => $ch) {
                    $map[$ch] ??= $to[$k] ?? '';
                }
                if ($map === []) {
                    return $s;
                }
                $out = '';
                foreach (Chars::chars($s) as $ch) {
                    $out .= $map[$ch] ?? $ch;
                }
                return $out;
            case 'lower-case':
                return Chars::lower($arg(0));
            case 'upper-case':
                return Chars::upper($arg(0));
            case 'not':
                return !self::evalBool($args[0], $node, $position, $size);
            case 'true':
                return true;
            case 'false':
                return false;
            case 'boolean':
                return self::evalBool($args[0], $node, $position, $size);
            case 'number':
                return $args === [] ? self::stringToNumber(self::stringValue($node)) : self::evalNumber($args[0], $node, $position, $size);
            case 'sum':
                $sum = 0.0;
                foreach (self::evalNodes($args[0], $node, $position, $size) as $n) {
                    $sum += self::stringToNumber(self::stringValue($n));
                }
                return $sum;
            case 'floor':
                return floor(self::evalNumber($args[0], $node, $position, $size));
            case 'ceiling':
                return ceil(self::evalNumber($args[0], $node, $position, $size));
            case 'round':
                return self::round(self::evalNumber($args[0], $node, $position, $size));
            default:
                throw new \LogicException('unknown XPath function ' . $e['fn']);
        }
    }

    /** XPath 1.0: the chars at 1-based positions p with round(start) <= p < round(start) + round(length). */
    private static function substring(array $e, XNode $node, int $position, int $size): string
    {
        $args = $e['args'];
        $s = self::evalString($args[0], $node, $position, $size);
        $from = self::round(self::evalNumber($args[1], $node, $position, $size));
        $to = count($args) > 2 ? $from + self::round(self::evalNumber($args[2], $node, $position, $size)) : INF;
        if (is_nan($from) || is_nan($to)) {
            return '';
        }
        $ascii = Chars::isAscii($s);
        $chars = $ascii ? null : Chars::chars($s);
        $n = $ascii ? strlen($s) : count($chars);
        $first = $from <= 1 ? 0 : ($from > $n ? $n : (int) $from - 1);
        $end = $to >= $n + 1 ? $n : ($to <= 1 ? 0 : (int) $to - 1);
        if ($end <= $first) {
            return '';
        }
        return $ascii ? substr($s, $first, $end - $first) : implode('', array_slice($chars, $first, $end - $first));
    }
}
