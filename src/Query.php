<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * Selects tags by name and, optionally, class, id, attribute value, visible text, inner HTML and the source of the
 * opening tag. Queries are immutable: every method returns a new one. Start with Query::by(Tag::Div),
 * Query::by('my-widget') or Query::any().
 *
 *     $doc->findAll(Query::by(Tag::Article)->hasClass('product_pod'));
 *     $pod->find(Query::by(Tag::P)->hasClass('price_color'))->text();
 */
final class Query
{
    private static ?Query $any = null;

    private ?string $class = null;
    private ?string $id = null;
    private ?string $attrName = null;
    private string $attrValue = '';
    private ?string $text = null;
    private ?string $idStartsWith = null;
    private ?string $textStartsWith = null;
    private ?string $body = null;
    private ?string $bodyStartsWith = null;
    private ?string $source = null;
    private bool $plain = true;

    /** @param int $key Tag key, or -1: by name ($tagName) or any ($tagName "") */
    private function __construct(private readonly int $key, private readonly string $tagName)
    {
    }

    /** Selects tags by name, case-insensitively; a string works for names that are not in Tag. */
    public static function by(Tag|string $name): self
    {
        if ($name instanceof Tag) {
            return new self(TagNames::KEYS[$name->value], '');
        }
        $t = Parser::lookupTag($name);
        return new self($t, $name);
    }

    /** Selects every tag. */
    public static function any(): self
    {
        return self::$any ??= new self(-1, '');
    }

    /** Adds a class criterion: one of the space-separated classes, case-insensitive. */
    public function hasClass(string $cls): self
    {
        $q = clone $this;
        $q->class = $cls;
        $q->plain = false;
        return $q;
    }

    /** Adds an id criterion. */
    public function id(string $id): self
    {
        $q = clone $this;
        $q->id = $id;
        $q->plain = false;
        return $q;
    }

    /** Adds an attribute criterion: the decoded value equals $value. */
    public function attr(string $name, string $value): self
    {
        $q = clone $this;
        $q->attrName = $name;
        $q->attrValue = $value;
        $q->plain = false;
        return $q;
    }

    /** Adds a visible-text criterion (see HtmlNode::text()). */
    public function text(string $text): self
    {
        $q = clone $this;
        $q->text = $text;
        $q->plain = false;
        return $q;
    }

    /** Adds a criterion: the decoded id starts with $prefix. */
    public function idStartsWith(string $prefix): self
    {
        $q = clone $this;
        $q->idStartsWith = $prefix;
        $q->plain = false;
        return $q;
    }

    /** Adds a criterion: the visible text starts with $prefix. */
    public function textStartsWith(string $prefix): self
    {
        $q = clone $this;
        $q->textStartsWith = $prefix;
        $q->plain = false;
        return $q;
    }

    /** Adds a criterion: the inner HTML (trimmed) equals $body. */
    public function body(string $body): self
    {
        $q = clone $this;
        $q->body = $body;
        $q->plain = false;
        return $q;
    }

    /** Adds a criterion: the inner HTML (trimmed) starts with $prefix. */
    public function bodyStartsWith(string $prefix): self
    {
        $q = clone $this;
        $q->bodyStartsWith = $prefix;
        $q->plain = false;
        return $q;
    }

    /** Adds a criterion: the opening tag without '<' and '>' (see HtmlNode::source()) equals $source. */
    public function source(string $source): self
    {
        $q = clone $this;
        $q->source = $source;
        $q->plain = false;
        return $q;
    }

    /** Whether the tag matches every criterion; false for null. */
    public function matches(?HtmlNode $n): bool
    {
        if ($n === null) {
            return false;
        }
        if ($this->key >= 0) {
            if ($n->key !== $this->key && ($n->key >= 0 || $this->tagName === '' || !Chars::equalFold($n->custom, $this->tagName))) {
                return false;
            }
        } elseif ($this->tagName !== '' && !Chars::equalFold($n->name(), $this->tagName)) {
            return false;
        }
        if ($this->plain) {
            return true;
        }
        if ($this->id !== null && ($this->id === '' || !Attrs::attrEquals(Attrs::attrRaw($n, 'id'), $this->id))) {
            return false;
        }
        if ($this->attrName !== null && !Attrs::attrEquals(Attrs::attrRaw($n, $this->attrName), $this->attrValue)) {
            return false;
        }
        if ($this->class !== null && !Attrs::hasClass($n, $this->class)) {
            return false;
        }
        if ($this->idStartsWith !== null && !Attrs::attrStartsWith(Attrs::attrRaw($n, 'id'), $this->idStartsWith)) {
            return false;
        }
        if ($this->source !== null && $n->source() !== $this->source) {
            return false;
        }
        if ($this->body !== null && $n->body() !== $this->body) {
            return false;
        }
        if ($this->bodyStartsWith !== null && !str_starts_with($n->body(), $this->bodyStartsWith)) {
            return false;
        }
        // the text last: it is the most expensive check
        if ($this->text !== null && !Text::isText($n->body(), $this->text, $n)) {
            return false;
        }
        if ($this->textStartsWith !== null && !str_starts_with($n->text(), $this->textStartsWith)) {
            return false;
        }
        return true;
    }

    /** @internal The older "optional" shortcuts skip an empty criterion. */
    public static function optClass(self $q, string $cls): self
    {
        return $cls === '' ? $q : $q->hasClass($cls);
    }

    /** @internal */
    public static function optId(self $q, string $id): self
    {
        return $id === '' ? $q : $q->id($id);
    }

    /** @internal */
    public static function optText(self $q, string $text): self
    {
        return $text === '' ? $q : $q->text($text);
    }

    /** @internal */
    public static function optBody(self $q, string $body): self
    {
        return $body === '' ? $q : $q->body($body);
    }
}
