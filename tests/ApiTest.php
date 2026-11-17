<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser\Tests;

use PHPUnit\Framework\TestCase;
use Z3l\FastHtmlParser\Attr;
use Z3l\FastHtmlParser\CssQuery;
use Z3l\FastHtmlParser\CssSelectorException;
use Z3l\FastHtmlParser\Html;
use Z3l\FastHtmlParser\HtmlDocument;
use Z3l\FastHtmlParser\HtmlNode;
use Z3l\FastHtmlParser\Query;
use Z3l\FastHtmlParser\Tag;
use Z3l\FastHtmlParser\XPathException;
use Z3l\FastHtmlParser\XPathQuery;

final class ApiTest extends TestCase
{
    private static function fixture(string $path): string
    {
        return file_get_contents(__DIR__ . '/../testdata/' . $path);
    }

    // The shortcuts on the books page give what the scraper code of the .NET examples expects.
    public function testShortcuts(): void
    {
        $doc = HtmlDocument::parse(self::fixture('books/index.html'), 'https://books.toscrape.com/index.html');
        $pods = $doc->findAllByNameClass(Tag::Article, 'product_pod');
        $this->assertCount(20, $pods);
        $first = $pods[0];
        $this->assertSame('A Light in the Attic', $first->findByName(Tag::H3)->findByName(Tag::A)->attributeTitle());
        $this->assertSame('£51.77', $first->findByNameClass(Tag::P, 'price_color')->text());
        $this->assertSame('price_color', $first->findByNameClassTextStartsWith(Tag::P, 'price_color', '£5')->attributeClass());
        $this->assertSame('https://books.toscrape.com/catalogue/a-light-in-the-attic_1000/index.html', $first->findByName(Tag::A)->href());
        $this->assertSame('https://books.toscrape.com/media/cache/2c/da/2cdad67c44b002e7ead0cc35693c0e8b.jpg', $first->findByName(Tag::Img)->src());
        $this->assertSame('product_pod', $first->findByName(Tag::Img)->parentByName(Tag::Article)->attributeClass());
        $this->assertSame('product_price', $first->findByName(Tag::H3)->nextSiblingByName(Tag::Div)->attributeClass());
        // names as strings work too
        $this->assertSame('£51.77', $first->findByNameClass('p', 'price_color')->text());

        $this->assertCount(1, Html::firstLevelByName($doc->findAll(Query::any()), Tag::Html));
        $this->assertTrue($first->isNameClass(Tag::Article, 'product_pod'));
        $this->assertFalse($first->isNameClass(Tag::Div, 'product_pod'));
        $this->assertNull($doc->findByNameTextStartsWith(Tag::A, ''), 'an empty criterion of the older shortcuts gives null');
        $this->assertNull($doc->findById(''));
    }

    public function testScrapingWithFindCssAndXPathGivesTheSameRecords(): void
    {
        $doc = HtmlDocument::parse(self::fixture('books/index.html'));
        $byFind = array_map(static fn (HtmlNode $pod): array => [
            $pod->find(Query::by(Tag::H3))->find(Query::by(Tag::A))->attr(Attr::TITLE),
            $pod->find(Query::by(Tag::P)->hasClass('price_color'))->text(),
            $pod->find(Query::by(Tag::P)->hasClass('star-rating'))->attr(Attr::CLASS_),
        ], $doc->findAll(Query::by(Tag::Article)->hasClass('product_pod')));
        $byCss = array_map(static fn (HtmlNode $pod): array => [
            $pod->cssFirst('h3 a')->attr('title'),
            $pod->cssFirst('p.price_color')->text(),
            $pod->cssFirst('p.star-rating')->attr('class'),
        ], $doc->css('article.product_pod'));
        $byXPath = array_map(static fn (HtmlNode $pod): array => [
            $pod->xpathValue('.//h3/a/@title'),
            $pod->xpathValue(".//p[@class='price_color']"),
            $pod->xpathValue(".//p[contains(@class,'star-rating')]/@class"),
        ], $doc->xpath("//article[@class='product_pod']"));
        $this->assertCount(20, $byFind);
        $this->assertSame($byFind, $byCss);
        $this->assertSame($byFind, $byXPath);
    }

    // CR LF and a lone CR are LF in values, as a browser reads the page; a CR written as a character reference stays.
    public function testLineBreaksAreReadAsInABrowser(): void
    {
        $doc = HtmlDocument::parse("<meta name=\"d\" content=\"one\r\ntwo\rthree&#13;four\">"
            . "<textarea>a\r\nb</textarea><pre>c\r\nd</pre><div class=\"x\r\ny\">t</div>");
        $div = $doc->find(Query::by(Tag::Div));
        $this->assertSame("one\ntwo\nthree\rfour", $doc->find(Query::by(Tag::Meta))->attr('content'));
        $this->assertSame("a\nb", $doc->find(Query::by(Tag::Textarea))->text());
        $this->assertSame("c\nd", $doc->find(Query::by(Tag::Pre))->text());
        $this->assertSame("a\nb", $doc->xpathValue('//textarea/text()'));
        $this->assertSame("one\ntwo\nthree\rfour", $doc->xpathValue('//meta/@content'));
        $this->assertSame("x\r\ny", $div->attrRaw('class'));
        $this->assertTrue($div->hasClass('y'));
        $this->assertTrue($div->is(Query::any()->attr('class', "x\ny")));
        $this->assertCount(1, $doc->css('div[class="x\\a y"]'));
    }

    public function testOptionalEndTagsAsInABrowser(): void
    {
        $doc = HtmlDocument::parse('<ul><li>one<li>two<ul><li>a<li>b</ul><li>three</ul>'
            . '<table><tr><td>1<td>2<tr><td>3</table><p>x<div>y</div>');
        $this->assertSame(['li1', 'li1', 'li3', 'li3', 'li1'],
            array_map(static fn (HtmlNode $n): string => $n->name() . $n->depth(), $doc->css('ul > li')));
        $this->assertSame(['one', "two\na\nb", 'three'],
            array_map(static fn (HtmlNode $n): string => $n->textLineBreaks(), $doc->cssFirst('ul')->css('> li')));
        $this->assertSame([2, 1], array_map(static fn (HtmlNode $n): int => count($n->children()), $doc->css('tr')));
        $this->assertNull($doc->cssFirst('div')->parent(), '<div> ends an open <p>');
    }

    public function testTextEntitiesRawTextAndComments(): void
    {
        $doc = HtmlDocument::parse('<div> a &amp;&nbsp;b <script>var x = "<b>";</script><!-- c --> <i>d</i>&copy2024 </div>'
            . '<title>a &lt; b</title>');
        $this->assertSame("a &\u{a0}b d©2024", $doc->cssFirst('div')->text(), '&nbsp; is a no-break space');
        $this->assertSame('a < b', $doc->cssFirst('title')->text());
        $this->assertSame(' c ', $doc->xpathValue('//comment()'));
        $this->assertSame("onetwo\u{a0}three", Html::toText('<p>one</p><p>two&nbsp;three</p>'));
        $this->assertSame("one\n\ntwo\nthree\n\nx y", Html::toTextLineBreaks('<p>one</p><p>two<br>three</p><td>x</td><td>y</td>'));
        $this->assertSame('<AB&bogus;', Html::decode('&lt;&#x41;&#66;&bogus;'));
    }

    public function testAttributes(): void
    {
        $doc = HtmlDocument::parse('<a href=/p?a=1&copy=2 title="see id=5" data-x=\'<b>\' id=main CLASS="One two" hidden>x</a>');
        $a = $doc->cssFirst('a');
        $this->assertSame('/p?a=1&copy=2', $a->attr('href'));
        $this->assertSame('main', $a->attr('id'));
        $this->assertSame('<b>', $a->attr('data-x'));
        $this->assertSame('', $a->attr('hidden'));
        $this->assertSame('', $a->attr('missing'));
        $this->assertTrue($a->hasAttr('hidden'));
        $this->assertFalse($a->hasAttr('missing'));
        $this->assertTrue($a->hasClass('one') && $a->hasClass('TWO') && !$a->hasClass('on'));
        $this->assertSame(['href', 'title', 'data-x', 'id', 'CLASS', 'hidden'], array_column($a->attrs(), 0));
        $this->assertTrue($a->isCss('[hidden]') && $a->isCss('a#main.One'));
        $this->assertSame('a href=/p?a=1&copy=2 title="see id=5" data-x=\'<b>\' id=main CLASS="One two" hidden', $a->source());

        // a quote that is not closed in the tag: the value runs to the end of the tag
        $b = HtmlDocument::parse('<b id=x title="one>two</b>')->node(0);
        $this->assertSame('one', $b->attr('title'));
        $this->assertSame('x', $b->attr('id'));
    }

    public function testLinks(): void
    {
        $doc = HtmlDocument::parse(
            '<a href="../x/y.html?q=1#f">1</a><a href="//cdn.example.com/a b">2</a><a href="">3</a>'
            . '<a href="mailto:me@example.com?subject=hi">4</a><a href="tel:+1 555">5</a><a href="https://Other.org/%7Ea">6</a>',
            'https://Example.com:443/catalog/page/index.html?sort=asc#top');
        $a = $doc->css('a');
        $this->assertSame('https://example.com/catalog/x/y.html?q=1#f', $a[0]->href());
        $this->assertSame('https://cdn.example.com/a b', $a[1]->href());
        $this->assertSame('https://Example.com:443/catalog/page/index.html?sort=asc#top', $a[2]->href());
        $this->assertSame('me@example.com?subject=hi', $a[3]->hrefMailTo());
        $this->assertSame('+1 555', $a[4]->hrefTelephone());
        $this->assertSame('https://Other.org/%7Ea', $a[5]->href(), 'absolute links are returned as written');
        $this->assertSame('http://h.test/a/x/y.html?q=1#f', $a[0]->hrefFrom('http://h.test/a/b/'));
    }

    public function testXPathValues(): void
    {
        $doc = HtmlDocument::parse("<dl><dt>Price</dt><dd>12.50</dd><dt>Stock</dt><dd>3</dd></dl><a href='/x'>a</a><a>b</a>");
        $this->assertSame('12.50', $doc->xpathValue("//dt[.='Price']/following-sibling::dd[1]"));
        $this->assertSame('2', $doc->xpathValue('count(//a)'));
        $this->assertSame('15.5', $doc->xpathValue('sum(//dd)'));
        $this->assertSame(['/x'], $doc->xpathValues('//a/@href'));
        $this->assertSame('', $doc->xpathValue('//missing'));
        $this->assertNull($doc->xpathFirst('//missing'));
        $this->assertSame('true', $doc->xpathValue('boolean(//dl)'));
        // an attribute read as a string is decoded
        $this->assertSame('1', HtmlDocument::parse('<a title="x &amp; y">a</a>')->xpathValue("count(//a[contains(@title, 'x & y')])"));
        $this->expectException(XPathException::class);
        $doc->xpath('count(//a)'); // a number is not tags
    }

    // Positions are byte offsets into the UTF-8 source, as substr() uses them; string-length() counts UTF-16 units
    // and substring() chars, as the .NET version does.
    public function testPositionsAndStringFunctionsOnUnicodeText(): void
    {
        $doc = HtmlDocument::parse('<p>Привет 😀</p><b>x</b>');
        $p = $doc->node(0);
        $b = $doc->node(1);
        $this->assertSame(0, $p->start());
        $this->assertSame(strlen('<p>Привет 😀</p>'), $b->start());
        $this->assertSame('<b>x</b>', substr($doc->source(), $b->start(), $b->end() - $b->start() + 1));
        $this->assertSame('9', $doc->xpathValue('string-length(//p)'), '6 Cyrillic letters, a space and a surrogate pair');
        $this->assertSame('рив', $doc->xpathValue('substring(//p, 2, 3)'));
        $this->assertSame('ПРИВЕТ 😀', $doc->xpathValue('upper-case(//p)'));
        $this->assertSame('Пёвет 😀', $doc->xpathValue("translate(//p, 'ри', 'ё')"));
    }

    public function testInvalidQueries(): void
    {
        $doc = HtmlDocument::parse('<p>x</p>');
        try {
            $doc->css('p >');
            $this->fail('no exception');
        } catch (CssSelectorException $e) {
            $this->assertStringContainsString('"p >"', $e->getMessage());
        }
        try {
            CssQuery::compile('::before');
            $this->fail('no exception');
        } catch (CssSelectorException) {
        }
        try {
            $doc->xpath('//p[');
            $this->fail('no exception');
        } catch (XPathException $e) {
            $this->assertStringContainsString('"//p["', $e->getMessage());
        }
        try {
            XPathQuery::compile('$var');
            $this->fail('no exception');
        } catch (XPathException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $doc->xpath('//p[unknown()]');
    }

    public function testCompiledQueriesAndTheByteOrderMark(): void
    {
        $doc = HtmlDocument::parse("\u{FEFF}<p class=a>é</p><p class=b>x</p>", 'https://h.test/');
        $this->assertStringStartsWith('<p', $doc->source(), 'the byte order mark is skipped');
        $css = CssQuery::compile('p.b');
        $xpath = XPathQuery::compile("//p[@class='a']");
        $this->assertSame('x', $css->first($doc)->text());
        $this->assertSame('é', $xpath->value($doc));
        $this->assertSame(2, $doc->size());
        $this->assertCount(2, $doc);
        $this->assertSame(Tag::P, $doc->node(0)->tag());
        $this->assertSame('p', $doc->node(0)->name());
        $this->assertNull($doc->node(5));
        $count = 0;
        foreach ($doc as $ignored) {
            $count++;
        }
        $this->assertSame(2, $count);
    }

    public function testCustomTagsAndComments(): void
    {
        $doc = HtmlDocument::parse('<my-widget data-id=1><svg:path d=x/></my-widget><!-- note -->');
        $w = $doc->findByName('my-widget');
        $this->assertNull($w->tag());
        $this->assertSame('my-widget', $w->name());
        $this->assertSame('1', $w->attr('data-id'));
        $this->assertCount(1, $doc->css('my-widget'));
        $this->assertSame(Tag::Comment, $doc->node(2)->tag());
        $this->assertSame('!--', $doc->node(2)->name());
        $this->assertSame('', $doc->node(2)->attr('note'), 'a comment has no attributes');
        $this->assertSame('<my-widget data-id=1>', (string) $w);
    }

    // Documents are independent: parsing one page in the middle of working with another changes nothing.
    public function testDocumentsAreIndependent(): void
    {
        $page = self::fixture('books/index.html');
        $titles = static fn (HtmlDocument $d): array => array_map(static fn (HtmlNode $a): string => $a->attr('title'), $d->css('article.product_pod h3 a'));
        $first = HtmlDocument::parse($page);
        $expected = $titles($first);
        for ($i = 0; $i < 5; $i++) {
            HtmlDocument::parse('<ul><li>' . $i . '<li><table><tr><td>x</ul>');
            $this->assertSame($expected, $titles(HtmlDocument::parse($page)));
        }
        $this->assertSame($expected, $titles($first));
    }
}
