<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/** Helpers for HTML fragments and lists of tags. */
final class Html
{
    private function __construct()
    {
    }

    /** The visible text of an HTML fragment, as HtmlNode::text() gives it for a tag's content. */
    public static function toText(string $html): string
    {
        return Text::clearTags($html);
    }

    /** The text of an HTML fragment with the visible line structure kept, as HtmlNode::textLineBreaks(). */
    public static function toTextLineBreaks(string $html): string
    {
        return TextLines::clearTagsLineBreaks($html);
    }

    /** Decodes the HTML character references in text (&amp;amp;, &amp;#169;, ...); CR LF and a lone CR become LF. */
    public static function decode(string $text): string
    {
        return Decode::decode($text, false);
    }

    /**
     * The tags of the list at the level of the first one (the outermost tags of a findAll() result).
     *
     * @param list<HtmlNode> $nodes
     * @return list<HtmlNode>
     */
    public static function firstLevel(array $nodes): array
    {
        return self::level($nodes, null);
    }

    /**
     * The tags of the list with the name, at the level of the first tag of the list.
     *
     * @param list<HtmlNode> $nodes
     * @return list<HtmlNode>
     */
    public static function firstLevelByName(array $nodes, Tag|string $name): array
    {
        return self::level($nodes, Query::by($name));
    }

    /**
     * The tags of the list with the name and the class, at the level of the first tag of the list.
     *
     * @param list<HtmlNode> $nodes
     * @return list<HtmlNode>
     */
    public static function firstLevelByNameClass(array $nodes, Tag|string $name, string $cls): array
    {
        return self::level($nodes, Query::by($name)->hasClass($cls));
    }

    private static function level(array $nodes, ?Query $q): array
    {
        $out = [];
        if ($nodes === []) {
            return $out;
        }
        $depth = $nodes[0]->depth;
        foreach ($nodes as $n) {
            if ($n->depth === $depth && ($q === null || $q->matches($n))) {
                $out[] = $n;
            }
        }
        return $out;
    }
}
