<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * @internal A node of the XPath data model over the flat tag list: the root, an element, a comment, a text node (a run
 * of source text between tags) or an attribute (positions in the opening tag).
 */
final class XNode
{
    public const ROOT = 0;
    public const ELEMENT = 1;
    public const COMMENT = 2;
    public const TEXT = 3;
    public const ATTRIBUTE = 4;

    /** document order and identity: tags and text nodes by their first byte; attributes right after their element */
    public readonly int $major;
    public readonly int $minor;

    /**
     * @param ?HtmlNode $tag element / comment: the tag; text / attribute: the parent element (null: the root)
     * @param int $start text: first byte; attribute: name start
     * @param int $length text: length; attribute: name length
     * @param int $valueStart attribute: value start (-1 for no value)
     */
    public function __construct(
        public readonly int $kind,
        public readonly HtmlDocument $doc,
        public readonly ?HtmlNode $tag,
        public readonly int $start = 0,
        public readonly int $length = 0,
        public readonly int $valueStart = 0,
        public readonly int $valueLength = 0,
    ) {
        $this->major = match ($kind) {
            self::ROOT => -1,
            self::TEXT => $start,
            default => $tag->start,
        };
        $this->minor = $kind === self::ATTRIBUTE ? $start : 0;
    }

    public static function root(HtmlDocument $d): self
    {
        return new self(self::ROOT, $d, null);
    }

    public static function ofTag(HtmlNode $n): self
    {
        return new self($n->key === Parser::COMMENT ? self::COMMENT : self::ELEMENT, $n->doc, $n);
    }

    /** The first source position the node covers (for following / preceding). */
    public function rangeStart(): int
    {
        return $this->kind === self::ROOT ? -1 : $this->major;
    }

    /** The last source position the node covers. */
    public function rangeEnd(): int
    {
        return match ($this->kind) {
            self::ROOT => PHP_INT_MAX,
            self::ELEMENT, self::COMMENT => $this->tag->end,
            self::TEXT => $this->start + $this->length - 1,
            default => $this->tag->start, // attribute: the children of its element follow it
        };
    }

    public function attrName(): string
    {
        return substr($this->doc->source, $this->start, $this->length);
    }
}
