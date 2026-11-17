<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * @internal Sibling links and positions of the elements, built once per document by the first selector that needs them.
 */
final class CssTree
{
    /** @var list<int> previous element sibling, -1 for none */
    public array $previous;
    /** @var list<int> next element sibling, -1 for none */
    public array $next;
    /** @var list<int> 1-based position among the element siblings; 0: not an element */
    public array $position;
    /** @var list<int> */
    public array $fromEnd;
    /** @var array{list<int>, list<int>}|null {position, fromEnd} among the siblings of the same type */
    private ?array $types = null;

    public function __construct(private readonly HtmlDocument $doc)
    {
        $count = $doc->size;
        $parents = $doc->parents;
        $previous = $count > 0 ? array_fill(0, $count, -1) : [];
        $next = $previous;
        $position = $count > 0 ? array_fill(0, $count, 0) : [];
        $fromEnd = $position;
        // per parent (slot = parent index + 1, slot 0: the top level): its last element child so far and their number
        $lastChild = [];
        $children = [];
        for ($i = 0; $i < $count; $i++) {
            if (!CssQuery::isElementAt($doc, $i)) {
                continue;
            }
            $slot = $parents[$i] + 1;
            $prev = $lastChild[$slot] ?? -1;
            $previous[$i] = $prev;
            if ($prev >= 0) {
                $next[$prev] = $i;
            }
            $lastChild[$slot] = $i;
            $position[$i] = $children[$slot] = ($children[$slot] ?? 0) + 1;
        }
        for ($i = 0; $i < $count; $i++) {
            if ($position[$i] > 0) {
                $fromEnd[$i] = $children[$parents[$i] + 1] - $position[$i] + 1;
            }
        }
        $this->previous = $previous;
        $this->next = $next;
        $this->position = $position;
        $this->fromEnd = $fromEnd;
    }

    public function prev(HtmlNode $n): ?HtmlNode
    {
        $i = $this->previous[$n->index];
        return $i < 0 ? null : $this->doc->at($i);
    }

    public function nextOf(HtmlNode $n): ?HtmlNode
    {
        $i = $this->next[$n->index];
        return $i < 0 ? null : $this->doc->at($i);
    }

    /** @return array{list<int>, list<int>} */
    public function typePositions(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }
        $d = $this->doc;
        $count = $d->size;
        $pos = $count > 0 ? array_fill(0, $count, 0) : [];
        $end = $pos;
        $seen = [];
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            if ($this->position[$i] === 0) {
                continue; // not an element
            }
            $key = $d->keys[$i];
            $k = $d->parents[$i] . '|' . $key . '|' . ($key < 0 ? $d->customs[$i] : '');
            $keys[$i] = $k;
            $pos[$i] = $seen[$k] = ($seen[$k] ?? 0) + 1;
        }
        for ($i = 0; $i < $count; $i++) {
            if ($pos[$i] > 0) {
                $end[$i] = $seen[$keys[$i]] - $pos[$i] + 1;
            }
        }
        return $this->types = [$pos, $end];
    }
}
