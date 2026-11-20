<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

use Z3l\FastHtmlParser\XPathQuery as X;

/**
 * @internal The tokenizer and parser of XPath 1.0 expressions (section 3 of the spec). Positions in error messages are
 * in chars.
 */
final class XPathCompiler
{
    private const T_END = 0;
    private const T_SLASH = 1;
    private const T_DOUBLE_SLASH = 2;
    private const T_LPAREN = 3;
    private const T_RPAREN = 4;
    private const T_LBRACKET = 5;
    private const T_RBRACKET = 6;
    private const T_DOT = 7;
    private const T_DOT_DOT = 8;
    private const T_AT = 9;
    private const T_COMMA = 10;
    private const T_COLON_COLON = 11;
    private const T_PIPE = 12;
    private const T_PLUS = 13;
    private const T_MINUS = 14;
    private const T_EQ = 15;
    private const T_NEQ = 16;
    private const T_LT = 17;
    private const T_LE = 18;
    private const T_GT = 19;
    private const T_GE = 20;
    private const T_STAR = 21; // name test *
    private const T_MULTIPLY = 22; // operator *
    private const T_AND = 23;
    private const T_OR = 24;
    private const T_DIV = 25;
    private const T_MOD = 26;
    private const T_NAME = 27;
    private const T_LITERAL = 28;
    private const T_NUMBER = 29;
    private const T_DOLLAR = 30;

    private const SINGLE = ['(' => self::T_LPAREN, ')' => self::T_RPAREN, '[' => self::T_LBRACKET, ']' => self::T_RBRACKET,
        '@' => self::T_AT, ',' => self::T_COMMA, '|' => self::T_PIPE, '+' => self::T_PLUS, '-' => self::T_MINUS,
        '=' => self::T_EQ, '$' => self::T_DOLLAR];

    private const NODE_TYPES = ['node' => 1, 'text' => 1, 'comment' => 1, 'processing-instruction' => 1];
    private const OPERATORS = ['and' => self::T_AND, 'or' => self::T_OR, 'div' => self::T_DIV, 'mod' => self::T_MOD];
    private const AXES = [
        'child' => X::AXIS_CHILD,
        'descendant' => X::AXIS_DESCENDANT,
        'descendant-or-self' => X::AXIS_DESCENDANT_OR_SELF,
        'self' => X::AXIS_SELF,
        'parent' => X::AXIS_PARENT,
        'ancestor' => X::AXIS_ANCESTOR,
        'ancestor-or-self' => X::AXIS_ANCESTOR_OR_SELF,
        'following-sibling' => X::AXIS_FOLLOWING_SIBLING,
        'preceding-sibling' => X::AXIS_PRECEDING_SIBLING,
        'following' => X::AXIS_FOLLOWING,
        'preceding' => X::AXIS_PRECEDING,
        'attribute' => X::AXIS_ATTRIBUTE,
    ];

    /** name => [min args, max args, type] */
    private const FUNCTIONS = [
        'last' => [0, 0, X::TYPE_NUMBER],
        'position' => [0, 0, X::TYPE_NUMBER],
        'count' => [1, 1, X::TYPE_NUMBER],
        'name' => [0, 1, X::TYPE_STRING],
        'local-name' => [0, 1, X::TYPE_STRING],
        'string' => [0, 1, X::TYPE_STRING],
        'concat' => [2, PHP_INT_MAX, X::TYPE_STRING],
        'starts-with' => [2, 2, X::TYPE_BOOLEAN],
        'ends-with' => [2, 2, X::TYPE_BOOLEAN],
        'contains' => [2, 2, X::TYPE_BOOLEAN],
        'substring-before' => [2, 2, X::TYPE_STRING],
        'substring-after' => [2, 2, X::TYPE_STRING],
        'substring' => [2, 3, X::TYPE_STRING],
        'string-length' => [0, 1, X::TYPE_NUMBER],
        'normalize-space' => [0, 1, X::TYPE_STRING],
        'translate' => [3, 3, X::TYPE_STRING],
        'lower-case' => [1, 1, X::TYPE_STRING],
        'upper-case' => [1, 1, X::TYPE_STRING],
        'not' => [1, 1, X::TYPE_BOOLEAN],
        'true' => [0, 0, X::TYPE_BOOLEAN],
        'false' => [0, 0, X::TYPE_BOOLEAN],
        'boolean' => [1, 1, X::TYPE_BOOLEAN],
        'number' => [0, 1, X::TYPE_NUMBER],
        'sum' => [1, 1, X::TYPE_NUMBER],
        'floor' => [1, 1, X::TYPE_NUMBER],
        'ceiling' => [1, 1, X::TYPE_NUMBER],
        'round' => [1, 1, X::TYPE_NUMBER],
    ];

    /** @var list<string> the chars of the expression */
    private array $source;
    /** @var list<array{k: int, t: string, n: float, p: int}> */
    private array $tokens = [];
    private int $index = 0;

    public function __construct(private readonly string $expression)
    {
        if (!preg_match('//u', $expression)) {
            throw new XPathException('invalid XPath ' . Chars::quote($expression) . ': not UTF-8');
        }
        $this->source = Chars::chars($expression);
        $this->tokenize();
    }

    /** The compiled expression. */
    public function parse(): array
    {
        $expr = $this->parseExpr();
        if ($this->peek(0)['k'] !== self::T_END) {
            $this->fail('unexpected ' . $this->describe($this->peek(0)));
        }
        return $expr;
    }

    private function failAt(string $msg, int $position): never
    {
        throw new XPathException('invalid XPath ' . Chars::quote($this->expression) . " at position $position: $msg");
    }

    private function fail(string $msg): never
    {
        $this->failAt($msg, $this->peek(0)['p']);
    }

    private function text(int $from, int $to): string
    {
        return implode('', array_slice($this->source, $from, $to - $from));
    }

    private static function isLetter(string $ch): bool
    {
        if (strlen($ch) === 1) {
            return ctype_alpha($ch);
        }
        return preg_match('/^\p{L}$/u', $ch) === 1;
    }

    private static function isXNameStart(string $ch): bool
    {
        return $ch === '_' || self::isLetter($ch);
    }

    private static function isXNameChar(string $ch): bool
    {
        if (strlen($ch) === 1) {
            return ctype_alnum($ch) || $ch === '_' || $ch === '-' || $ch === '.';
        }
        return preg_match('/^[\p{L}\p{Nd}]$/u', $ch) === 1;
    }

    private static function isDigit(string $ch): bool
    {
        return strlen($ch) === 1 && ctype_digit($ch);
    }

    private static function isSpace(string $ch): bool
    {
        return Chars::spaceAt($ch, 0, strlen($ch)) === strlen($ch);
    }

    private function tokenize(): void
    {
        $s = $this->source;
        $n = count($s);
        $i = 0;
        for (;;) {
            while ($i < $n && self::isSpace($s[$i])) {
                $i++;
            }
            if ($i >= $n) {
                $this->tokens[] = ['k' => self::T_END, 't' => '', 'n' => 0.0, 'p' => $i];
                return;
            }
            $start = $i;
            $ch = $s[$i];
            $next = $s[$i + 1] ?? '';

            // XPath 1.0 lexical rule: after a token that can end an operand, '*' and and/or/div/mod are operators
            $operatorExpected = false;
            if ($this->tokens !== []) {
                $operatorExpected = match ($this->tokens[count($this->tokens) - 1]['k']) {
                    self::T_RPAREN, self::T_RBRACKET, self::T_DOT, self::T_DOT_DOT, self::T_STAR, self::T_NAME,
                    self::T_LITERAL, self::T_NUMBER => true,
                    default => false,
                };
            }

            $kind = self::T_END;
            $text = '';
            $number = 0.0;
            if ($ch === '/') {
                if ($next === '/') {
                    $kind = self::T_DOUBLE_SLASH;
                    $i += 2;
                } else {
                    $kind = self::T_SLASH;
                    $i++;
                }
            } elseif (isset(self::SINGLE[$ch])) {
                $kind = self::SINGLE[$ch];
                $i++;
            } elseif ($ch === '*') {
                $kind = $operatorExpected ? self::T_MULTIPLY : self::T_STAR;
                $i++;
            } elseif ($ch === '!') {
                if ($next !== '=') {
                    $this->failAt("'!' must be followed by '='", $i);
                }
                $kind = self::T_NEQ;
                $i += 2;
            } elseif ($ch === '<' || $ch === '>') {
                if ($next === '=') {
                    $kind = $ch === '<' ? self::T_LE : self::T_GE;
                    $i += 2;
                } else {
                    $kind = $ch === '<' ? self::T_LT : self::T_GT;
                    $i++;
                }
            } elseif ($ch === ':') {
                if ($next !== ':') {
                    $this->failAt("unexpected ':'", $i);
                }
                $kind = self::T_COLON_COLON;
                $i += 2;
            } elseif ($ch === '"' || $ch === "'") {
                $end = -1;
                for ($k = $i + 1; $k < $n; $k++) {
                    if ($s[$k] === $ch) {
                        $end = $k;
                        break;
                    }
                }
                if ($end < 0) {
                    $this->failAt('unterminated string literal', $i);
                }
                $kind = self::T_LITERAL;
                $text = $this->text($i + 1, $end);
                $i = $end + 1;
            } elseif ($ch === '.' && $next === '.') {
                $kind = self::T_DOT_DOT;
                $i += 2;
            } elseif ($ch === '.' && !self::isDigit($next)) {
                $kind = self::T_DOT;
                $i++;
            } elseif (self::isDigit($ch) || $ch === '.') {
                while ($i < $n && self::isDigit($s[$i])) {
                    $i++;
                }
                if ($i < $n && $s[$i] === '.') {
                    $i++;
                    while ($i < $n && self::isDigit($s[$i])) {
                        $i++;
                    }
                }
                $kind = self::T_NUMBER;
                $number = (float) $this->text($start, $i);
            } elseif (self::isXNameStart($ch)) {
                $i++;
                while ($i < $n) {
                    if (self::isXNameChar($s[$i])) {
                        $i++;
                    } elseif ($s[$i] === ':' && $i + 1 < $n && $s[$i + 1] !== ':' && (self::isXNameStart($s[$i + 1]) || $s[$i + 1] === '*')) {
                        // a prefixed name (dc:creator) or prefix:*
                        $i += 2;
                        if ($s[$i - 1] === '*') {
                            break;
                        }
                    } else {
                        break;
                    }
                }
                $kind = self::T_NAME;
                $text = $this->text($start, $i);
                if ($operatorExpected) {
                    $op = self::OPERATORS[$text] ?? null;
                    if ($op === null) {
                        $this->failAt("expected an operator, found '$text'", $start);
                    }
                    $kind = $op;
                }
            } else {
                $this->failAt("unexpected character '$ch'", $i);
            }
            $this->tokens[] = ['k' => $kind, 't' => $text, 'n' => $number, 'p' => $start];
        }
    }

    // ----- parser helpers -----

    private function peek(int $offset): array
    {
        return $this->tokens[min($this->index + $offset, count($this->tokens) - 1)];
    }

    private function advance(): array
    {
        return $this->tokens[$this->index++];
    }

    private function accept(int $kind): bool
    {
        if ($this->peek(0)['k'] !== $kind) {
            return false;
        }
        $this->index++;
        return true;
    }

    private function expect(int $kind, string $what): void
    {
        if (!$this->accept($kind)) {
            $this->fail("expected $what, found " . $this->describe($this->peek(0)));
        }
    }

    private function describe(array $t): string
    {
        switch ($t['k']) {
            case self::T_END:
                return 'the end of the expression';
            case self::T_NAME:
            case self::T_LITERAL:
                return "'" . $t['t'] . "'";
            case self::T_NUMBER:
                return X::numberToString($t['n']);
            default:
                $to = min(count($this->source), $t['p'] + 2);
                return "'" . rtrim($this->text($t['p'], $to)) . "'";
        }
    }

    // ----- expressions -----

    private static function expr(int $op, int $type, bool $pos, array $fields): array
    {
        return ['op' => $op, 'type' => $type, 'pos' => $pos] + $fields;
    }

    private static function isPositional(array $e): bool
    {
        return $e['type'] === X::TYPE_NUMBER || $e['pos'];
    }

    private static function step(int $axis, array $test, array $preds = []): array
    {
        $positional = false;
        foreach ($preds as $p) {
            if (self::isPositional($p)) {
                $positional = true;
                break;
            }
        }
        return ['axis' => $axis, 'test' => $test, 'preds' => $preds, 'positional' => $positional];
    }

    private static function nodeTest(int $kind, string $name = ''): array
    {
        return ['k' => $kind, 'name' => $name, 'q' => $kind === X::TK_NAME ? Query::by($name) : null];
    }

    /** Whether the expression is a bare @name (one attribute step, no predicates); its name, or null. */
    private static function attributeName(array $e): ?string
    {
        if ($e['op'] !== X::E_PATH || $e['start'] !== null || $e['absolute'] || count($e['steps']) !== 1) {
            return null;
        }
        $s = $e['steps'][0];
        if ($s['axis'] !== X::AXIS_ATTRIBUTE || $s['preds'] !== [] || $s['test']['k'] !== X::TK_NAME) {
            return null;
        }
        return $s['test']['name'];
    }

    /** @name = 'literal' is compared in place; everything else by the general rules. */
    private static function makeEquality(int $op, array $left, array $right): array
    {
        $l = self::attributeName($left);
        if ($l !== null && $right['op'] === X::E_LITERAL) {
            return self::expr(X::E_ATTR_EQUALS, X::TYPE_BOOLEAN, false, ['name' => $l, 'value' => $right['value'], 'negate' => $op === X::OP_NEQ]);
        }
        $r = self::attributeName($right);
        if ($r !== null && $left['op'] === X::E_LITERAL) {
            return self::expr(X::E_ATTR_EQUALS, X::TYPE_BOOLEAN, false, ['name' => $r, 'value' => $left['value'], 'negate' => $op === X::OP_NEQ]);
        }
        return self::compareExpr($op, $left, $right);
    }

    private static function compareExpr(int $op, array $left, array $right): array
    {
        return self::expr(X::E_COMPARE, X::TYPE_BOOLEAN, $left['pos'] || $right['pos'], ['cmp' => $op, 'l' => $left, 'r' => $right]);
    }

    private static function arith(int $op, array $left, array $right): array
    {
        return self::expr(X::E_ARITH, X::TYPE_NUMBER, $left['pos'] || $right['pos'], ['arith' => $op, 'l' => $left, 'r' => $right]);
    }

    /**
     * //x is descendant-or-self::node()/child::x. Without positional predicates on x it is the same as descendant::x:
     * one linear scan of the tag list instead of the children of every node.
     */
    private static function optimize(array $steps): array
    {
        for ($i = 0; $i < count($steps) - 1; $i++) {
            $s = $steps[$i];
            if ($s['axis'] !== X::AXIS_DESCENDANT_OR_SELF || $s['test'] !== X::ANY_NODE_TEST || $s['preds'] !== []) {
                continue;
            }
            $next = $steps[$i + 1];
            if ($next['axis'] === X::AXIS_CHILD && !$next['positional']) {
                $steps[$i] = self::step(X::AXIS_DESCENDANT, $next['test'], $next['preds']);
                array_splice($steps, $i + 1, 1);
            } elseif ($next['axis'] === X::AXIS_CHILD || $next['axis'] === X::AXIS_ATTRIBUTE) {
                // only the root and elements have children or attributes: skip the text nodes
                $steps[$i] = self::step(X::AXIS_DESCENDANT_OR_SELF, X::CONTAINERS_TEST);
            }
        }
        return $steps;
    }

    private static function canStartStep(array $t): bool
    {
        $k = $t['k'];
        return $k === self::T_DOT || $k === self::T_DOT_DOT || $k === self::T_AT || $k === self::T_STAR || $k === self::T_NAME;
    }

    // ----- grammar (XPath 1.0, section 3) -----

    private function parseExpr(): array
    {
        return $this->parseOr();
    }

    private function parseOr(): array
    {
        $left = $this->parseAnd();
        while ($this->accept(self::T_OR)) {
            $right = $this->parseAnd();
            $left = self::expr(X::E_OR, X::TYPE_BOOLEAN, $left['pos'] || $right['pos'], ['l' => $left, 'r' => $right]);
        }
        return $left;
    }

    private function parseAnd(): array
    {
        $left = $this->parseEquality();
        while ($this->accept(self::T_AND)) {
            $right = $this->parseEquality();
            $left = self::expr(X::E_AND, X::TYPE_BOOLEAN, $left['pos'] || $right['pos'], ['l' => $left, 'r' => $right]);
        }
        return $left;
    }

    private function parseEquality(): array
    {
        $left = $this->parseRelational();
        for (;;) {
            if ($this->accept(self::T_EQ)) {
                $op = X::OP_EQ;
            } elseif ($this->accept(self::T_NEQ)) {
                $op = X::OP_NEQ;
            } else {
                return $left;
            }
            $left = self::makeEquality($op, $left, $this->parseRelational());
        }
    }

    private function parseRelational(): array
    {
        $left = $this->parseAdditive();
        for (;;) {
            if ($this->accept(self::T_LT)) {
                $op = X::OP_LT;
            } elseif ($this->accept(self::T_LE)) {
                $op = X::OP_LE;
            } elseif ($this->accept(self::T_GT)) {
                $op = X::OP_GT;
            } elseif ($this->accept(self::T_GE)) {
                $op = X::OP_GE;
            } else {
                return $left;
            }
            $left = self::compareExpr($op, $left, $this->parseAdditive());
        }
    }

    private function parseAdditive(): array
    {
        $left = $this->parseMultiplicative();
        for (;;) {
            if ($this->accept(self::T_PLUS)) {
                $left = self::arith(X::ARITH_ADD, $left, $this->parseMultiplicative());
            } elseif ($this->accept(self::T_MINUS)) {
                $left = self::arith(X::ARITH_SUB, $left, $this->parseMultiplicative());
            } else {
                return $left;
            }
        }
    }

    private function parseMultiplicative(): array
    {
        $left = $this->parseUnary();
        for (;;) {
            if ($this->accept(self::T_MULTIPLY)) {
                $left = self::arith(X::ARITH_MUL, $left, $this->parseUnary());
            } elseif ($this->accept(self::T_DIV)) {
                $left = self::arith(X::ARITH_DIV, $left, $this->parseUnary());
            } elseif ($this->accept(self::T_MOD)) {
                $left = self::arith(X::ARITH_MOD, $left, $this->parseUnary());
            } else {
                return $left;
            }
        }
    }

    private function parseUnary(): array
    {
        if ($this->accept(self::T_MINUS)) {
            $operand = $this->parseUnary();
            return self::expr(X::E_NEGATE, X::TYPE_NUMBER, $operand['pos'], ['e' => $operand]);
        }
        return $this->parseUnion();
    }

    private function parseUnion(): array
    {
        $left = $this->parsePath();
        while ($this->peek(0)['k'] === self::T_PIPE) {
            $position = $this->advance()['p'];
            $right = $this->parsePath();
            if ($left['type'] !== X::TYPE_NODE_SET || $right['type'] !== X::TYPE_NODE_SET) {
                $this->failAt("'|' needs node-sets on both sides", $position);
            }
            $left = self::expr(X::E_UNION, X::TYPE_NODE_SET, $left['pos'] || $right['pos'], ['l' => $left, 'r' => $right]);
        }
        return $left;
    }

    private function parsePath(): array
    {
        $t = $this->peek(0);
        $isFilter = $t['k'] === self::T_LITERAL || $t['k'] === self::T_NUMBER || $t['k'] === self::T_LPAREN || $t['k'] === self::T_DOLLAR
            || ($t['k'] === self::T_NAME && $this->peek(1)['k'] === self::T_LPAREN && !isset(self::NODE_TYPES[$t['t']]));
        if (!$isFilter) {
            return $this->parseLocationPath();
        }

        $position = $t['p'];
        $primary = $this->parsePrimary();
        $predicates = $this->parsePredicates();
        if ($predicates !== []) {
            if ($primary['type'] !== X::TYPE_NODE_SET) {
                $this->failAt('a predicate needs a node-set', $position);
            }
            $primary = self::expr(X::E_FILTER, X::TYPE_NODE_SET, $primary['pos'], ['primary' => $primary, 'preds' => $predicates]);
        }
        $k = $this->peek(0)['k'];
        if ($k !== self::T_SLASH && $k !== self::T_DOUBLE_SLASH) {
            return $primary;
        }
        if ($primary['type'] !== X::TYPE_NODE_SET) {
            $this->failAt("'/' needs a node-set on the left", $position);
        }
        $steps = self::optimize($this->parseRelativeSteps([], true));
        return self::expr(X::E_PATH, X::TYPE_NODE_SET, $primary['pos'], ['start' => $primary, 'absolute' => false, 'steps' => $steps]);
    }

    private function parseLocationPath(): array
    {
        $steps = [];
        $absolute = false;
        if ($this->accept(self::T_SLASH)) {
            $absolute = true;
            if (self::canStartStep($this->peek(0))) {
                $steps = $this->parseRelativeSteps($steps, false);
            }
        } elseif ($this->peek(0)['k'] === self::T_DOUBLE_SLASH) {
            $absolute = true;
            $steps = $this->parseRelativeSteps($steps, true);
        } else {
            if (!self::canStartStep($this->peek(0))) {
                $this->fail('unexpected ' . $this->describe($this->peek(0)));
            }
            $steps = $this->parseRelativeSteps($steps, false);
        }
        $path = self::expr(X::E_PATH, X::TYPE_NODE_SET, false, ['start' => null, 'absolute' => $absolute, 'steps' => self::optimize($steps)]);
        $name = self::attributeName($path);
        if ($name !== null) {
            $path['attr'] = $name; // read in place where a string is needed
        }
        return $path;
    }

    /** Steps separated by '/' or '//'; $afterSlash: the next token is a '/' or '//' to read first. */
    private function parseRelativeSteps(array $steps, bool $afterSlash): array
    {
        if (!$afterSlash) {
            $steps[] = $this->parseStep();
        }
        for (;;) {
            if ($this->accept(self::T_SLASH)) {
                $steps[] = $this->parseStep();
            } elseif ($this->accept(self::T_DOUBLE_SLASH)) {
                $steps[] = self::step(X::AXIS_DESCENDANT_OR_SELF, X::ANY_NODE_TEST);
                $steps[] = $this->parseStep();
            } else {
                return $steps;
            }
        }
    }

    private function parseStep(): array
    {
        if ($this->accept(self::T_DOT)) {
            return self::step(X::AXIS_SELF, X::ANY_NODE_TEST);
        }
        if ($this->accept(self::T_DOT_DOT)) {
            return self::step(X::AXIS_PARENT, X::ANY_NODE_TEST);
        }
        $axis = X::AXIS_CHILD;
        if ($this->accept(self::T_AT)) {
            $axis = X::AXIS_ATTRIBUTE;
        } elseif ($this->peek(0)['k'] === self::T_NAME && $this->peek(1)['k'] === self::T_COLON_COLON) {
            $name = $this->advance();
            $a = self::AXES[$name['t']] ?? null;
            if ($a === null) {
                if ($name['t'] === 'namespace') {
                    $this->failAt('the namespace axis is not supported', $name['p']);
                }
                $this->failAt("unknown axis '{$name['t']}'", $name['p']);
            }
            $axis = $a;
            $this->advance();
        }
        $test = $this->parseNodeTest();
        return self::step($axis, $test, $this->parsePredicates());
    }

    private function parseNodeTest(): array
    {
        if ($this->accept(self::T_STAR)) {
            return self::nodeTest(X::TK_WILDCARD);
        }
        $t = $this->peek(0);
        if ($t['k'] !== self::T_NAME) {
            $this->fail('expected a node test, found ' . $this->describe($t));
        }
        $this->advance();
        $name = $t['t'];
        if ($this->peek(0)['k'] === self::T_LPAREN && isset(self::NODE_TYPES[$name])) {
            $this->advance();
            if ($name === 'processing-instruction') {
                $this->accept(self::T_LITERAL);
            }
            $this->expect(self::T_RPAREN, "')'");
            return match ($name) {
                'node' => X::ANY_NODE_TEST,
                'text' => self::nodeTest(X::TK_TEXT),
                'comment' => self::nodeTest(X::TK_COMMENT),
                default => self::nodeTest(X::TK_PI),
            };
        }
        if (str_ends_with($name, ':*')) {
            return self::nodeTest(X::TK_PREFIX, substr($name, 0, -1));
        }
        return self::nodeTest(X::TK_NAME, $name);
    }

    private function parsePredicates(): array
    {
        $predicates = [];
        while ($this->accept(self::T_LBRACKET)) {
            $p = $this->parseExpr();
            $this->expect(self::T_RBRACKET, "']'");
            // [@name]: an existence check instead of a node-set
            $name = self::attributeName($p);
            if ($name !== null) {
                $p = self::expr(X::E_ATTR_EXISTS, X::TYPE_BOOLEAN, false, ['name' => $name]);
            }
            $predicates[] = $p;
        }
        return $predicates;
    }

    private function parsePrimary(): array
    {
        $t = $this->advance();
        switch ($t['k']) {
            case self::T_LITERAL:
                return self::expr(X::E_LITERAL, X::TYPE_STRING, false, ['value' => $t['t']]);
            case self::T_NUMBER:
                return self::expr(X::E_NUMBER, X::TYPE_NUMBER, false, ['value' => $t['n']]);
            case self::T_LPAREN:
                $e = $this->parseExpr();
                $this->expect(self::T_RPAREN, "')'");
                return $e;
            case self::T_DOLLAR:
                $this->failAt('variables are not supported', $t['p']);
            default:
                break;
        }

        // function call
        $info = self::FUNCTIONS[$t['t']] ?? null;
        if ($info === null) {
            $this->failAt("unknown function '{$t['t']}()'", $t['p']);
        }
        $this->expect(self::T_LPAREN, "'('");
        $args = [];
        if (!$this->accept(self::T_RPAREN)) {
            do {
                $args[] = $this->parseExpr();
            } while ($this->accept(self::T_COMMA));
            $this->expect(self::T_RPAREN, "')'");
        }
        if (count($args) < $info[0] || count($args) > $info[1]) {
            $this->failAt("wrong number of arguments for {$t['t']}()", $t['p']);
        }
        $fn = $t['t'];
        $needsNodeSet = $fn === 'count' || $fn === 'sum' || $fn === 'name' || $fn === 'local-name';
        if ($needsNodeSet && $args !== [] && $args[0]['type'] !== X::TYPE_NODE_SET) {
            $this->failAt("$fn() needs a node-set argument", $t['p']);
        }
        $pos = $fn === 'position' || $fn === 'last';
        foreach ($args as $a) {
            $pos = $pos || $a['pos'];
        }
        return self::attributeTest($fn, $args) ?? self::expr(X::E_FUNCTION, $info[2], $pos, ['fn' => $fn, 'args' => $args]);
    }

    /**
     * contains(@a, 'x'), starts-with(...), ends-with(...), also with the attribute wrapped in literals
     * (contains(concat(' ', @class, ' '), ' x '), the usual class test), are evaluated on the attribute in place,
     * without a node-set for every element. Null: a plain function call.
     */
    private static function attributeTest(string $fn, array $args): ?array
    {
        if (($fn !== 'contains' && $fn !== 'starts-with' && $fn !== 'ends-with') || $args[1]['op'] !== X::E_LITERAL) {
            return null;
        }
        $arg = $args[0];
        $name = self::attributeName($arg);
        $prefix = $suffix = '';
        if ($name === null) {
            if ($arg['op'] !== X::E_FUNCTION || $arg['fn'] !== 'concat' || count($arg['args']) !== 3
                || $arg['args'][0]['op'] !== X::E_LITERAL || $arg['args'][2]['op'] !== X::E_LITERAL) {
                return null;
            }
            $name = self::attributeName($arg['args'][1]);
            if ($name === null) {
                return null;
            }
            $prefix = $arg['args'][0]['value'];
            $suffix = $arg['args'][2]['value'];
        }
        return self::expr(X::E_ATTR_TEST, X::TYPE_BOOLEAN, false,
            ['fn' => $fn, 'prefix' => $prefix, 'name' => $name, 'suffix' => $suffix, 'value' => $args[1]['value']]);
    }
}
