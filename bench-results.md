every scraper returns the same records

PHP 8.4.27 (Windows, JIT on); median time per call

Parse a page:

| Page | fasthtmlparser | Dom\HTMLDocument | DOMDocument | DomCrawler | simple_html_dom |
|---|---:|---:|---:|---:|---:|
| quotes, 11 KB | 153 μs | 257 μs | 443 μs | 840 μs | 584 μs |
| laptops, 38 KB | 418 μs | 754 μs | 1.34 ms | 2.61 ms | 2.67 ms |
| books, 51 KB | 560 μs | 765 μs | 1.45 ms | 2.33 ms | 3.26 ms |
| books x10, 510 KB | 4.90 ms | 9.62 ms | 15.46 ms | 26.89 ms | 49.53 ms |
| books without `</li>` `</p>` | 533 μs | 766 μs | 1.48 ms | 2.41 ms | 2.24 ms |

Scrape a page (parse + extract the records):

| Task | Time |
|---|---:|
| books, fasthtmlparser find | 680 μs |
| books, fasthtmlparser CSS | 844 μs |
| books, fasthtmlparser XPath | 944 μs |
| books, Dom\HTMLDocument CSS | 1.08 ms |
| books, Dom\HTMLDocument XPath | 1.20 ms |
| books, DOMDocument + DOMXPath | 2.01 ms |
| books, DomCrawler CSS | 4.57 ms |
| books, DomCrawler XPath | 3.18 ms |
| books, simple_html_dom | 2.86 ms |
| quotes, fasthtmlparser find | 273 μs |
| quotes, fasthtmlparser CSS | 330 μs |
| quotes, Dom\HTMLDocument CSS | 420 μs |
| quotes, DomCrawler CSS | 1.98 ms |
| quotes, simple_html_dom | 965 μs |
