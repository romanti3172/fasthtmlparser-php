<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/** An invalid XPath expression, or one used where it cannot be (a number where tags are asked for). */
final class XPathException extends \InvalidArgumentException
{
}
