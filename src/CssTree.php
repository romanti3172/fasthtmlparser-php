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

    /** @param list<HtmlNode> $nodes */
    public function __construct(private readonly array $nodes)
    {
        $count = count($nodes);
        $previous = $count > 0 ? array_fill(0, $count, -1) : [];
        $next = $previous;
        $position = $count > 0 ? array_fill(0, $count, 0) : [];
        $fromEnd = $position;
        // per parent (slot = parent index + 1, slot 0: the top level): its last element child so far and their number
        $lastChild = [];
        $children = [];
        for ($i = 0; $i < $count; $i++) {
            if (!CssQuery::isElement($nodes[$i])) {
                continue;
            }
            $slot = $nodes[$i]->parentIndex + 1;
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
                $fromEnd[$i] = $children[$nodes[$i]->parentIndex + 1] - $position[$i] + 1;
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
        return $i < 0 ? null : $this->nodes[$i];
    }

    public function nextOf(HtmlNode $n): ?HtmlNode
    {
        $i = $this->next[$n->index];
        return $i < 0 ? null : $this->nodes[$i];
    }

    /** @return array{list<int>, list<int>} */
    public function typePositions(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }
        $count = count($this->nodes);
        $pos = $count > 0 ? array_fill(0, $count, 0) : [];
        $end = $pos;
        $seen = [];
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            if ($this->position[$i] === 0) {
                continue; // not an element
            }
            $n = $this->nodes[$i];
            $k = $n->parentIndex . '|' . $n->key . '|' . ($n->key < 0 ? $n->custom : '');
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
