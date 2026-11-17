<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Z3l\FastHtmlParser\CssQuery;
use Z3l\FastHtmlParser\HtmlDocument;
use Z3l\FastHtmlParser\HtmlNode;
use Z3l\FastHtmlParser\XPathQuery;

/**
 * The expected output comes from z3l.FastHtmlParser (.NET) for the same pages (tools/conformance): tag names,
 * parents, depths, positions (as UTF-8 byte offsets), visible texts, attribute values, navigation and links,
 * and the results of 569 XPath / CSS queries. The PHP port must give exactly the same.
 */
final class ConformanceTest extends TestCase
{
    private const DIR = __DIR__ . '/../testdata/conformance/';
    private const PAGE_URL = 'https://Example.com:443/catalog/page-1.html?sort=asc#top'; // as in tools/conformance/Program.cs
    private const ATTRIBUTES = ['class', 'id', 'href', 'title', 'value', 'alt', 'data-a', 'x', 'a', 'src'];

    // page and query where the .NET result depends on the order of an unstable sort (see the Go port):
    // snip039 nests 600 divs; text nodes and flattened tags share document-order keys.
    private const KNOWN_DIFFERENCES = ['snip039 //div/node()' => true];

    /** @return list<string> */
    private static function inputs(): array
    {
        $names = [];
        foreach (glob(self::DIR . 'input/*.html') as $f) {
            $names[] = basename($f, '.html');
        }
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        $out = [];
        foreach (self::inputs() as $name) {
            $out[$name] = [$name];
        }
        return $out;
    }

    private static function json(string $path): mixed
    {
        return json_decode(file_get_contents(self::DIR . $path), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function input(string $name): string
    {
        return file_get_contents(self::DIR . 'input/' . $name . '.html');
    }

    private static function index(?HtmlNode $n): int
    {
        return $n === null ? -1 : $n->index();
    }

    private static function shortText(mixed $v): string
    {
        $s = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return strlen($s) > 200 ? substr($s, 0, 200) . '...' : $s;
    }

    public function testInputsPresent(): void
    {
        $this->assertGreaterThanOrEqual(100, count(self::inputs()));
    }

    #[DataProvider('pages')]
    public function testTagsTextsAttributesNavigationAndLinks(string $name): void
    {
        $source = self::input($name);
        $expected = self::json("expected/$name.json");
        $doc = HtmlDocument::parse($source, self::PAGE_URL);
        $problems = [];
        if ($doc->size() !== count($expected)) {
            $problems[] = 'tags: ' . $doc->size() . ', .NET: ' . count($expected);
        }
        for ($i = 0; $i < min($doc->size(), count($expected)) && count($problems) < 10; $i++) {
            $n = $doc->node($i);
            $e = $expected[$i];
            $got = [
                'name' => $n->name(),
                'parent' => $n->parentIndex,
                'depth' => $n->depth(),
                'start' => $n->start(),
                'end' => $n->end(),
                'innerStart' => $n->innerStart,
                'innerEnd' => $n->innerEnd,
                'text' => $n->text(),
                'textLineBreaks' => $n->textLineBreaks(),
                'source' => $n->source(),
                'next' => self::index($n->next()),
                'previous' => self::index($n->previous()),
                'nextSibling' => self::index($n->nextSibling()),
                'previousSibling' => self::index($n->previousSibling()),
                'children' => implode(',', array_map(static fn (HtmlNode $c): int => $c->index(), $n->children())),
                'href' => $n->href(),
                'src' => $n->src(),
                'mailto' => $n->hrefMailTo(),
                'tel' => $n->hrefTelephone(),
                'textAfter' => $n->textAfterTagUntilNextOne(),
                'textNoTagsAfter' => $n->textNoTagsAfterTagUntilNextOne(),
            ];
            foreach ($got as $key => $value) {
                $want = $e[$key] ?? null;
                if ($value !== $want) {
                    $problems[] = "[$i] <{$n->name()}> $key:\n  php:  " . self::shortText($value) . "\n  .net: " . self::shortText($want);
                }
            }
            $attrs = $e['attrs'];
            foreach (self::ATTRIBUTES as $a) {
                $want = $attrs[$a] ?? '';
                if ($n->attr($a) !== $want) {
                    $problems[] = "[$i] <{$n->name()}> @$a: " . self::shortText($n->attr($a)) . ', .NET: ' . self::shortText($want);
                }
            }
        }
        $this->assertSame([], array_slice($problems, 0, 10), implode("\n", array_slice($problems, 0, 10)));
    }

    public function testWhichQueriesCompile(): void
    {
        $expected = self::json('expected/queries/compile.json');
        $problems = [];
        foreach ($expected['xpath'] as $x => $want) {
            $x = (string) $x;
            if (self::okOrError(static fn () => XPathQuery::compile($x)) !== $want) {
                $problems[] = 'XPath ' . self::shortText($x) . ": .NET $want";
            }
        }
        foreach ($expected['css'] as $c => $want) {
            $c = (string) $c;
            if (self::okOrError(static fn () => CssQuery::compile($c)) !== $want) {
                $problems[] = 'CSS ' . self::shortText($c) . ": .NET $want";
            }
        }
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    private static function okOrError(callable $f): string
    {
        try {
            $f();
            return 'ok';
        } catch (\InvalidArgumentException) {
            return 'error';
        }
    }

    /** [count, hash] of the values, or "throws" when the evaluation throws (.NET throws). */
    private static function digest(callable $values): mixed
    {
        try {
            $v = $values();
        } catch (\InvalidArgumentException) {
            return 'throws';
        }
        if (getenv('FASTHTML_FULL') !== false) {
            return $v;
        }
        return [(string) count($v), substr(hash('sha256', implode("\x01", $v)), 0, 16)];
    }

    /**
     * @param list<HtmlNode> $nodes
     * @return list<string>
     */
    private static function indexes(array $nodes): array
    {
        return array_map(static fn (HtmlNode $n): string => (string) $n->index(), $nodes);
    }

    #[DataProvider('pages')]
    public function testXPathAndCssResults(string $name): void
    {
        $expected = self::json("expected/queries/$name.json");
        $doc = HtmlDocument::parse(self::input($name));
        $scopes = $expected['scopes'];
        $problems = [];

        foreach ($expected['xpath'] as $x => $want) {
            $x = (string) $x;
            if (isset(self::KNOWN_DIFFERENCES["$name $x"])) {
                continue;
            }
            $q = XPathQuery::compile($x);
            $this->check($problems, "XPath tags $x", $want['tags'], self::digest(static fn () => self::indexes($q->nodes($doc))));
            $this->check($problems, "XPath values $x", $want['values'], self::digest(static fn () => $q->values($doc)));
            $this->check($problems, "XPath value $x", $want['value'], self::digest(static fn () => [$q->value($doc) ?? '<null>']));
            $scoped = [];
            foreach ($scopes as $s) {
                $n = $doc->node($s);
                $scoped[] = self::digest(static fn () => [...self::indexes($q->nodesFrom($n)), '|', ...$q->valuesFrom($n)]);
            }
            $this->check($problems, "XPath scoped $x", $want['scoped'], $scoped);
        }

        foreach ($expected['css'] as $c => $want) {
            $c = (string) $c;
            $q = CssQuery::compile($c);
            $this->check($problems, "CSS all $c", $want['all'], self::digest(static fn () => self::indexes($q->all($doc))));
            $this->check($problems, "CSS first $c", $want['first'], self::digest(static function () use ($q, $doc): array {
                $f = $q->first($doc);
                return [$f === null ? '<null>' : (string) $f->index()];
            }));
            $this->check($problems, "CSS matches $c", $want['matches'], self::digest(static function () use ($q, $doc): array {
                $m = [];
                foreach ($doc as $n) {
                    if ($q->matches($n)) {
                        $m[] = $n;
                    }
                }
                return self::indexes($m);
            }));
            $scoped = [];
            foreach ($scopes as $s) {
                $n = $doc->node($s);
                $scoped[] = self::digest(static fn () => self::indexes($q->allIn($n)));
            }
            $this->check($problems, "CSS scoped $c", $want['scoped'], $scoped);
        }
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    private function check(array &$problems, string $what, mixed $want, mixed $got): void
    {
        if (count($problems) < 10 && $got !== $want) {
            $problems[] = "$what\n  php:  " . self::shortText($got) . "\n  .net: " . self::shortText($want);
        }
    }

    public function testNumberToString(): void
    {
        $this->assertSame('0.1', XPathQuery::numberToString(0.1));
        $this->assertSame('100000000000000000000', XPathQuery::numberToString(1e20));
        $this->assertSame('1000000000000000000000', XPathQuery::numberToString(1e21));
        $this->assertSame('0.0000001', XPathQuery::numberToString(1e-7));
        $this->assertSame('-123.456', XPathQuery::numberToString(-123.456));
        $this->assertSame('0.30000000000000004', XPathQuery::numberToString(0.1 + 0.2));
        $this->assertSame('12', XPathQuery::numberToString(12.0));
        $this->assertSame('NaN', XPathQuery::numberToString(NAN));
    }
}
