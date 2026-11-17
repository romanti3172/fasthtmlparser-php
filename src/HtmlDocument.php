<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * A parsed HTML or XML page: a flat list of tags in document order. Each tag holds only positions in the source; text,
 * attributes and entities are read lazily, when they are asked for, and the values are substrings of the page.
 * Documents are read-only.
 *
 *     $doc = HtmlDocument::parse($html, 'https://example.com/catalog/');
 *     foreach ($doc->css('article.product_pod') as $pod) {
 *         $title = $pod->cssFirst('h3 a')->attr('title');
 *         $price = $pod->findByNameClass(Tag::P, 'price_color')->text();
 *     }
 *     $doc->xpathValue("//dt[.='Price']/following-sibling::dd[1]");
 *
 * @implements \IteratorAggregate<int, HtmlNode>
 */
final class HtmlDocument implements \IteratorAggregate, \Countable
{
    /** @internal */
    public readonly string $source;
    /** @internal */
    public readonly string $url;
    /**
     * @internal
     * @var list<HtmlNode>
     */
    public readonly array $nodes;
    /** @internal sibling links and positions, built by the first selector that needs them */
    public ?CssTree $cssTree = null;
    private ?Url $base = null;
    private bool $baseParsed = false;

    private function __construct(string $source, string $url)
    {
        $this->source = $source;
        $this->url = $url;
        $this->nodes = Parser::parseTags($this, $source);
    }

    /**
     * Parses an HTML or XML page given as a UTF-8 string (a leading byte order mark is skipped). With the page URL,
     * relative links (HtmlNode::href(), HtmlNode::src()) are resolved against it.
     */
    public static function parse(string $html, string $url = ''): self
    {
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            $html = substr($html, 3);
        }
        return new self($html, $url);
    }

    /** @internal the page URL parsed once, for resolving relative links */
    public function base(): ?Url
    {
        if (!$this->baseParsed) {
            $this->base = Links::absoluteUrl($this->url);
            $this->baseParsed = true;
        }
        return $this->base;
    }

    /** The page source. */
    public function source(): string
    {
        return $this->source;
    }

    /** The page URL given to parse(), or "". */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * Every tag (comments included) in document order.
     *
     * @return list<HtmlNode>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** The number of tags (comments included). */
    public function size(): int
    {
        return count($this->nodes);
    }

    public function count(): int
    {
        return count($this->nodes);
    }

    /** The tag at index $i in document order, or null when there is none. */
    public function node(int $i): ?HtmlNode
    {
        return $this->nodes[$i] ?? null;
    }

    /** @return \ArrayIterator<int, HtmlNode> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->nodes);
    }

    // ----- search -----

    /** The first tag in the document that matches the query, or null. */
    public function find(Query $q): ?HtmlNode
    {
        return self::findIn($this->nodes, 0, PHP_INT_MAX, $q);
    }

    /**
     * All tags in the document that match the query.
     *
     * @return list<HtmlNode>
     */
    public function findAll(Query $q): array
    {
        return self::findAllIn($this->nodes, 0, PHP_INT_MAX, $q);
    }

    /**
     * The tags that match a CSS selector (as querySelectorAll). Throws CssSelectorException on an invalid selector.
     *
     * @return list<HtmlNode>
     */
    public function css(string $selector): array
    {
        return CssQuery::cached($selector)->all($this);
    }

    /** The first tag that matches a CSS selector, or null. */
    public function cssFirst(string $selector): ?HtmlNode
    {
        return CssQuery::cached($selector)->first($this);
    }

    /**
     * The tags an XPath expression selects. Throws XPathException on an invalid expression or one that gives
     * a number, string or boolean (use xpathValue()).
     *
     * @return list<HtmlNode>
     */
    public function xpath(string $expression): array
    {
        return XPathQuery::cached($expression)->nodes($this);
    }

    /** The first tag an XPath expression selects, or null. */
    public function xpathFirst(string $expression): ?HtmlNode
    {
        return $this->xpath($expression)[0] ?? null;
    }

    /**
     * The values an XPath expression selects: decoded attributes, text(), visible text of tags, or the result of
     * count(), string(), ... as one value.
     *
     * @return list<string>
     */
    public function xpathValues(string $expression): array
    {
        return XPathQuery::cached($expression)->values($this);
    }

    /** The first value an XPath expression selects, or "". */
    public function xpathValue(string $expression): string
    {
        return XPathQuery::cached($expression)->value($this) ?? '';
    }

    /**
     * @internal
     * @param list<HtmlNode> $nodes
     */
    public static function findIn(array $nodes, int $first, int $end, Query $q): ?HtmlNode
    {
        $count = count($nodes);
        for ($i = $first; $i < $count; $i++) {
            $n = $nodes[$i];
            if ($n->end > $end) {
                break;
            }
            if ($q->matches($n)) {
                return $n;
            }
        }
        return null;
    }

    /**
     * @internal
     * @param list<HtmlNode> $nodes
     * @return list<HtmlNode>
     */
    public static function findAllIn(array $nodes, int $first, int $end, Query $q): array
    {
        $out = [];
        $count = count($nodes);
        for ($i = $first; $i < $count; $i++) {
            $n = $nodes[$i];
            if ($n->end > $end) {
                break;
            }
            if ($q->matches($n)) {
                $out[] = $n;
            }
        }
        return $out;
    }

    // <shortcuts document>
    // Generated by tools/gen.php; do not edit by hand.

    /** Returns the first tag with the id in the document, or null. */
    public function findById(string $id): ?HtmlNode
    {
        if ($id === '') {
            return null;
        }
        return $this->find(Query::any()->id($id));
    }

    /** Returns the first tag with the name in the document, or null. */
    public function findByName(Tag|string $name): ?HtmlNode
    {
        return $this->find(Query::by($name));
    }

    /** Returns the first tag with the name and the inner HTML in the document, or null. */
    public function findByNameBody(Tag|string $name, string $body): ?HtmlNode
    {
        return $this->find(Query::by($name)->body($body));
    }

    /** Returns the first tag with the name and the decoded attribute value in the document, or null. */
    public function findByNameAttribute(Tag|string $name, string $attribute, string $value): ?HtmlNode
    {
        return $this->find(Query::by($name)->attr($attribute, $value));
    }

    /** Returns the first tag with the name and the class in the document, or null. */
    public function findByNameClass(Tag|string $name, string $cls): ?HtmlNode
    {
        return $this->find(Query::by($name)->hasClass($cls));
    }

    /** Returns the first tag with the name, the class and the inner HTML in the document, or null. */
    public function findByNameClassBody(Tag|string $name, string $cls, string $body): ?HtmlNode
    {
        return $this->find(Query::by($name)->hasClass($cls)->body($body));
    }

    /** Returns the first tag with the name, and the class and visible text when they are not empty in the document, or null. */
    public function findByNameClassText(Tag|string $name, string $cls, string $text): ?HtmlNode
    {
        return $this->find(Query::optText(Query::optClass(Query::by($name), $cls), $text));
    }

    /** Returns the first tag with the name, and the id and visible text when they are not empty in the document, or null. */
    public function findByNameIdText(Tag|string $name, string $id, string $text): ?HtmlNode
    {
        return $this->find(Query::optText(Query::optId(Query::by($name), $id), $text));
    }

    /** Returns the first tag with the name and the visible text in the document, or null. */
    public function findByNameText(Tag|string $name, string $text): ?HtmlNode
    {
        return $this->find(Query::by($name)->text($text));
    }

    /** Returns the first tag with the name and the id in the document, or null. */
    public function findByNameId(Tag|string $name, string $id): ?HtmlNode
    {
        return $this->find(Query::by($name)->id($id));
    }

    /** Returns the first tag with the name whose id starts with the prefix in the document, or null. */
    public function findByNameIdStartsWith(Tag|string $name, string $idPrefix): ?HtmlNode
    {
        return $this->find(Query::by($name)->idStartsWith($idPrefix));
    }

    /** Returns the first tag with the name whose inner HTML starts with the prefix in the document, or null. */
    public function findByNameBodyStartsWith(Tag|string $name, string $bodyPrefix): ?HtmlNode
    {
        return $this->find(Query::by($name)->bodyStartsWith($bodyPrefix));
    }

    /** Returns the first tag with the name whose visible text starts with the prefix in the document, or null. */
    public function findByNameTextStartsWith(Tag|string $name, string $textPrefix): ?HtmlNode
    {
        if ($textPrefix === '') {
            return null;
        }
        return $this->find(Query::by($name)->textStartsWith($textPrefix));
    }

    /** Returns the first tag with the name and the class whose visible text starts with the prefix in the document, or null. */
    public function findByNameClassTextStartsWith(Tag|string $name, string $cls, string $textPrefix): ?HtmlNode
    {
        if ($textPrefix === '') {
            return null;
        }
        return $this->find(Query::by($name)->hasClass($cls)->textStartsWith($textPrefix));
    }

    /** Returns the first tag with the name and the itemprop in the document, or null. */
    public function findByNameItemprop(Tag|string $name, string $itemprop): ?HtmlNode
    {
        return $this->find(Query::by($name)->attr(Attr::ITEMPROP, $itemprop));
    }

    /**
     * Returns all tags with the name in the document.
     *
     * @return list<HtmlNode>
     */
    public function findAllByName(Tag|string $name): array
    {
        return $this->findAll(Query::by($name));
    }

    /**
     * Returns all tags with the name and the decoded attribute value in the document.
     *
     * @return list<HtmlNode>
     */
    public function findAllByNameAttribute(Tag|string $name, string $attribute, string $value): array
    {
        return $this->findAll(Query::by($name)->attr($attribute, $value));
    }

    /**
     * Returns all tags with the name and the class in the document.
     *
     * @return list<HtmlNode>
     */
    public function findAllByNameClass(Tag|string $name, string $cls): array
    {
        return $this->findAll(Query::by($name)->hasClass($cls));
    }

    /**
     * Returns all tags with the name and the id in the document.
     *
     * @return list<HtmlNode>
     */
    public function findAllByNameId(Tag|string $name, string $id): array
    {
        return $this->findAll(Query::by($name)->id($id));
    }

    /**
     * Returns all tags with the name whose id starts with the prefix in the document.
     *
     * @return list<HtmlNode>
     */
    public function findAllByNameIdStartsWith(Tag|string $name, string $idPrefix): array
    {
        return $this->findAll(Query::by($name)->idStartsWith($idPrefix));
    }

    /**
     * Returns all tags with the name and the itemprop in the document.
     *
     * @return list<HtmlNode>
     */
    public function findAllByNameItemprop(Tag|string $name, string $itemprop): array
    {
        return $this->findAll(Query::by($name)->attr(Attr::ITEMPROP, $itemprop));
    }

    /**
     * Returns all tags with the name whose inner HTML starts with the prefix in the document.
     *
     * @return list<HtmlNode>
     */
    public function findAllByNameBodyStartsWith(Tag|string $name, string $bodyPrefix): array
    {
        return $this->findAll(Query::by($name)->bodyStartsWith($bodyPrefix));
    }
    // </shortcuts document>
}
