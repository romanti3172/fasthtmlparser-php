<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * @internal A parsed URL (net/url's URL); every string is bytes.
 */
final class Url
{
    public string $scheme = '';
    public string $opaque = '';
    public ?string $user = null; // the userinfo as written back (escaped), or null
    public string $host = '';
    public string $path = ''; // decoded
    public string $rawPath = ''; // the path as written, when it differs from the default escaping of path
    public bool $forceQuery = false;
    public string $rawQuery = '';
    public string $fragment = ''; // decoded
    public string $rawFragment = '';
}
