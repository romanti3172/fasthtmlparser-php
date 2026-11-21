# fasthtmlparser for PHP

[![CI](https://github.com/romanti3172/fasthtmlparser-php/actions/workflows/ci.yml/badge.svg)](https://github.com/romanti3172/fasthtmlparser-php/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/z3l/fasthtmlparser)](https://packagist.org/packages/z3l/fasthtmlparser)

**Website:** [fasthtmlparser.com](https://fasthtmlparser.com): try the parser on any page, documentation and every language.

PHP port of [z3l.FastHtmlParser](https://github.com/romanti3172/z3l.FastHtmlParser): a fast HTML / XML parser for
scraping, with **XPath 1.0** and **CSS selectors**. It is built for extracting data, not for building a DOM: a page
becomes a flat list of tags that hold only positions in the source. Text, attributes and entities are read when you
ask for them, and the values are substrings of the page.

The whole API is ported: the parser, attributes, HTML5 entities, visible text and text with line breaks,
`find` / `findAll`, navigation, links, XPath 1.0, CSS selectors and the one-call shortcuts. There are also ports for
[Go](https://github.com/romanti3172/fasthtmlparser), [JavaScript](https://github.com/romanti3172/fasthtmlparser-js),
[Rust](https://github.com/romanti3172/fasthtmlparser-rs), [Python](https://github.com/romanti3172/fasthtmlparser-py)
and [Java](https://github.com/romanti3172/fasthtmlparser-java).

```
composer require z3l/fasthtmlparser
```

No dependencies. PHP 8.1 or later. With `ext-mbstring` (installed almost everywhere) names and `lower-case()` /
`upper-case()` fold non-ASCII letters too; without it they fold ASCII only.

```php
use Z3l\FastHtmlParser\{Attr, Html, HtmlDocument, Query, Tag, XPathQuery};

$doc = HtmlDocument::parse($page, 'https://example.com/catalog/'); // UTF-8; a byte order mark is skipped

// CSS selectors, as querySelectorAll / querySelector / matches
foreach ($doc->css('article.product_pod') as $pod) {
    $title = $pod->cssFirst('h3 a')->attr('title');
    $price = $pod->cssFirst('p.price_color')->text();
}
$doc->cssFirst('ul#menu > li:last-child a')->attr('href');
$doc->css('tr:has(> td.price)');
$card->css('> span'); // the children of $card

// XPath 1.0
$doc->xpath("//div[@class='card']//span[@class='price']");
$doc->xpathValues("//a[contains(@href, '/product/')]/@href");
$doc->xpathValue("//dt[.='Price']/following-sibling::dd[1]");
$doc->xpathValue('count(//table//tr)'); // "12"

// compile once, reuse on any number of pages
$titles = XPathQuery::compile('//article//h3/a/@title');
$titles->values($doc);

// search by name, class, id, attribute, text
foreach ($doc->findAll(Query::by(Tag::Article)->hasClass('product_pod')) as $pod) {
    $pod->find(Query::by(Tag::P)->hasClass('price_color'))->text();
}

// one-call shortcuts (findByName*, findAllByName*, isName*, parentByName, nextSiblingByName, attribute*, ...)
$pod = $doc->findByNameClass(Tag::Article, 'product_pod');
$pod->findByNameItemprop(Tag::Span, 'price')->text();
$doc->findByNameText(Tag::Dt, 'Price')->nextSiblingByName(Tag::Dd)->text();
$doc->findByNameClass('my-widget', 'x'); // any name works as a string

// reading a tag
$tag->text();              // visible text, entities decoded
$tag->textLineBreaks();    // <br> and block elements → new line, <p> and headings → paragraph
$tag->attr(Attr::DATA_SRC); // decoded value; attrRaw(): as written; hasAttr(), attrs()
$tag->href();              // absolute URL, resolved against the page URL; src() for src
$tag->hrefMailTo();        // the address of a mailto: link; hrefTelephone() for tel:
$tag->source();            // the opening tag without '<' and '>'
$tag->name(); $tag->tag(); $tag->depth(); $tag->index(); $tag->start(); $tag->end();

// navigation
$tag->parent(); $tag->children(); $tag->nextSibling(); $tag->previousSibling(); $tag->next(); $tag->previous();
$tag->parentMatching(Query::by(Tag::Table));
$tag->textAfterUntil(Query::by(Tag::Br)); // the source between the tag and the next <br>
```

Queries: `Query::by(Tag::Div)`, `Query::by('custom-tag')` or `Query::any()`, then `->hasClass`, `->id`, `->attr`,
`->text`, `->idStartsWith`, `->textStartsWith`, `->body`, `->bodyStartsWith`, `->source`. Queries are immutable.
`Tag` (a string-backed enum: `Tag::Div->value` is `"div"`) and `Attr` (string constants; `Attr::CLASS_` for `class`,
which cannot name a constant) cover about 250 tag and 230 attribute names (HTML, SVG, MathML, RSS, Atom, sitemap);
any other name works as a string. Every method that takes a tag name takes a `Tag` or a `string`.

A missing tag is `null`; a missing value is `""`. The string methods (`css`, `xpath`, ...) compile and cache the query
and throw `CssSelectorException` / `XPathException` (both `InvalidArgumentException`s) on an invalid one; `xpath()` on
an expression that gives a number or string throws an `XPathException` (use `xpathValue`). `Html::toText`,
`Html::toTextLineBreaks` and `Html::decode` work on HTML fragments. A document is a `Countable` and iterable list of
its tags.

Documents are read-only. `CssQuery` and `XPathQuery` are immutable. The tags are kept as arrays of positions; a tag
object (`HtmlNode`) is made the first time a search, a selector or `node($i)` returns it.

CSS: type, `*`, `#id`, `.class`, attribute selectors with all operators and the `i` / `s` flags, the four combinators,
selector lists, `:first-child`, `:last-child`, `:only-child`, `:*-of-type`, `:nth-child(An+B [of S])` and its
relatives, `:not()`, `:is()`, `:where()`, `:has()`, `:empty`, `:root`, `:scope`, `:link`, `:checked`, `:disabled`,
`:enabled`, `:required`, `:optional`, `:contains("text")`. XPath: all axes except namespace, predicates, `|`, the
operators and all XPath 1.0 functions, plus `ends-with()`, `lower-case()`, `upper-case()`. HTML rules apply: names are
case-insensitive, the string-value of an element is its visible text, attribute values and text are decoded.

Parsing follows what a browser makes of the page where it matters for scraping: HTML5 optional end tags (`<li>`, `<p>`,
`<td>`, `<tr>`, `<option>` without end tags give the same elements as a browser), raw text in `<script>`, `<style>`,
`<textarea>`, `<title>`, comments as HTML5 reads them, void elements, nesting flattened past 512 levels, all 2125
named entities, CR LF read as LF. XML documents (RSS, Atom, sitemaps) are parsed by XML rules. Not done: full HTML5
tree construction (no implied `<tbody>` or `<html>`), and documents are read-only.

## Same results as the .NET version

The conformance tests compare the port with z3l.FastHtmlParser 1.4.1 (.NET) on 121 pages: the test fixtures, the
broken-markup snippets of the .NET cross-check tests, extra edge cases (entities, raw text, comments, XML, custom
elements, 600-deep nesting, Unicode whitespace, links of every kind, line breaks), and every fixture with
`</li> </p> </td> </tr>` left out, plus three copies of each with random end tags dropped, names upper-cased and the
page cut off, and copies saved with CR LF and with a lone CR.

- `ConformanceTest::testTagsTextsAttributesNavigationAndLinks`, every tag (over 15,000): names, parents, depths,
  positions, visible text, text with line breaks, 10 attribute values, the opening tag source, next / previous tag and
  sibling, children, the resolved href and src, mailto: and tel: values, and the text after the tag must be identical.
- `ConformanceTest::testXPathAndCssResults`, 569 queries: every string of the .NET XPath and CSS tests plus extra ones
  for the axes, functions and selectors. Whether each one compiles must be the same (119 are invalid); the 228 XPath
  and 222 CSS queries that compile are run on every page, on the document and from three tags of the page, and every
  CSS selector is also matched against every tag. Selected tags and values must be identical.

All pass, with one documented exception: on the page nested 600 levels deep, where the .NET result depends on the
order of an unstable sort.

PHP strings are UTF-8 bytes, so positions (`start()`, `end()`) are **byte offsets** into the source, as in the Go, Rust
and Python ports: `substr($doc->source(), $tag->start(), ...)` works with them. (.NET, Java and JavaScript count UTF-16
units; the conformance data holds byte offsets, so every port is checked against the same numbers.) XPath counts as
.NET does: `string-length()` gives UTF-16 units (an emoji is 2), `substring()` and `translate()` work on characters.
Intended differences: `textLineBreaks()` joins lines with `"\n"` on every OS (.NET uses `Environment.NewLine`), and a
missing value is `""` where .NET returns null. Links resolved against the page are written as .NET's `System.Uri`
writes them (host in lower case, no default port, readable escapes); absolute links are returned as written.

The expected output in `testdata/conformance` is produced by `tools/conformance`, a small .NET program that references
the z3l.FastHtmlParser package; it is shared with the other ports.

## Benchmarks

`cd bench && composer install && php bench.php` (PHP 8.4 with opcache and the tracing JIT, AMD Ryzen 7 3800X, Windows;
median time per call after a one-second warm-up). `Dom\HTMLDocument` is the HTML5 parser built into PHP 8.4 (lexbor);
`DOMDocument` is libxml2's HTML parser; Symfony's DomCrawler 7.4 parses with `Dom\HTMLDocument` on PHP 8.4 and selects
with symfony/css-selector; simple_html_dom is simplehtmldom 2.0-RC2. Before timing, the benchmark checks that every
library extracts exactly the same records.

Parse a page:

| Page | fasthtmlparser | Dom\HTMLDocument | DOMDocument | DomCrawler | simple_html_dom |
|---|---:|---:|---:|---:|---:|
| quotes, 11 KB | **153 μs** | 257 μs | 443 μs | 840 μs | 584 μs |
| laptops, 38 KB | **418 μs** | 754 μs | 1.34 ms | 2.61 ms | 2.67 ms |
| books, 51 KB | **560 μs** | 765 μs | 1.45 ms | 2.33 ms | 3.26 ms |
| books x10, 510 KB | **4.90 ms** | 9.62 ms | 15.46 ms | 26.89 ms | 49.53 ms |
| books without `</li>` `</p>` | **533 μs** | 766 μs | 1.48 ms | 2.41 ms | 2.24 ms |

Scrape a page: parse and extract the records. Books: 20 × title, price, rating; quotes: 10 × text, author, tags.

| Task | fasthtmlparser `find` | CSS | XPath | Dom\HTMLDocument CSS | Dom\XPath | DOMDocument + DOMXPath | DomCrawler CSS | DomCrawler XPath | simple_html_dom |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| books | **680 μs** | 844 μs | 944 μs | 1.08 ms | 1.20 ms | 2.01 ms | 4.57 ms | 3.18 ms | 2.86 ms |
| quotes | **273 μs** | 330 μs |  | 420 μs |  |  | 1.98 ms |  | 965 μs |

The full output is in `bench-results.md`. paquettg/php-html-parser is not included: its last release needs
guzzlehttp/psr7 1.x, which Composer refuses to install because of its security advisories.

## Development

```
composer install
vendor/bin/phpunit                                          # API and conformance tests
php tools/gen.php ../z3l.FastHtmlParser/src/z3l.FastHtmlParser   # tag, attribute and entity tables, shortcuts
cd bench && composer install && php bench.php               # benchmarks (they load the library from ../src)
```

Releases: push a tag `v<version>`; Packagist reads the version from the tag.

- `src/Parser.php`: the scanner and the HTML5 optional-end-tag rules (port of `ParsingCore.cs`); it writes the tags
  into parallel arrays of the document
- `src/Attrs.php`, `src/Decode.php`, `src/Text.php`, `src/TextLines.php`: attributes, character references, visible
  text, text with line breaks
- `src/HtmlDocument.php`, `src/HtmlNode.php`, `src/Query.php`: search, navigation and the generated shortcuts;
  `src/Links.php`: href / src
- `src/CssQuery.php`, `src/CssCompiler.php`: CSS selectors; `src/XPathQuery.php`, `src/XPathCompiler.php`: XPath 1.0
- `src/Tag.php`, `src/TagNames.php`, `src/Attr.php`, `src/Entities.php`: generated by `tools/gen.php` from the C#
  sources
- `bench/`: benchmarks in their own Composer project, so the library has no dependencies

## License

Source-available, not OSI open source: free for individuals and companies with annual revenue below USD 1,000,000
and fewer than 100 people; a one-time commercial license above that. See [LICENSE.md](LICENSE.md).
