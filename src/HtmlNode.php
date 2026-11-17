<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * One tag of a document. Positions are byte offsets into the document source (substr() works with them). Methods that
 * look for another tag return null when there is none; methods that read a value return "" when there is none.
 */
final class HtmlNode implements \Stringable
{
    // The tag as the parser left it, copied from the arrays of the document. Read them through the methods:
    // the properties are public (and untyped) only because PHP reads such properties fastest.

    /** @internal @var HtmlDocument */
    public $doc;
    /** @internal @var int */
    public $index;
    /** @internal @var int the index of the enclosing tag, -1 at the top level */
    public $parentIndex;
    /** @internal @var int Tag key, or -1 for a name that is not in Tag */
    public $key;
    /** @internal @var string the lower-case name of a tag that is not in Tag, "" for the others */
    public $custom;
    /** @internal @var int */
    public $start;
    /** @internal @var int */
    public $end;
    /** @internal @var int right after the opening tag; 0 when the tag is not closed */
    public $innerStart;
    /** @internal @var int the last byte before the end tag; 0 when the tag is not closed */
    public $innerEnd;
    /** @internal @var int */
    public $depth;

    /** @internal Tags are made by the document: HtmlDocument::node(). */
    public function __construct(HtmlDocument $doc, int $i)
    {
        $this->doc = $doc;
        $this->index = $i;
        $this->parentIndex = $doc->parents[$i];
        $this->key = $doc->keys[$i];
        $this->custom = $doc->customs[$i];
        $this->start = $doc->starts[$i];
        $this->end = $doc->ends[$i];
        $this->innerStart = $doc->innerStarts[$i];
        $this->innerEnd = $doc->innerEnds[$i];
        $this->depth = $doc->depths[$i];
    }

    // ----- reading a tag -----

    /** The document the tag belongs to. */
    public function document(): HtmlDocument
    {
        return $this->doc;
    }

    /** The position of the tag in the document. */
    public function index(): int
    {
        return $this->index;
    }

    /** The byte offset of the opening '<' in the source. */
    public function start(): int
    {
        return $this->start;
    }

    /** The byte offset of the last '>': of the end tag when there is one, otherwise of the opening tag. */
    public function end(): int
    {
        return $this->end;
    }

    /** The nesting depth: 0 for top-level tags. */
    public function depth(): int
    {
        return $this->depth;
    }

    /** The tag as a Tag, or null for a name that is not one of the known tags. */
    public function tag(): ?Tag
    {
        return $this->key >= 0 ? Tag::from(TagNames::NAMES[$this->key]) : null;
    }

    /** The lower-case tag name ("div", "!--" for comments, "custom-tag", ...). */
    public function name(): string
    {
        return $this->key >= 0 ? TagNames::NAMES[$this->key] : $this->custom;
    }

    /** The inner HTML (trimmed); "" for tags that are not closed. */
    public function body(): string
    {
        if ($this->innerStart < 0 || $this->innerEnd <= 0) {
            return '';
        }
        return Chars::trimSpace(Chars::slice($this->doc->source, $this->innerStart, $this->innerEnd + 1));
    }

    /** The source of the whole element; "" for tags that are not closed. */
    public function outerHtml(): string
    {
        if ($this->innerStart <= 0 || $this->innerEnd <= 0) {
            return '';
        }
        return Chars::trimSpace(Chars::slice($this->doc->source, $this->start, $this->end + 1));
    }

    /**
     * The visible text with entities decoded. With child tags: tags, comments and the content of script, style,
     * noscript, template, noframes, noembed removed, whitespace collapsed; without: the inner text as is (trimmed).
     */
    public function text(): string
    {
        $body = $this->body();
        // No child tags and no '<' at all: the body is the text, except in script, style, textarea, title, ...
        if (!$this->hasChildren() && (!str_contains($body, '<') || isset(Parser::RAW_TEXT_ENDS[$this->key]))) {
            return strpbrk($body, "&\r") === false ? $body : Decode::decode($body, false);
        }
        return Text::clearTags($body);
    }

    /**
     * The text with the visible line structure kept: <br> and block elements give new lines, p and headings
     * a blank line, td / th a space; lines are trimmed and joined with "\n".
     */
    public function textLineBreaks(): string
    {
        if ($this->innerStart < 0 || $this->innerEnd <= 0) {
            return '';
        }
        return TextLines::clearTagsLineBreaks($this->body());
    }

    /** Whether another tag starts inside this one. */
    public function hasChildren(): bool
    {
        $i = $this->index + 1;
        return $i < $this->doc->size && $this->doc->starts[$i] < $this->end;
    }

    /** The opening tag without '<' and '>': the name and the attributes. */
    public function source(): string
    {
        $src = $this->doc->source;
        if ($this->innerStart > 0 && $this->innerEnd > 0) {
            $length = $this->innerStart - $this->start - 2;
        } elseif ($this->end > 0 && $this->end - 1 < strlen($src) && $src[$this->end - 1] === '/') {
            $length = $this->end - $this->start - 2;
        } else {
            $length = $this->end - $this->start - 1;
        }
        return Chars::trimSpace(Chars::slice($src, $this->start + 1, $this->start + 1 + $length));
    }

    public function __toString(): string
    {
        return '<' . $this->source() . '>';
    }

    // ----- attributes -----

    /** The decoded value of the first attribute with the name (case-insensitive); "" when it is missing or has no value. */
    public function attr(string $name): string
    {
        return Attrs::attrValue($this, $name);
    }

    /** The value of the attribute as written in the source. */
    public function attrRaw(string $name): string
    {
        return Attrs::attrRaw($this, $name);
    }

    /** Whether the tag has the attribute, with or without a value (case-insensitive name). */
    public function hasAttr(string $name): bool
    {
        return Attrs::hasAttribute($this, $name);
    }

    /** Whether one of the space-separated classes equals $cls (case-insensitive). */
    public function hasClass(string $cls): bool
    {
        return Attrs::hasClass($this, $cls);
    }

    /**
     * Every attribute of the opening tag: [name as written, raw value] pairs in source order.
     *
     * @return list<array{string, string}>
     */
    public function attrs(): array
    {
        $out = [];
        if ($this->key === Parser::COMMENT) {
            return $out;
        }
        $s = new AttrScanner($this);
        while ($s->next()) {
            $out[] = [$s->name(), $s->rawValue()];
        }
        return $out;
    }

    /** The link a browser follows: href decoded and made absolute against the page URL; an empty or missing href gives the page URL. */
    public function href(): string
    {
        return Links::correctLink(Attrs::attrRaw($this, Attr::HREF), $this->doc->url, $this->doc->base());
    }

    /** href() resolved against $baseUrl instead of the page URL. */
    public function hrefFrom(string $baseUrl): string
    {
        return Links::correctLink(Attrs::attrRaw($this, Attr::HREF), $baseUrl, Links::absoluteUrl($baseUrl));
    }

    /** The src attribute decoded and made absolute against the page URL. */
    public function src(): string
    {
        return Links::correctLink(Attrs::attrRaw($this, Attr::SRC), $this->doc->url, $this->doc->base());
    }

    /** src() resolved against $baseUrl instead of the page URL. */
    public function srcFrom(string $baseUrl): string
    {
        return Links::correctLink(Attrs::attrRaw($this, Attr::SRC), $baseUrl, Links::absoluteUrl($baseUrl));
    }

    /** The address of a mailto: link (everything after the first ':'), or "". */
    public function hrefMailTo(): string
    {
        return Links::afterScheme(Attrs::attrRaw($this, Attr::HREF), 'mailto');
    }

    /** The number of a tel: link (everything after the first ':'), or "". */
    public function hrefTelephone(): string
    {
        return Links::afterScheme(Attrs::attrRaw($this, Attr::HREF), 'tel');
    }

    // ----- navigation -----

    /** The enclosing tag, or null. */
    public function parent(): ?HtmlNode
    {
        return $this->parentIndex >= 0 ? $this->doc->at($this->parentIndex) : null;
    }

    /**
     * The direct children: the tags inside this one, one level deeper.
     *
     * @return list<HtmlNode>
     */
    public function children(): array
    {
        $out = [];
        $d = $this->doc;
        $ends = $d->ends;
        $depths = $d->depths;
        $depth = $this->depth + 1;
        for ($i = $this->index + 1, $count = $d->size; $i < $count && $ends[$i] <= $this->end; $i++) {
            if ($depths[$i] === $depth) {
                $out[] = $d->at($i);
            }
        }
        return $out;
    }

    /** The tag that follows in document order (the first child, if there is one), or null. */
    public function next(): ?HtmlNode
    {
        return $this->doc->node($this->index + 1);
    }

    /** The tag before this one in document order, or null. */
    public function previous(): ?HtmlNode
    {
        return $this->index > 0 ? $this->doc->at($this->index - 1) : null;
    }

    /** The first tag after the end of this one that matches the query (tags inside it are skipped), or null. */
    public function nextMatching(Query $q): ?HtmlNode
    {
        $d = $this->doc;
        $starts = $d->starts;
        for ($i = $this->index + 1, $count = $d->size; $i < $count; $i++) {
            if ($starts[$i] > $this->end && $q->nameMatchesAt($d, $i) && $q->matches($d->at($i))) {
                return $d->at($i);
            }
        }
        return null;
    }

    /** The next tag at the same level inside the same parent, or null. */
    public function nextSibling(): ?HtmlNode
    {
        return $this->nextSiblingMatching(Query::any());
    }

    /** The next sibling that matches the query, or null. */
    public function nextSiblingMatching(Query $q): ?HtmlNode
    {
        // deeper tags are inside the following siblings; a shallower one ends the parent
        $d = $this->doc;
        $depths = $d->depths;
        for ($i = $this->index + 1, $count = $d->size; $i < $count && $depths[$i] >= $this->depth; $i++) {
            if ($depths[$i] === $this->depth && $q->nameMatchesAt($d, $i) && $q->matches($d->at($i))) {
                return $d->at($i);
            }
        }
        return null;
    }

    /** The previous tag at the same level inside the same parent, or null. */
    public function previousSibling(): ?HtmlNode
    {
        return $this->previousSiblingMatching(Query::any());
    }

    /** The nearest previous sibling that matches the query, or null. */
    public function previousSiblingMatching(Query $q): ?HtmlNode
    {
        // deeper tags are inside the previous siblings; a shallower one is the parent
        $d = $this->doc;
        $depths = $d->depths;
        for ($i = $this->index - 1; $i >= 0 && $depths[$i] >= $this->depth; $i--) {
            if ($depths[$i] === $this->depth && $q->nameMatchesAt($d, $i) && $q->matches($d->at($i))) {
                return $d->at($i);
            }
        }
        return null;
    }

    /** The nearest enclosing tag that matches the query, or null. */
    public function parentMatching(Query $q): ?HtmlNode
    {
        for ($p = $this->parent(); $p !== null; $p = $p->parent()) {
            if ($q->matches($p)) {
                return $p;
            }
        }
        return null;
    }

    /** The source text right after the tag up to the next '<' (or the end of the page). */
    public function textAfterTagUntilNextOne(): string
    {
        $src = $this->doc->source;
        $from = $this->end + 1;
        if ($from >= strlen($src)) {
            return '';
        }
        $next = strpos($src, '<', $from);
        return $next === false ? substr($src, $from) : substr($src, $from, $next - $from);
    }

    /** textAfterTagUntilNextOne() as visible text: entities decoded, whitespace collapsed. */
    public function textNoTagsAfterTagUntilNextOne(): string
    {
        return Text::clearTags($this->textAfterTagUntilNextOne());
    }

    /** The source between the end of the tag and the next tag that matches the query; "" when there is none. */
    public function textAfterUntil(Query $q): string
    {
        $until = $this->nextMatching($q);
        return $until === null ? '' : Chars::slice($this->doc->source, $this->end + 1, $until->start);
    }

    // ----- search -----

    /** The first tag inside this one (a descendant) that matches the query, or null. */
    public function find(Query $q): ?HtmlNode
    {
        return $q->findIn($this->doc, $this->index + 1, $this->end);
    }

    /**
     * All tags inside this one (descendants) that match the query.
     *
     * @return list<HtmlNode>
     */
    public function findAll(Query $q): array
    {
        return $q->findAllIn($this->doc, $this->index + 1, $this->end);
    }

    /** Whether the tag matches the query. */
    public function is(Query $q): bool
    {
        return $q->matches($this);
    }

    /**
     * The tags inside this one that match a CSS selector (as querySelectorAll); :scope is this tag, "> li" its children.
     *
     * @return list<HtmlNode>
     */
    public function css(string $selector): array
    {
        return CssQuery::cached($selector)->allIn($this);
    }

    /** The first tag inside this one that matches a CSS selector, or null. */
    public function cssFirst(string $selector): ?HtmlNode
    {
        return CssQuery::cached($selector)->firstIn($this);
    }

    /** Whether the tag matches a CSS selector (as element.matches()). */
    public function isCss(string $selector): bool
    {
        return CssQuery::cached($selector)->matches($this);
    }

    /**
     * The tags an XPath expression selects; relative paths start at this tag, '/' and '//' search the whole document.
     *
     * @return list<HtmlNode>
     */
    public function xpath(string $expression): array
    {
        return XPathQuery::cached($expression)->nodesFrom($this);
    }

    /** The first tag an XPath expression selects from this tag, or null. */
    public function xpathFirst(string $expression): ?HtmlNode
    {
        return $this->xpath($expression)[0] ?? null;
    }

    /**
     * The values an XPath expression selects from this tag.
     *
     * @return list<string>
     */
    public function xpathValues(string $expression): array
    {
        return XPathQuery::cached($expression)->valuesFrom($this);
    }

    /** The first value an XPath expression selects from this tag, or "". */
    public function xpathValue(string $expression): string
    {
        return XPathQuery::cached($expression)->valueFrom($this) ?? '';
    }

    // <shortcuts node>
    // Generated by tools/gen.php; do not edit by hand.

    /** Returns the first tag with the id inside the tag, or null. */
    public function findById(string $id): ?HtmlNode
    {
        if ($id === '') {
            return null;
        }
        return $this->find(Query::any()->id($id));
    }

    /** Returns the first tag with the name inside the tag, or null. */
    public function findByName(Tag|string $name): ?HtmlNode
    {
        return $this->find(Query::by($name));
    }

    /** Returns the first tag with the name and the inner HTML inside the tag, or null. */
    public function findByNameBody(Tag|string $name, string $body): ?HtmlNode
    {
        return $this->find(Query::by($name)->body($body));
    }

    /** Returns the first tag with the name and the decoded attribute value inside the tag, or null. */
    public function findByNameAttribute(Tag|string $name, string $attribute, string $value): ?HtmlNode
    {
        return $this->find(Query::by($name)->attr($attribute, $value));
    }

    /** Returns the first tag with the name and the class inside the tag, or null. */
    public function findByNameClass(Tag|string $name, string $cls): ?HtmlNode
    {
        return $this->find(Query::by($name)->hasClass($cls));
    }

    /** Returns the first tag with the name, the class and the inner HTML inside the tag, or null. */
    public function findByNameClassBody(Tag|string $name, string $cls, string $body): ?HtmlNode
    {
        return $this->find(Query::by($name)->hasClass($cls)->body($body));
    }

    /** Returns the first tag with the name, and the class and visible text when they are not empty inside the tag, or null. */
    public function findByNameClassText(Tag|string $name, string $cls, string $text): ?HtmlNode
    {
        return $this->find(Query::optText(Query::optClass(Query::by($name), $cls), $text));
    }

    /** Returns the first tag with the name, and the id and visible text when they are not empty inside the tag, or null. */
    public function findByNameIdText(Tag|string $name, string $id, string $text): ?HtmlNode
    {
        return $this->find(Query::optText(Query::optId(Query::by($name), $id), $text));
    }

    /** Returns the first tag with the name and the visible text inside the tag, or null. */
    public function findByNameText(Tag|string $name, string $text): ?HtmlNode
    {
        return $this->find(Query::by($name)->text($text));
    }

    /** Returns the first tag with the name and the id inside the tag, or null. */
    public function findByNameId(Tag|string $name, string $id): ?HtmlNode
    {
        return $this->find(Query::by($name)->id($id));
    }

    /** Returns the first tag with the name whose id starts with the prefix inside the tag, or null. */
    public function findByNameIdStartsWith(Tag|string $name, string $idPrefix): ?HtmlNode
    {
        return $this->find(Query::by($name)->idStartsWith($idPrefix));
    }

    /** Returns the first tag with the name whose inner HTML starts with the prefix inside the tag, or null. */
    public function findByNameBodyStartsWith(Tag|string $name, string $bodyPrefix): ?HtmlNode
    {
        return $this->find(Query::by($name)->bodyStartsWith($bodyPrefix));
    }

    /** Returns the first tag with the name whose visible text starts with the prefix inside the tag, or null. */
    public function findByNameTextStartsWith(Tag|string $name, string $textPrefix): ?HtmlNode
    {
        if ($textPrefix === '') {
            return null;
        }
        return $this->find(Query::by($name)->textStartsWith($textPrefix));
    }

    /** Returns the first tag with the name and the class whose visible text starts with the prefix inside the tag, or null. */
    public function findByNameClassTextStartsWith(Tag|string $name, string $cls, string $textPrefix): ?HtmlNode
    {
        if ($textPrefix === '') {
            return null;
        }
        return $this->find(Query::by($name)->hasClass($cls)->textStartsWith($textPrefix));
    }

    /** Returns the first tag with the name and the itemprop inside the tag, or null. */
    public function findByNameItemprop(Tag|string $name, string $itemprop): ?HtmlNode
    {
        return $this->find(Query::by($name)->attr(Attr::ITEMPROP, $itemprop));
    }

    /**
     * Returns all tags with the name inside the tag.
     *
     * @return list<HtmlNode>
     */
    public function findAllByName(Tag|string $name): array
    {
        return $this->findAll(Query::by($name));
    }

    /**
     * Returns all tags with the name and the decoded attribute value inside the tag.
     *
     * @return list<HtmlNode>
     */
    public function findAllByNameAttribute(Tag|string $name, string $attribute, string $value): array
    {
        return $this->findAll(Query::by($name)->attr($attribute, $value));
    }

    /**
     * Returns all tags with the name and the class inside the tag.
     *
     * @return list<HtmlNode>
     */
    public function findAllByNameClass(Tag|string $name, string $cls): array
    {
        return $this->findAll(Query::by($name)->hasClass($cls));
    }

    /**
     * Returns all tags with the name and the id inside the tag.
     *
     * @return list<HtmlNode>
     */
    public function findAllByNameId(Tag|string $name, string $id): array
    {
        return $this->findAll(Query::by($name)->id($id));
    }

    /**
     * Returns all tags with the name whose id starts with the prefix inside the tag.
     *
     * @return list<HtmlNode>
     */
    public function findAllByNameIdStartsWith(Tag|string $name, string $idPrefix): array
    {
        return $this->findAll(Query::by($name)->idStartsWith($idPrefix));
    }

    /**
     * Returns all tags with the name and the itemprop inside the tag.
     *
     * @return list<HtmlNode>
     */
    public function findAllByNameItemprop(Tag|string $name, string $itemprop): array
    {
        return $this->findAll(Query::by($name)->attr(Attr::ITEMPROP, $itemprop));
    }

    /**
     * Returns all tags with the name whose inner HTML starts with the prefix inside the tag.
     *
     * @return list<HtmlNode>
     */
    public function findAllByNameBodyStartsWith(Tag|string $name, string $bodyPrefix): array
    {
        return $this->findAll(Query::by($name)->bodyStartsWith($bodyPrefix));
    }

    /** Whether the tag has the name and the decoded attribute value. */
    public function isNameAttribute(Tag|string $name, string $attribute, string $value): bool
    {
        return $this->is(Query::by($name)->attr($attribute, $value));
    }

    /** Whether the tag has the name and the inner HTML. */
    public function isNameBody(Tag|string $name, string $body): bool
    {
        return $this->is(Query::by($name)->body($body));
    }

    /** Whether the tag has the name and the visible text. */
    public function isNameText(Tag|string $name, string $text): bool
    {
        return $this->is(Query::by($name)->text($text));
    }

    /** Whether the tag has the name and the class. */
    public function isNameClass(Tag|string $name, string $cls): bool
    {
        return $this->is(Query::by($name)->hasClass($cls));
    }

    /** Whether the tag has the name, the class and the inner HTML. */
    public function isNameClassBody(Tag|string $name, string $cls, string $body): bool
    {
        return $this->is(Query::by($name)->hasClass($cls)->body($body));
    }

    /** Whether the tag has the name, and the class, id and inner HTML when they are not empty. */
    public function isNameClassIdBody(Tag|string $name, string $cls, string $id, string $body): bool
    {
        return $this->is(Query::optBody(Query::optId(Query::optClass(Query::by($name), $cls), $id), $body));
    }

    /** Whether the tag has the name, and the class and visible text when they are not empty. */
    public function isNameClassText(Tag|string $name, string $cls, string $text): bool
    {
        return $this->is(Query::optText(Query::optClass(Query::by($name), $cls), $text));
    }

    /** Whether the tag has the name, and the id and visible text when they are not empty. */
    public function isNameIdText(Tag|string $name, string $id, string $text): bool
    {
        return $this->is(Query::optText(Query::optId(Query::by($name), $id), $text));
    }

    /** Whether the tag has the name and the id. */
    public function isNameId(Tag|string $name, string $id): bool
    {
        return $this->is(Query::by($name)->id($id));
    }

    /** Whether the tag has the name and its visible text starts with the prefix. */
    public function isNameTextStartsWith(Tag|string $name, string $textPrefix): bool
    {
        return $this->is(Query::by($name)->textStartsWith($textPrefix));
    }

    /** Whether the tag has the name and its inner HTML starts with the prefix. */
    public function isNameBodyStartsWith(Tag|string $name, string $bodyPrefix): bool
    {
        return $this->is(Query::by($name)->bodyStartsWith($bodyPrefix));
    }

    /** Whether the tag has the name and the class and its visible text starts with the prefix. */
    public function isNameClassTextStartsWith(Tag|string $name, string $cls, string $textPrefix): bool
    {
        return $this->is(Query::by($name)->hasClass($cls)->textStartsWith($textPrefix));
    }

    /** Whether the tag has the name and its id starts with the prefix. */
    public function isNameIdStartsWith(Tag|string $name, string $idPrefix): bool
    {
        return $this->is(Query::by($name)->idStartsWith($idPrefix));
    }

    /** Whether the tag has the name and the itemprop. */
    public function isNameItemprop(Tag|string $name, string $itemprop): bool
    {
        return $this->is(Query::by($name)->attr(Attr::ITEMPROP, $itemprop));
    }

    /** Whether the tag has the name and its opening tag (without '<' and '>') equals source. */
    public function isNameSource(Tag|string $name, string $source): bool
    {
        return $this->is(Query::by($name)->source($source));
    }

    /** Returns the nearest enclosing tag with the name, or null. */
    public function parentByName(Tag|string $name): ?HtmlNode
    {
        return $this->parentMatching(Query::by($name));
    }

    /** Returns the nearest enclosing tag with the name and the class, or null. */
    public function parentByNameClass(Tag|string $name, string $cls): ?HtmlNode
    {
        return $this->parentMatching(Query::by($name)->hasClass($cls));
    }

    /** Returns the first tag with the name after the end of this one, or null. */
    public function nextByName(Tag|string $name): ?HtmlNode
    {
        return $this->nextMatching(Query::by($name));
    }

    /** Returns the first tag with the name and the class after the end of this one, or null. */
    public function nextByNameClass(Tag|string $name, string $cls): ?HtmlNode
    {
        return $this->nextMatching(Query::by($name)->hasClass($cls));
    }

    /** Returns the next sibling with the name, or null. */
    public function nextSiblingByName(Tag|string $name): ?HtmlNode
    {
        return $this->nextSiblingMatching(Query::by($name));
    }

    /** Returns the next sibling with the name and the class, or null. */
    public function nextSiblingByNameClass(Tag|string $name, string $cls): ?HtmlNode
    {
        return $this->nextSiblingMatching(Query::by($name)->hasClass($cls));
    }

    /** Returns the nearest previous sibling with the name, or null. */
    public function previousSiblingByName(Tag|string $name): ?HtmlNode
    {
        return $this->previousSiblingMatching(Query::by($name));
    }

    /** Returns the source between the end of this tag and the next tag with the name. */
    public function textAfterUntilTagByName(Tag|string $name): string
    {
        return $this->textAfterUntil(Query::by($name));
    }

    /** Returns the source between the end of this tag and the next tag with the name and the class. */
    public function textAfterUntilTagByNameClass(Tag|string $name, string $cls): string
    {
        return $this->textAfterUntil(Query::by($name)->hasClass($cls));
    }

    /** The decoded value of the alt attribute; "" when it is missing. */
    public function attributeAlt(): string
    {
        return $this->attr(Attr::ALT);
    }

    /** The decoded value of the class attribute; "" when it is missing. */
    public function attributeClass(): string
    {
        return $this->attr(Attr::CLASS_);
    }

    /** The decoded value of the content attribute; "" when it is missing. */
    public function attributeContent(): string
    {
        return $this->attr(Attr::CONTENT);
    }

    /** The decoded value of the id attribute; "" when it is missing. */
    public function attributeId(): string
    {
        return $this->attr(Attr::ID);
    }

    /** The decoded value of the itemprop attribute; "" when it is missing. */
    public function attributeItemProp(): string
    {
        return $this->attr(Attr::ITEMPROP);
    }

    /** The decoded value of the name attribute; "" when it is missing. */
    public function attributeName(): string
    {
        return $this->attr(Attr::NAME);
    }

    /** The decoded value of the style attribute; "" when it is missing. */
    public function attributeStyle(): string
    {
        return $this->attr(Attr::STYLE);
    }

    /** The decoded value of the title attribute; "" when it is missing. */
    public function attributeTitle(): string
    {
        return $this->attr(Attr::TITLE);
    }

    /** The decoded value of the value attribute; "" when it is missing. */
    public function attributeValue(): string
    {
        return $this->attr(Attr::VALUE);
    }
    // </shortcuts node>
}
