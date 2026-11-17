<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * @internal Reads the attributes of an opening tag one by one: positions in the source.
 */
final class AttrScanner
{
    public readonly string $src;
    public readonly int $end;
    public int $pos;
    public int $nameStart = 0;
    public int $nameLength = 0;
    public int $valueStart = -1; // -1: no value
    public int $valueLength = 0;

    public function __construct(HtmlNode $n)
    {
        $src = $n->doc->source;
        $end = Attrs::openTagEnd($n);
        $i = $n->start + 1; // skip '<' and the name
        if ($i < $end) {
            $i += strcspn($src, " \t\n\x0b\x0c\r/>", $i, $end - $i);
        }
        $this->src = $src;
        $this->end = $end;
        $this->pos = $i;
    }

    /** Moves to the next attribute; false at the end of the tag. */
    public function next(): bool
    {
        $s = $this->src;
        $end = $this->end;
        for (;;) {
            if ($this->pos < $end) {
                $this->pos += strspn($s, " \t\n\x0b\x0c\r/", $this->pos, $end - $this->pos);
            }
            if ($this->pos >= $end) {
                return false;
            }
            $this->nameStart = $this->pos;
            $this->pos += strcspn($s, " \t\n\x0b\x0c\r=/>", $this->pos, $end - $this->pos);
            $this->nameLength = $this->pos - $this->nameStart;
            if ($this->nameLength === 0) {
                $this->pos++; // a stray '=': skip it
                continue;
            }
            Attrs::readValue($s, $this->pos, $end);
            $this->valueStart = Attrs::$valueStart;
            $this->valueLength = Attrs::$valueLength;
            $this->pos = Attrs::$valueNext;
            return true;
        }
    }

    public function name(): string
    {
        return substr($this->src, $this->nameStart, $this->nameLength);
    }

    public function rawValue(): string
    {
        return $this->valueStart < 0 ? '' : substr($this->src, $this->valueStart, $this->valueLength);
    }
}
