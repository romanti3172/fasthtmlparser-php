<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * @internal A page is scanned once in fast mode: tags are matched to their end tags and nothing else. Well-formed
 * markup needs nothing more. Where the HTML5 optional-end-tag rules matter (<li>one<li>two</ul>), tags are left open
 * inside an element: when its end tag (or the end of the document) finds them on the stack and one of them is a rule
 * target (li, p, td, tr, option, ...), only that part is scanned again in rules mode, from the outermost such tag up to
 * that end tag. Everything before it was parsed right and is kept.
 *
 * Port of ParsingCore.cs from z3l.FastHtmlParser; positions are byte offsets into the UTF-8 source.
 */
final class Parser
{
    /** Nesting deeper than this is flattened, as browsers do (Blink: 512). */
    private const MAX_DEPTH = 512;
    /** The parts scanned again with the optional-end-tag rules may add up to this many times the source length. */
    private const REPAIR_BUDGET_FACTOR = 8;

    public const COMMENT = TagNames::KEYS['!--'];
    private const P = TagNames::KEYS['p'];

    /** "</script", "</style", ... for the elements whose content is raw text (HTML5 RAWTEXT / RCDATA). */
    public const RAW_TEXT_ENDS = [
        TagNames::KEYS['script'] => '</script',
        TagNames::KEYS['style'] => '</style',
        TagNames::KEYS['textarea'] => '</textarea',
        TagNames::KEYS['title'] => '</title',
        TagNames::KEYS['xmp'] => '</xmp',
        TagNames::KEYS['iframe'] => '</iframe',
        TagNames::KEYS['noembed'] => '</noembed',
        TagNames::KEYS['noframes'] => '</noframes',
    ];

    // HTML5 void elements (incl. obsolete ones the parsing rules also treat as void) and declarations.
    private const VOID_NAMES = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source',
        'track', 'wbr', 'basefont', 'bgsound', 'frame', 'keygen', 'param', 'isindex', '!doctype', '?xml'];

    // Elements that limit the scope of the rules (HTML5 "has an element in scope").
    private const SCOPE_STOPS = ['html', 'body', 'table', 'caption', 'td', 'th', 'template', 'object', 'applet',
        'marquee', 'button'];

    // <p> is ended by the start of a block element (HTML5 "close a p element").
    private const CLOSES_P_NAMES = ['address', 'article', 'aside', 'blockquote', 'center', 'details', 'dialog', 'dir',
        'div', 'dl', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'header', 'hgroup', 'hr', 'listing', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'search', 'section', 'summary',
        'table', 'ul', 'li', 'dd', 'dt', 'plaintext', 'xmp'];

    /** @var array<int, true> */
    private static array $void;
    /** @var array<int, true> */
    private static array $closesP;
    /** @var array{t: array<int, true>, s: array<int, true>, k: list<int>} */
    private static array $pRule;
    /** @var array<int, array{t: array<int, true>, s: array<int, true>, k: list<int>}> */
    private static array $rules;
    /** @var array<int, true> */
    private static array $isRuleTarget;
    private static bool $ready = false;

    private static function init(): void
    {
        $keys = static fn (array $names): array => array_map(static fn (string $n): int => TagNames::KEYS[$n], $names);
        $set = static fn (array $names): array => array_fill_keys($keys($names), true);
        $rule = static function (array $targets, array ...$stops) use ($keys, $set): array {
            $s = [];
            foreach ($stops as $list) {
                $s += $set($list);
            }
            return ['t' => $set($targets), 's' => $s, 'k' => $keys($targets)];
        };
        self::$void = $set(self::VOID_NAMES);
        self::$closesP = $set(self::CLOSES_P_NAMES);
        self::$pRule = $rule(['p'], self::SCOPE_STOPS);

        $r = [];
        $r[TagNames::KEYS['li']] = $rule(['li'], self::SCOPE_STOPS, ['ul', 'ol', 'menu']);
        $r[TagNames::KEYS['dt']] = $r[TagNames::KEYS['dd']] = $rule(['dt', 'dd'], self::SCOPE_STOPS, ['dl']);
        $tableStops = ['html', 'body', 'template', 'table'];
        $r[TagNames::KEYS['td']] = $r[TagNames::KEYS['th']] = $rule(['td', 'th'], $tableStops, ['tr', 'tbody', 'thead', 'tfoot']);
        $r[TagNames::KEYS['tr']] = $rule(['tr', 'td', 'th'], $tableStops, ['tbody', 'thead', 'tfoot']);
        $section = $rule(['tbody', 'thead', 'tfoot', 'tr', 'td', 'th', 'caption'], $tableStops);
        $r[TagNames::KEYS['tbody']] = $r[TagNames::KEYS['thead']] = $r[TagNames::KEYS['tfoot']] = $section;
        $selectStops = ['html', 'body', 'template', 'select', 'datalist'];
        $r[TagNames::KEYS['option']] = $rule(['option'], $selectStops, ['optgroup']);
        $r[TagNames::KEYS['optgroup']] = $rule(['option', 'optgroup'], $selectStops);
        self::$rules = $r;
        $targets = [];
        foreach ($r as $x) {
            foreach ($x['k'] as $k) {
                $targets[$k] = true;
            }
        }
        self::$isRuleTarget = $targets;
        self::$ready = true;
    }

    /** Finds the tag name $s[$from, $to) case-insensitively (ASCII); -1 for a name that is not a known tag. */
    public static function lookupTagAt(string $s, int $from, int $to): int
    {
        $length = $to - $from;
        if ($length <= 0 || $length > 32) {
            return -1;
        }
        return TagNames::KEYS[strtr(substr($s, $from, $length), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')] ?? -1;
    }

    /** Finds a tag name case-insensitively (ASCII); -1 for a name that is not a known tag. */
    public static function lookupTag(string $name): int
    {
        if ($name === '' || strlen($name) > 32) {
            return -1;
        }
        return TagNames::KEYS[strtr($name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')] ?? -1;
    }

    /** Whether the content of the tag is raw text (script, style, textarea, title, ...). */
    public static function isRawText(int $key): bool
    {
        return isset(self::RAW_TEXT_ENDS[$key]);
    }

    /** As in HTML5, '<' starts markup only before an ASCII letter, '/', '!' or '?'. */
    public static function isMarkupStart(string $s, int $i, int $limit): bool
    {
        if ($i + 1 >= $limit) {
            return false;
        }
        $c = $s[$i + 1];
        return ctype_alpha($c) || $c === '/' || $c === '!' || $c === '?';
    }

    /** The position of the '<' of the end tag (e.g. "</script") in $s[$from, $limit), or $limit if there is none. */
    public static function findRawTextEnd(string $s, int $from, string $endTag, int $limit): int
    {
        $n = strlen($endTag);
        while ($from < $limit) {
            $pos = stripos($s, $endTag, $from);
            if ($pos === false || $pos + $n > $limit) {
                break;
            }
            $after = $pos + $n;
            // "</script>", "</script >", "</script/>" end it; "</scripts" does not.
            if ($after >= $limit) {
                return $pos;
            }
            $c = $s[$after];
            if ($c === '>' || $c === '/' || $c === ' ' || ($c >= "\t" && $c <= "\r")) {
                return $pos;
            }
            $from = $after;
        }
        return $limit;
    }

    /**
     * The position of the '>' that ends a comment whose content starts at $contentStart (in $s[0, $limit)). As in
     * HTML5: "<!-->" and "<!--->" are empty comments, "-->" or "--!>" end a comment, otherwise it runs to the end.
     */
    public static function findCommentEnd(string $s, int $contentStart, int $limit): int
    {
        if ($contentStart < $limit && $s[$contentStart] === '>') {
            return $contentStart;
        }
        if ($contentStart + 1 < $limit && $s[$contentStart] === '-' && $s[$contentStart + 1] === '>') {
            return $contentStart + 1;
        }
        $from = $contentStart;
        $len = strlen($s);
        while ($from < $limit) {
            $dashes = $from < $len ? strpos($s, '--', $from) : false;
            if ($dashes === false || $dashes + 2 > $limit) {
                break;
            }
            $pos = $dashes + 2;
            if ($pos < $limit && $s[$pos] === '>') {
                return $pos;
            }
            if ($pos + 1 < $limit && $s[$pos] === '!' && $s[$pos + 1] === '>') {
                return $pos + 1;
            }
            $from = $dashes + 1;
        }
        return $limit - 1;
    }

    /** An XML document (RSS, Atom, sitemap, XHTML) is parsed by XML rules: elements end only with an end tag or "/>". */
    private static function detectXml(string $src): bool
    {
        $i = 0;
        $n = strlen($src);
        for (;;) {
            $i += strspn($src, Chars::SPACE, $i);
            if ($i + 3 <= $n && $src[$i] === "\xEF" && $src[$i + 1] === "\xBB" && $src[$i + 2] === "\xBF") {
                $i += 3; // a BOM
                continue;
            }
            break;
        }
        return $n - $i >= 5 && $src[$i] === '<' && $src[$i + 1] === '?' && Chars::startsWithFold($src, $i, '<?xml');
    }

    /**
     * Parses $src into the flat tag list of $doc.
     *
     * @return list<HtmlNode>
     */
    public static function parseTags(HtmlDocument $doc, string $src): array
    {
        if (!str_contains($src, '<')) {
            return [];
        }
        if (!self::$ready) {
            self::init();
        }
        $st = new self($doc, $src);
        $st->scan(false, 0, strlen($src));

        // The end of the document ends every tag still open (a truncated page keeps its text),
        // right before an unfinished tag if the source stops inside one.
        $regionEnd = $st->tagStarted ? $st->tagStart - 1 : strlen($src);
        if (!$st->isXml) {
            $s = $st->firstMisnested(0, $st->stackTop);
            if ($s >= 0) {
                $st->repair($s, $regionEnd);
            }
        }
        $st->truncateOpenList(0, $regionEnd - 1);

        $nodes = $st->count === count($st->nodes) ? $st->nodes : array_slice($st->nodes, 0, $st->count);
        foreach ($nodes as $n) {
            if ($n->innerEnd === 0) {
                $n->innerStart = 0; // not closed: no body
            }
        }
        return $nodes;
    }

    // ----- the state of one parse -----

    private readonly bool $isXml;
    /** @var list<HtmlNode> */
    private array $nodes = [];
    /** @var list<int> Tag key, or -1 for a custom name */
    private array $key = [];
    /** @var list<int> */
    private array $depth = [];
    /** @var list<int> the open tags as a linked list in document order */
    private array $prevOpen = [];
    /** @var list<bool> <x/>: stays in the open list (a later </x> ends it), but nests nothing */
    private array $selfClosed = [];
    /** @var list<int> indexes of the open tags that contain the following ones */
    private array $stack = [];
    /** @var array<int, int> per Tag, how many are on the stack: kept only in rules mode */
    private array $openByKey = [];
    /** @var array<int, int> per Tag, how many are in the open list */
    private array $openInList = [];
    /** @var array<string, int> custom names (lower case): how many such tags are in the open list */
    private array $custom = [];
    private int $count = 0;
    private int $stackTop = 0;
    private int $lastOpen = -1;
    private int $openP = 0; // <p> tags on the stack, kept in both modes
    private int $budget; // bytes that may still be scanned again by repair
    private bool $tagStarted = false; // scan results
    private int $tagStart = 0;

    private function __construct(private readonly HtmlDocument $doc, private readonly string $src)
    {
        $this->isXml = self::detectXml($src);
        $this->budget = self::REPAIR_BUDGET_FACTOR * strlen($src);
    }

    /** Parses $src[$from, $to) ($from is outside a tag), adding tags at count. Tags still open at $to stay on the stack. */
    private function scan(bool $rules, int $from, int $to): void
    {
        $src = $this->src;
        $tagStarted = false;
        $tagStart = 0;
        $i = $from;
        while ($i < $to) {
            if (!$tagStarted) {
                // Outside a tag only '<' matters: jump to it.
                $lt = strpos($src, '<', $i);
                if ($lt === false || $lt >= $to) {
                    break;
                }
                $i = $lt;
                if ($i + 1 >= $to) {
                    break;
                }
                $c = $src[$i + 1];

                if ($c === '!' && $i + 4 <= $to && $src[$i + 2] === '-' && $src[$i + 3] === '-') {
                    // <!-- ... -->: one "!--" tag; its content is not markup, it does not nest anything.
                    $commentEnd = self::findCommentEnd($src, $i + 4, $to);
                    $parent = -1;
                    $depth = 0;
                    if ($this->stackTop > 0) {
                        $parent = $this->stack[$this->stackTop - 1];
                        $depth = $this->depth[$parent] + 1;
                    }
                    $k = $this->count;
                    $this->nodes[$k] = new HtmlNode($this->doc, $k, $parent, self::COMMENT, '', $i, $commentEnd, $commentEnd + 1, $depth);
                    $this->key[$k] = self::COMMENT;
                    $this->depth[$k] = $depth;
                    $this->prevOpen[$k] = -1;
                    $this->selfClosed[$k] = false;
                    $this->count++;
                    $i = $commentEnd + 1;
                    continue;
                }

                // As in HTML5, '<' starts markup only before a letter, '/', '!' or '?': "a < b" is text.
                if (!(ctype_alpha($c) || $c === '/' || $c === '!' || $c === '?')) {
                    $i++;
                    continue;
                }

                $tagStarted = true;
                $tagStart = $i + 1;
                $i++;
                continue;
            }

            // Inside a tag only '<', '=' and '>' matter.
            $i += strcspn($src, '<=>', $i, $to - $i);
            if ($i >= $to) {
                break;
            }
            $c = $src[$i];
            if ($c === '<') {
                // An unfinished tag: start over at this '<' (it may also start a comment).
                $tagStarted = false;
                continue;
            }
            if ($c === '=') {
                // A quoted value may contain '<' and '>' (data-x="<p>...</p>"): jump over it.
                $q = $i + 1;
                $q += strspn($src, Chars::SPACE, $q, $to - $q);
                if ($q < $to) {
                    $quote = $src[$q];
                    if ($quote === '"' || $quote === "'") {
                        $closeQuote = strpos($src, $quote, $q + 1);
                        if ($closeQuote !== false && $closeQuote < $to) {
                            $i = $closeQuote;
                        }
                    }
                }
            } else {
                // '>'
                if ($src[$tagStart] === '/') {
                    $this->endTag($rules, $tagStart, $i);
                } else {
                    $i = $this->startTag($rules, $tagStart, $i, $to);
                }
                $tagStarted = false;
            }
            $i++;
        }
        $this->tagStarted = $tagStarted;
        $this->tagStart = $tagStart;
    }

    /** Matches the end tag $src[$tagStart-1 .. $gt] to the innermost open tag with that name. */
    private function endTag(bool $rules, int $tagStart, int $gt): void
    {
        $src = $this->src;
        $from = $tagStart + 1;
        $to = $gt;
        while (($n = Chars::spaceBefore($src, $to, $from)) > 0) {
            $to -= $n;
        }
        while (($n = Chars::spaceAt($src, $from, $to)) > 0) {
            $from += $n;
        }
        if ($from === $to) {
            return;
        }
        // Resolve the name once, then compare ints instead of a lookup per candidate.
        $key = self::lookupTagAt($src, $from, $to);
        $name = $key >= 0 ? '' : substr($src, $from, $to - $from); // only custom names are compared as strings
        // The rules may have ended the matched tag itself (<li><div><li>x</div>: the second <li> ends the first one
        // together with its <div>, as in a browser): the end tag is matched again, to what is open now.
        // Once: the part is not scanned a second time.
        if ($this->matchEnd($rules, $key, $name, $tagStart, $gt, true)) {
            $this->matchEnd($rules, $key, $name, $tagStart, $gt, false);
        }
    }

    /**
     * Ends the innermost open tag with the end tag's name. Returns true when it scanned a part again
     * with the optional-end-tag rules instead, and the end tag is to be matched once more.
     */
    private function matchEnd(bool $rules, int $key, string $name, int $tagStart, int $gt, bool $canRepair): bool
    {
        // A name that no open tag has is a stray end tag: it is skipped without scanning the open tags.
        $closing = '';
        if ($key >= 0) {
            if (($this->openInList[$key] ?? 0) <= 0) {
                return false;
            }
        } else {
            if ($this->custom === []) {
                return false;
            }
            $closing = Chars::lower($name);
            if (($this->custom[$closing] ?? 0) <= 0) {
                return false;
            }
        }

        for ($j = $this->lastOpen; $j >= 0; $j = $this->prevOpen[$j]) {
            if ($key >= 0) {
                if ($this->key[$j] !== $key) {
                    continue;
                }
            } elseif ($this->key[$j] >= 0 || $this->nodes[$j]->custom !== $closing) {
                continue;
            }

            $innerEnd = $tagStart - 2;

            // Tags left open inside it: if the optional-end-tag rules apply to one of them,
            // scan its part again with the rules (fast mode only; rules mode has applied them).
            if ($canRepair && !$rules && !$this->isXml && $this->stackTop > 0 && $this->stack[$this->stackTop - 1] > $j) {
                $above = $this->stackTop - 1;
                while ($above > 0 && $this->stack[$above - 1] > $j) {
                    $above--;
                }
                $target = $this->firstMisnested($above, $this->stackTop);
                if ($target >= 0) {
                    $this->repair($target, $tagStart - 1);
                    return true;
                }
            }

            $node = $this->nodes[$j];
            $node->innerEnd = $innerEnd;
            $node->end = $gt;

            while ($this->stackTop > 0 && $this->stack[$this->stackTop - 1] >= $j) {
                $this->stackTop--;
                $popped = $this->key[$this->stack[$this->stackTop]];
                if ($popped === self::P) {
                    $this->openP--;
                }
                if ($rules && $popped >= 0) {
                    $this->openByKey[$popped]--;
                }
            }

            // It leaves the open list with everything opened after it; the tags opened inside it and
            // never closed end right before this end tag, as in a browser.
            $this->truncateOpenList($j, $innerEnd);
            return false;
        }
        return false;
    }

    /** Adds the tag $src[$tagStart-1 .. $gt]; returns the position to continue from ($gt, or before a raw-text end tag). */
    private function startTag(bool $rules, int $tagStart, int $gt, int $to): int
    {
        $src = $this->src;
        // The name in the inside of the opening tag (trimmed; "!--" for a comment).
        $from = $tagStart;
        $end = $gt;
        while (($n = Chars::spaceBefore($src, $end, $from)) > 0) {
            $end -= $n;
        }
        while (($n = Chars::spaceAt($src, $from, $end)) > 0) {
            $from += $n;
        }
        if ($from + 3 <= $end && $src[$from] === '!' && $src[$from + 1] === '-' && $src[$from + 2] === '-') {
            $nameTo = $from + 3;
        } else {
            // The name ends at whitespace or '/': <br/> is "br", as <br /> is.
            $nameTo = $from + strcspn($src, " \t\n\x0b\x0c\r/", $from, $end - $from);
        }
        $key = self::lookupTagAt($src, $from, $nameTo);
        // Custom names (svg elements, web components, ...): the lower-case name.
        $customName = '';
        if ($key < 0 && $nameTo > $from) {
            $customName = Chars::lower(substr($src, $from, $nameTo - $from));
            $this->custom[$customName] ??= 0;
        }

        if (!$this->isXml && $key >= 0) {
            // Optional end tags (HTML5): <li> ends an open <li>, <td> an open <td>, ...
            $rule = self::$rules[$key] ?? null;
            if ($rule !== null) {
                if ($rules) {
                    foreach ($rule['k'] as $t) {
                        if (($this->openByKey[$t] ?? 0) > 0) {
                            // a target is open (in well-formed markup it is not)
                            $this->closeInScope($rule, $tagStart - 2, true);
                            break;
                        }
                    }
                } elseif ($this->stackTop > 0) {
                    // The common case of a left-out end tag is caught right here: the innermost open tag is a target.
                    $top = $this->key[$this->stack[$this->stackTop - 1]];
                    if ($top >= 0 && isset($rule['t'][$top])) {
                        $this->applyRuleInFastMode($rule, $tagStart);
                    }
                }
            }

            // A block element ends an open <p>. Usually no <p> is open and this is one compare.
            if ($this->openP > 0 && isset(self::$closesP[$key])) {
                if ($rules) {
                    $this->closeInScope(self::$pRule, $tagStart - 2, true);
                } else {
                    $this->applyRuleInFastMode(self::$pRule, $tagStart);
                }
            }
        }

        // the innermost open tag is the parent
        $parent = -1;
        $depth = 0;
        if ($this->stackTop > 0) {
            $parent = $this->stack[$this->stackTop - 1];
            $depth = $this->depth[$parent] + 1;
        }

        $index = $this->count;
        $this->nodes[$index] = new HtmlNode($this->doc, $index, $parent, $key, $customName, $tagStart - 1, $gt, $gt + 1, $depth);

        // Void elements (<br>, <img>, <meta>, ...) and declarations never contain anything.
        $isVoid = !$this->isXml && $key >= 0 && isset(self::$void[$key]);
        $selfClosing = $isVoid;
        if (!$selfClosing) {
            $e = $gt;
            while ($e > $tagStart && strspn($src[$e - 1], Chars::SPACE) === 1) {
                $e--;
            }
            $selfClosing = $e > $tagStart && $src[$e - 1] === '/';
        }

        $this->key[$index] = $key;
        $this->depth[$index] = $depth;
        if ($isVoid) {
            $this->prevOpen[$index] = -1;
            $this->selfClosed[$index] = false;
        } else {
            $this->prevOpen[$index] = $this->lastOpen;
            $this->selfClosed[$index] = $selfClosing;
            $this->lastOpen = $index;
            if ($key >= 0) {
                $this->openInList[$key] = ($this->openInList[$key] ?? 0) + 1;
            } elseif ($customName !== '') {
                $this->custom[$customName]++;
            }
        }
        $this->count++;

        if (!$selfClosing) {
            // Deeper than MAX_DEPTH the tag is not nested: it stays in the open list for its end tag.
            if ($this->stackTop < self::MAX_DEPTH) {
                $this->stack[$this->stackTop++] = $index;
                if ($key === self::P) {
                    $this->openP++;
                }
                if ($rules && $key >= 0) {
                    $this->openByKey[$key] = ($this->openByKey[$key] ?? 0) + 1;
                }
            }

            // <script>, <style>, <textarea>, <title>, ...: the content is text, not markup;
            // continue right before the matching end tag.
            if (!$this->isXml && $key >= 0 && isset(self::RAW_TEXT_ENDS[$key])) {
                return self::findRawTextEnd($src, $gt + 1, self::RAW_TEXT_ENDS[$key], $to) - 1;
            }
        }
        return $gt;
    }

    // ----- implied end tags -----

    /** Among the tags left open at stack positions [$from, $to), the outermost one that a rule should have ended, or -1. */
    private function firstMisnested(int $from, int $to): int
    {
        for ($s = $from; $s < $to - 1; $s++) {
            $key = $this->key[$this->stack[$s]];
            if ($key < 0 || !isset(self::$isRuleTarget[$key])) {
                continue;
            }
            for ($inner = $s + 1; $inner < $to; $inner++) {
                $innerKey = $this->key[$this->stack[$inner]];
                if ($innerKey >= 0 && isset(self::$rules[$innerKey]['t'][$key])) {
                    return $s;
                }
            }
        }
        return -1;
    }

    /**
     * Scans again with the optional-end-tag rules from the tag at stack position $stackPos up to $regionEnd:
     * the tags from it on are replaced, the ones before it are kept.
     */
    private function repair(int $stackPos, int $regionEnd): void
    {
        $first = $this->stack[$stackPos];
        $from = $this->nodes[$first]->start;

        // Past the budget the part is left as it is, so that parsing stays linear on any markup.
        $cost = $regionEnd - $from;
        if ($cost > $this->budget) {
            return;
        }
        $this->budget -= $cost;

        $this->truncateOpenList($first, -1);
        $this->stackTop = $stackPos;
        $this->count = $first;

        $this->openByKey = [];
        $this->openP = 0;
        for ($s = 0; $s < $stackPos; $s++) {
            $key = $this->key[$this->stack[$s]];
            if ($key < 0) {
                continue;
            }
            $this->openByKey[$key] = ($this->openByKey[$key] ?? 0) + 1;
            if ($key === self::P) {
                $this->openP++;
            }
        }

        $this->scan(true, $from, $regionEnd);
    }

    /**
     * Removes the tags from index $first on from the open list (they are its tail).
     * With $end >= 0 the ones that have no end tag of their own end there.
     */
    private function truncateOpenList(int $first, int $end): void
    {
        $k = $this->lastOpen;
        while ($k >= $first) {
            $node = $this->nodes[$k];
            $key = $this->key[$k];
            if ($key >= 0) {
                $this->openInList[$key]--;
            } elseif ($node->custom !== '' && isset($this->custom[$node->custom])) {
                $this->custom[$node->custom]--;
            }

            if ($end >= 0 && !$this->selfClosed[$k] && $node->innerEnd === 0) {
                $node->innerEnd = $end;
                $node->end = $end;
            }
            $k = $this->prevOpen[$k];
        }
        $this->lastOpen = $k;
    }

    /** The stack position of the outermost target of the rule below the first stop tag, or -1. */
    private function scopeStart(array $rule): int
    {
        $from = -1;
        for ($s = $this->stackTop - 1; $s >= 0; $s--) {
            $k = $this->key[$this->stack[$s]];
            if ($k < 0) {
                continue;
            }
            if (isset($rule['t'][$k])) {
                $from = $s;
            } elseif (isset($rule['s'][$k])) {
                break;
            }
        }
        return $from;
    }

    /**
     * Applies the rule for the tag that starts at $tagStart. If a tag it ends was itself misnested,
     * that part is scanned again with the rules first.
     */
    private function applyRuleInFastMode(array $rule, int $tagStart): void
    {
        $from = $this->scopeStart($rule);
        if ($from < 0) {
            return;
        }
        $m = $this->firstMisnested($from, $this->stackTop);
        if ($m >= 0) {
            $this->repair($m, $tagStart - 1);
        }
        $this->closeInScope($rule, $tagStart - 2, false);
    }

    /** Ends the outermost target found below the first stop tag together with everything opened inside it. */
    private function closeInScope(array $rule, int $end, bool $byKey): void
    {
        $from = $this->scopeStart($rule);
        if ($from < 0) {
            return;
        }
        $first = $this->stack[$from];
        while ($this->stackTop > $from) {
            $this->stackTop--;
            $key = $this->key[$this->stack[$this->stackTop]];
            if ($key === self::P) {
                $this->openP--;
            }
            if ($byKey && $key >= 0) {
                $this->openByKey[$key]--;
            }
        }
        $this->truncateOpenList($first, $end);
    }
}
