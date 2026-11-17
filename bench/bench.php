<?php

// Benchmarks of fasthtmlparser against other PHP HTML parsers: parsing pages, and scraping records
// (parse + extract). Every scraper is first checked to return exactly the same records.
//
//   composer install        (in this folder)
//   php bench.php [seconds per measurement, default 2]
//
// The library itself is loaded from ../src, so the benchmark always runs the working copy.

declare(strict_types=1);

// simple_html_dom 2.0-RC2 passes null to trim() on PHP 8.4
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Z3l\\FastHtmlParser\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use Symfony\Component\DomCrawler\Crawler;
use Z3l\FastHtmlParser\HtmlDocument;
use Z3l\FastHtmlParser\HtmlNode;
use Z3l\FastHtmlParser\Query;
use Z3l\FastHtmlParser\Tag;

function read(string $name): string
{
    return file_get_contents(__DIR__ . '/../testdata/' . $name);
}

/** Median time per call in microseconds: batches of calls for about $seconds, after a one-second warm-up. */
function measure(callable $fn, float $seconds): float
{
    $batch = 1;
    for (;;) {
        $t = hrtime(true);
        for ($i = 0; $i < $batch; $i++) {
            $fn();
        }
        if (hrtime(true) - $t > 20_000_000) {
            break;
        }
        $batch *= 2;
    }
    $until = hrtime(true) + 1_000_000_000;
    while (hrtime(true) < $until) {
        $fn(); // warm-up: the JIT compiles the hot paths
    }
    gc_collect_cycles();
    $samples = [];
    $end = hrtime(true) + (int) ($seconds * 1e9);
    while (hrtime(true) < $end) {
        $t = hrtime(true);
        for ($i = 0; $i < $batch; $i++) {
            $fn();
        }
        $samples[] = (hrtime(true) - $t) / 1000 / $batch;
    }
    sort($samples);
    return $samples[intdiv(count($samples), 2)];
}

function fmt(float $us): string
{
    return $us >= 1000 ? sprintf('%.2f ms', $us / 1000) : sprintf('%s μs', number_format($us));
}

function lastWord(string $s): string
{
    $i = strrpos($s, ' ');
    return $i === false ? $s : substr($s, $i + 1);
}

// ----- scrapers: each gives [title, price, rating] for books and [text, author, tags] for quotes -----

/** @return array<string, callable(string): list<list<string>>> */
function bookScrapers(): array
{
    return [
        'fasthtmlparser find' => static fn (string $page): array => array_map(static fn (HtmlNode $pod): array => [
            $pod->find(Query::by(Tag::H3))->find(Query::by(Tag::A))->attr('title'),
            $pod->find(Query::by(Tag::P)->hasClass('price_color'))->text(),
            lastWord($pod->find(Query::by(Tag::P)->hasClass('star-rating'))->attr('class')),
        ], HtmlDocument::parse($page)->findAll(Query::by(Tag::Article)->hasClass('product_pod'))),
        'fasthtmlparser CSS' => static fn (string $page): array => array_map(static fn (HtmlNode $pod): array => [
            $pod->cssFirst('h3 a')->attr('title'),
            $pod->cssFirst('p.price_color')->text(),
            lastWord($pod->cssFirst('p.star-rating')->attr('class')),
        ], HtmlDocument::parse($page)->css('article.product_pod')),
        'fasthtmlparser XPath' => static fn (string $page): array => array_map(static fn (HtmlNode $pod): array => [
            $pod->xpathValue('.//h3/a/@title'),
            $pod->xpathValue(".//p[@class='price_color']"),
            lastWord($pod->xpathValue(".//p[contains(@class,'star-rating')]/@class")),
        ], HtmlDocument::parse($page)->xpath("//article[@class='product_pod']")),
        'Dom\HTMLDocument CSS' => static function (string $page): array {
            $out = [];
            foreach (Dom\HTMLDocument::createFromString($page, LIBXML_NOERROR)->querySelectorAll('article.product_pod') as $pod) {
                $out[] = [
                    $pod->querySelector('h3 a')->getAttribute('title'),
                    trim($pod->querySelector('p.price_color')->textContent),
                    lastWord($pod->querySelector('p.star-rating')->getAttribute('class')),
                ];
            }
            return $out;
        },
        'Dom\HTMLDocument XPath' => static function (string $page): array {
            $doc = Dom\HTMLDocument::createFromString($page, LIBXML_NOERROR | Dom\HTML_NO_DEFAULT_NS);
            $xpath = new Dom\XPath($doc);
            $out = [];
            foreach ($xpath->query("//article[@class='product_pod']") as $pod) {
                $out[] = [
                    $xpath->evaluate('string(.//h3/a/@title)', $pod),
                    trim($xpath->evaluate("string(.//p[@class='price_color'])", $pod)),
                    lastWord($xpath->evaluate("string(.//p[contains(@class,'star-rating')]/@class)", $pod)),
                ];
            }
            return $out;
        },
        'DOMDocument + DOMXPath' => static function (string $page): array {
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="UTF-8">' . $page, LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($doc);
            $out = [];
            foreach ($xpath->query("//article[@class='product_pod']") as $pod) {
                $out[] = [
                    $xpath->evaluate('string(.//h3/a/@title)', $pod),
                    trim($xpath->evaluate("string(.//p[@class='price_color'])", $pod)),
                    lastWord($xpath->evaluate("string(.//p[contains(@class,'star-rating')]/@class)", $pod)),
                ];
            }
            return $out;
        },
        'DomCrawler CSS' => static fn (string $page): array => (new Crawler($page))->filter('article.product_pod')->each(
            static fn (Crawler $pod): array => [
                $pod->filter('h3 a')->attr('title'),
                $pod->filter('p.price_color')->text(),
                lastWord($pod->filter('p.star-rating')->attr('class')),
            ]),
        'DomCrawler XPath' => static fn (string $page): array => (new Crawler($page))->filterXPath("//article[@class='product_pod']")->each(
            static fn (Crawler $pod): array => [
                $pod->filterXPath('.//h3/a')->attr('title'),
                $pod->filterXPath(".//p[@class='price_color']")->text(),
                lastWord($pod->filterXPath(".//p[contains(@class,'star-rating')]")->attr('class')),
            ]),
        'simple_html_dom' => static function (string $page): array {
            $doc = (new simplehtmldom\HtmlDocument())->load($page);
            $out = [];
            foreach ($doc->find('article.product_pod') as $pod) {
                // attribute values come as written: the entities are decoded here
                $out[] = [
                    html_entity_decode($pod->find('h3 a', 0)->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    trim($pod->find('p.price_color', 0)->plaintext),
                    lastWord($pod->find('p.star-rating', 0)->class),
                ];
            }
            $doc->clear();
            return $out;
        },
    ];
}

/** @return array<string, callable(string): list<list<string>>> */
function quoteScrapers(): array
{
    return [
        'fasthtmlparser find' => static fn (string $page): array => array_map(static fn (HtmlNode $q): array => [
            $q->find(Query::by(Tag::Span)->hasClass('text'))->text(),
            $q->find(Query::by(Tag::Small)->hasClass('author'))->text(),
            implode(',', array_map(static fn (HtmlNode $a): string => $a->text(), $q->findAll(Query::by(Tag::A)->hasClass('tag')))),
        ], HtmlDocument::parse($page)->findAll(Query::by(Tag::Div)->hasClass('quote'))),
        'fasthtmlparser CSS' => static fn (string $page): array => array_map(static fn (HtmlNode $q): array => [
            $q->cssFirst('span.text')->text(),
            $q->cssFirst('small.author')->text(),
            implode(',', array_map(static fn (HtmlNode $a): string => $a->text(), $q->css('a.tag'))),
        ], HtmlDocument::parse($page)->css('div.quote')),
        'Dom\HTMLDocument CSS' => static function (string $page): array {
            $out = [];
            foreach (Dom\HTMLDocument::createFromString($page, LIBXML_NOERROR)->querySelectorAll('div.quote') as $q) {
                $tags = [];
                foreach ($q->querySelectorAll('a.tag') as $a) {
                    $tags[] = trim($a->textContent);
                }
                $out[] = [trim($q->querySelector('span.text')->textContent), trim($q->querySelector('small.author')->textContent), implode(',', $tags)];
            }
            return $out;
        },
        'DomCrawler CSS' => static fn (string $page): array => (new Crawler($page))->filter('div.quote')->each(
            static fn (Crawler $q): array => [
                $q->filter('span.text')->text(),
                $q->filter('small.author')->text(),
                implode(',', $q->filter('a.tag')->each(static fn (Crawler $a): string => $a->text())),
            ]),
        'simple_html_dom' => static function (string $page): array {
            $doc = (new simplehtmldom\HtmlDocument())->load($page);
            $out = [];
            foreach ($doc->find('div.quote') as $q) {
                $tags = [];
                foreach ($q->find('a.tag') as $a) {
                    $tags[] = trim($a->plaintext);
                }
                $out[] = [trim($q->find('span.text', 0)->plaintext), trim($q->find('small.author', 0)->plaintext), implode(',', $tags)];
            }
            $doc->clear();
            return $out;
        },
    ];
}

// ----- parsers -----

/** @return array<string, callable(string): mixed> */
function parsers(): array
{
    return [
        'fasthtmlparser' => static fn (string $p): HtmlDocument => HtmlDocument::parse($p),
        'Dom\HTMLDocument' => static fn (string $p): Dom\HTMLDocument => Dom\HTMLDocument::createFromString($p, LIBXML_NOERROR),
        'DOMDocument' => static function (string $p): DOMDocument {
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="UTF-8">' . $p, LIBXML_NOERROR | LIBXML_NOWARNING);
            return $doc;
        },
        'DomCrawler' => static fn (string $p): Crawler => new Crawler($p),
        'simple_html_dom' => static function (string $p): int {
            $doc = (new simplehtmldom\HtmlDocument())->load($p);
            $n = count($doc->nodes);
            $doc->clear();
            return $n;
        },
    ];
}

function main(array $argv): void
{
    $books = read('books/index.html');
    $quotes = read('quotes/page1.html');
    $laptops = read('webscraper/laptops.html');
    $pages = [
        ['quotes, 11 KB', $quotes],
        ['laptops, 38 KB', $laptops],
        ['books, 51 KB', $books],
        ['books x10, 510 KB', str_repeat($books, 10)],
        ['books without `</li>` `</p>`', str_replace(['</li>', '</p>'], '', $books)],
    ];
    $seconds = isset($argv[1]) ? (float) $argv[1] : 2.0;

    // the same records from every scraper
    $scrapers = ['books' => bookScrapers(), 'quotes' => quoteScrapers()];
    $pageOf = ['books' => $books, 'quotes' => $quotes];
    foreach ($scrapers as $task => $list) {
        $expected = $list['fasthtmlparser find']($pageOf[$task]);
        $want = $task === 'books' ? 20 : 10;
        if (count($expected) !== $want) {
            throw new RuntimeException("$task: " . count($expected) . ' records');
        }
        foreach ($list as $name => $scrape) {
            $got = $scrape($pageOf[$task]);
            if ($got !== $expected) {
                throw new RuntimeException("$task: $name gives\n" . json_encode($got, JSON_UNESCAPED_UNICODE)
                    . "\ninstead of\n" . json_encode($expected, JSON_UNESCAPED_UNICODE));
            }
        }
    }
    echo "every scraper returns the same records\n\n";

    $jit = function_exists('opcache_get_status') ? (opcache_get_status(false)['jit']['on'] ?? false) : false;
    printf("PHP %s (%s, JIT %s); median time per call\n\n", PHP_VERSION, PHP_OS_FAMILY, $jit ? 'on' : 'off');
    echo "Parse a page:\n\n";
    $parsers = parsers();
    echo '| Page | ' . implode(' | ', array_keys($parsers)) . " |\n";
    echo '|---|' . implode('|', array_fill(0, count($parsers), '---:')) . "|\n";
    foreach ($pages as [$label, $page]) {
        $cells = [];
        foreach ($parsers as $parse) {
            $cells[] = fmt(measure(static fn () => $parse($page), $seconds));
        }
        echo "| $label | " . implode(' | ', $cells) . " |\n";
    }

    echo "\nScrape a page (parse + extract the records):\n\n";
    echo "| Task | Time |\n|---|---:|\n";
    foreach ($scrapers as $task => $list) {
        foreach ($list as $name => $scrape) {
            echo "| $task, $name | " . fmt(measure(static fn () => $scrape($pageOf[$task]), $seconds)) . " |\n";
        }
    }
}

main($argv);
