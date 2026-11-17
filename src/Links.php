<?php

declare(strict_types=1);

namespace Z3l\FastHtmlParser;

/**
 * @internal Links: href / src made absolute against the page URL, written as .NET's System.Uri writes them.
 *
 * Resolution follows RFC 3986 the way the Go port does it (net/url: Parse + ResolveReference), byte for byte.
 */
final class Links
{
    private const MODE_PATH = 0;
    private const MODE_HOST = 1;
    private const MODE_USER_PASSWORD = 2;
    private const MODE_FRAGMENT = 3;

    private const DEFAULT_PORTS = ['http' => '80', 'https' => '443', 'ftp' => '21', 'ws' => '80', 'wss' => '443'];
    private const RESERVED_FOR_DISPLAY = '%/?#[]@:!$&\'()*+,;=';
    private const UPPER_HEX = '0123456789ABCDEF';

    private function __construct()
    {
    }

    // ----- net/url escaping -----

    private static function shouldEscape(int $c, int $mode): bool
    {
        if (($c >= 0x61 && $c <= 0x7a) || ($c >= 0x41 && $c <= 0x5a) || ($c >= 0x30 && $c <= 0x39)) {
            return false;
        }
        $ch = chr($c);
        if ($mode === self::MODE_HOST && str_contains("!$&'()*+,;=:[]<>\"", $ch)) {
            return false;
        }
        switch ($ch) {
            case '-':
            case '_':
            case '.':
            case '~':
                return false;
            case '$':
            case '&':
            case '+':
            case ',':
            case '/':
            case ':':
            case ';':
            case '=':
            case '?':
            case '@':
                switch ($mode) {
                    case self::MODE_PATH:
                        return $ch === '?';
                    case self::MODE_USER_PASSWORD:
                        return $ch === '@' || $ch === '/' || $ch === '?' || $ch === ':';
                    case self::MODE_FRAGMENT:
                        return false;
                    default:
                        break;
                }
                break;
            default:
                break;
        }
        if ($mode === self::MODE_FRAGMENT && ($ch === '!' || $ch === '(' || $ch === ')' || $ch === '*')) {
            return false;
        }
        return true;
    }

    private static function unhex(string $c): int
    {
        return (int) hexdec($c);
    }

    /** @throws \UnexpectedValueException on a malformed URL */
    private static function unescape(string $s, int $mode): string
    {
        $count = 0;
        $n = strlen($s);
        for ($i = 0; $i < $n;) {
            $c = $s[$i];
            if ($c === '%') {
                $count++;
                if ($i + 2 >= $n || !ctype_xdigit($s[$i + 1]) || !ctype_xdigit($s[$i + 2])) {
                    throw new \UnexpectedValueException('invalid URL escape');
                }
                // in the host, %-encoding is only for non-ASCII bytes (and %25)
                if ($mode === self::MODE_HOST && self::unhex($s[$i + 1]) < 8 && substr($s, $i, 3) !== '%25') {
                    throw new \UnexpectedValueException('invalid URL escape');
                }
                $i += 3;
            } else {
                if ($mode === self::MODE_HOST && ord($c) < 0x80 && self::shouldEscape(ord($c), $mode)) {
                    throw new \UnexpectedValueException('invalid character in host name');
                }
                $i++;
            }
        }
        if ($count === 0) {
            return $s;
        }
        return rawurldecode($s);
    }

    private static function escape(string $s, int $mode): string
    {
        $out = '';
        $from = 0;
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($s[$i]);
            if (self::shouldEscape($c, $mode)) {
                $out .= substr($s, $from, $i - $from) . '%' . self::UPPER_HEX[$c >> 4] . self::UPPER_HEX[$c & 15];
                $from = $i + 1;
            }
        }
        return $from === 0 ? $s : $out . substr($s, $from);
    }

    private static function validEncoded(string $s, int $mode): bool
    {
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $ch = $s[$i];
            if (str_contains("!$&'()*+,;=:@[]%", $ch)) {
                continue;
            }
            if (self::shouldEscape(ord($ch), $mode)) {
                return false;
            }
        }
        return true;
    }

    // ----- parsing -----

    /** @return array{string, string} the scheme and the rest */
    private static function getScheme(string $raw): array
    {
        $n = strlen($raw);
        for ($i = 0; $i < $n; $i++) {
            $c = $raw[$i];
            if (ctype_alpha($c)) {
                continue;
            }
            if (ctype_digit($c) || $c === '+' || $c === '-' || $c === '.') {
                if ($i === 0) {
                    return ['', $raw];
                }
                continue;
            }
            if ($c === ':') {
                if ($i === 0) {
                    throw new \UnexpectedValueException('missing protocol scheme');
                }
                return [substr($raw, 0, $i), substr($raw, $i + 1)];
            }
            return ['', $raw];
        }
        return ['', $raw];
    }

    private static function setPath(Url $u, string $p): void
    {
        $u->path = self::unescape($p, self::MODE_PATH);
        $u->rawPath = self::escape($u->path, self::MODE_PATH) === $p ? '' : $p;
    }

    private static function escapedPath(Url $u): string
    {
        if ($u->rawPath !== '' && self::validEncoded($u->rawPath, self::MODE_PATH)) {
            try {
                if (self::unescape($u->rawPath, self::MODE_PATH) === $u->path) {
                    return $u->rawPath;
                }
            } catch (\UnexpectedValueException) {
                // fall through
            }
        }
        if ($u->path === '*') {
            return '*';
        }
        return self::escape($u->path, self::MODE_PATH);
    }

    private static function escapedFragment(Url $u): string
    {
        if ($u->rawFragment !== '' && self::validEncoded($u->rawFragment, self::MODE_FRAGMENT)) {
            try {
                if (self::unescape($u->rawFragment, self::MODE_FRAGMENT) === $u->fragment) {
                    return $u->rawFragment;
                }
            } catch (\UnexpectedValueException) {
                // fall through
            }
        }
        return self::escape($u->fragment, self::MODE_FRAGMENT);
    }

    private static function validOptionalPort(string $port): bool
    {
        if ($port === '') {
            return true;
        }
        if ($port[0] !== ':') {
            return false;
        }
        return strspn($port, '0123456789', 1) === strlen($port) - 1;
    }

    private static function parseHost(string $host): string
    {
        if (str_starts_with($host, '[')) {
            $i = strrpos($host, ']');
            if ($i === false) {
                throw new \UnexpectedValueException("missing ']' in host");
            }
            if (!self::validOptionalPort(substr($host, $i + 1))) {
                throw new \UnexpectedValueException('invalid port');
            }
        } else {
            $i = strrpos($host, ':');
            if ($i !== false && !self::validOptionalPort(substr($host, $i))) {
                throw new \UnexpectedValueException('invalid port');
            }
        }
        return self::unescape($host, self::MODE_HOST);
    }

    private static function validUserinfo(string $s): bool
    {
        return strspn($s, "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-._:~!$&'()*+,;=%@") === strlen($s);
    }

    private static function parseAuthority(Url $u, string $authority): void
    {
        $i = strrpos($authority, '@');
        $u->host = self::parseHost($i === false ? $authority : substr($authority, $i + 1));
        if ($i === false) {
            return;
        }
        $userinfo = substr($authority, 0, $i);
        if (!self::validUserinfo($userinfo)) {
            throw new \UnexpectedValueException('invalid userinfo');
        }
        $colon = strpos($userinfo, ':');
        if ($colon === false) {
            $u->user = self::escape(self::unescape($userinfo, self::MODE_USER_PASSWORD), self::MODE_USER_PASSWORD);
        } else {
            $name = self::unescape(substr($userinfo, 0, $colon), self::MODE_USER_PASSWORD);
            $password = self::unescape(substr($userinfo, $colon + 1), self::MODE_USER_PASSWORD);
            $u->user = self::escape($name, self::MODE_USER_PASSWORD) . ':' . self::escape($password, self::MODE_USER_PASSWORD);
        }
    }

    /** net/url Parse. */
    private static function parseUrl(string $rawUrl): Url
    {
        $hash = strpos($rawUrl, '#');
        $u = self::parseNoFragment($hash === false ? $rawUrl : substr($rawUrl, 0, $hash));
        if ($hash !== false) {
            $frag = substr($rawUrl, $hash + 1);
            $u->fragment = self::unescape($frag, self::MODE_FRAGMENT);
            $u->rawFragment = self::escape($u->fragment, self::MODE_FRAGMENT) === $frag ? '' : $frag;
        }
        return $u;
    }

    private static function parseNoFragment(string $raw): Url
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $raw)) {
            throw new \UnexpectedValueException('invalid control character in URL');
        }
        $u = new Url();
        if ($raw === '*') {
            $u->path = '*';
            return $u;
        }
        [$scheme, $rest] = self::getScheme($raw);
        $u->scheme = Chars::asciiLower($scheme);

        $q = strpos($rest, '?');
        if ($q !== false && $q === strlen($rest) - 1) {
            $u->forceQuery = true;
            $rest = substr($rest, 0, -1);
        } elseif ($q !== false) {
            $u->rawQuery = substr($rest, $q + 1);
            $rest = substr($rest, 0, $q);
        }

        if (!str_starts_with($rest, '/')) {
            if ($u->scheme !== '') {
                // rootless paths are opaque (RFC 3986)
                $u->opaque = $rest;
                return $u;
            }
            // a relative-path reference: the first segment cannot contain ':'
            $slash = strpos($rest, '/');
            if (str_contains($slash === false ? $rest : substr($rest, 0, $slash), ':')) {
                throw new \UnexpectedValueException('first path segment in URL cannot contain colon');
            }
        }

        if (($u->scheme !== '' || !str_starts_with($rest, '///')) && str_starts_with($rest, '//')) {
            $authority = substr($rest, 2);
            $rest = '';
            $slash = strpos($authority, '/');
            if ($slash !== false) {
                $rest = substr($authority, $slash);
                $authority = substr($authority, 0, $slash);
            }
            self::parseAuthority($u, $authority);
        }
        self::setPath($u, $rest);
        return $u;
    }

    private static function resolvePath(string $base, string $ref): string
    {
        if ($ref === '') {
            $full = $base;
        } elseif ($ref[0] !== '/') {
            $slash = strrpos($base, '/');
            $full = ($slash === false ? '' : substr($base, 0, $slash + 1)) . $ref;
        } else {
            $full = $ref;
        }
        if ($full === '') {
            return '';
        }

        $dst = '/';
        $first = true;
        $remaining = $full;
        $found = true;
        $elem = '';
        while ($found) {
            $slash = strpos($remaining, '/');
            $found = $slash !== false;
            $elem = $found ? substr($remaining, 0, $slash) : $remaining;
            $remaining = $found ? substr($remaining, $slash + 1) : '';
            if ($elem === '.') {
                $first = false;
                continue;
            }
            if ($elem === '..') {
                $str = substr($dst, 1);
                $index = strrpos($str, '/');
                $dst = '/';
                if ($index === false) {
                    $first = true;
                } else {
                    $dst .= substr($str, 0, $index);
                }
            } else {
                if (!$first) {
                    $dst .= '/';
                }
                $dst .= $elem;
                $first = false;
            }
        }
        if ($elem === '.' || $elem === '..') {
            $dst .= '/';
        }
        if (strlen($dst) > 1 && $dst[1] === '/') {
            $dst = substr($dst, 1);
        }
        return $dst;
    }

    /** net/url ResolveReference. */
    private static function resolveReference(Url $base, Url $ref): Url
    {
        $url = clone $ref;
        if ($ref->scheme === '') {
            $url->scheme = $base->scheme;
        }
        if ($ref->scheme !== '' || $ref->host !== '' || $ref->user !== null) {
            self::setPath($url, self::resolvePath(self::escapedPath($ref), ''));
            return $url;
        }
        if ($ref->opaque !== '') {
            $url->user = null;
            $url->host = '';
            $url->path = '';
            return $url;
        }
        if ($ref->path === '' && !$ref->forceQuery && $ref->rawQuery === '') {
            $url->rawQuery = $base->rawQuery;
            if ($ref->fragment === '') {
                $url->fragment = $base->fragment;
                $url->rawFragment = $base->rawFragment;
            }
        }
        if ($ref->path === '' && $base->opaque !== '') {
            $url->opaque = $base->opaque;
            $url->user = null;
            $url->host = '';
            $url->path = '';
            return $url;
        }
        $url->host = $base->host;
        $url->user = $base->user;
        self::setPath($url, self::resolvePath(self::escapedPath($base), self::escapedPath($ref)));
        return $url;
    }

    /** @return array{string, string} the host and the port */
    private static function splitHostPort(string $hostPort): array
    {
        $host = $hostPort;
        $port = '';
        $colon = strrpos($host, ':');
        if ($colon !== false && self::validOptionalPort(substr($host, $colon))) {
            $port = substr($host, $colon + 1);
            $host = substr($host, 0, $colon);
        }
        if (strlen($host) >= 2 && $host[0] === '[' && $host[-1] === ']') {
            $host = substr($host, 1, -1);
        }
        return [$host, $port];
    }

    /**
     * Decodes %XX escapes except those of reserved and control characters; escaped bytes of non-ASCII text are
     * decoded when they form valid UTF-8.
     */
    private static function unescapeForDisplay(string $s): string
    {
        if (!str_contains($s, '%')) {
            return $s;
        }
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n;) {
            if ($s[$i] !== '%' || $i + 2 >= $n || !ctype_xdigit($s[$i + 1]) || !ctype_xdigit($s[$i + 2])) {
                $out .= $s[$i++];
                continue;
            }
            $c = self::unhex(substr($s, $i + 1, 2));
            if ($c < 0x80) {
                if ($c < 0x20 || $c === 0x7f || str_contains(self::RESERVED_FOR_DISPLAY, chr($c))) {
                    $out .= substr($s, $i, 3);
                } else {
                    $out .= chr($c);
                }
                $i += 3;
                continue;
            }
            // a run of escaped bytes >= 0x80: decoded together when it is valid UTF-8
            $j = $i;
            $run = '';
            while ($j + 2 < $n && $s[$j] === '%' && ctype_xdigit($s[$j + 1]) && ctype_xdigit($s[$j + 2])) {
                $v = self::unhex(substr($s, $j + 1, 2));
                if ($v < 0x80) {
                    break;
                }
                $run .= chr($v);
                $j += 3;
            }
            $out .= preg_match('//u', $run) ? $run : substr($s, $i, $j - $i);
            $i = $j;
        }
        return $out;
    }

    /**
     * Writes a resolved URL as the .NET version does (System.Uri.ToString): the scheme and host in lower case,
     * no default port, an empty path as "/", and percent-escapes decoded except those of the characters that delimit
     * URL parts ("%2F" stays) and of control characters; non-ASCII text is written as is.
     */
    private static function displayString(Url $u): string
    {
        $b = Chars::asciiLower($u->scheme) . ':';
        if ($u->opaque !== '') {
            $b .= $u->opaque;
        } else {
            if ($u->host !== '' || $u->user !== null) {
                $b .= '//';
                if ($u->user !== null) {
                    $b .= $u->user . '@';
                }
                [$host, $port] = self::splitHostPort($u->host);
                $b .= Chars::lower(Chars::scrub($host));
                if ($port !== '' && $port !== (self::DEFAULT_PORTS[Chars::asciiLower($u->scheme)] ?? null)) {
                    $b .= ':' . $port;
                }
            }
            $path = self::escapedPath($u);
            if ($path === '' && $u->host !== '') {
                $path = '/';
            }
            $b .= self::unescapeForDisplay($path);
        }
        if ($u->rawQuery !== '' || $u->forceQuery) {
            $b .= '?' . self::unescapeForDisplay($u->rawQuery);
        }
        if ($u->fragment !== '') {
            $b .= '#' . self::unescapeForDisplay(self::escapedFragment($u));
        }
        return Chars::scrub($b);
    }

    /** Parses a page URL with its own scheme and host; null for anything else. */
    public static function absoluteUrl(string $s): ?Url
    {
        if ($s === '') {
            return null;
        }
        try {
            $u = self::parseUrl($s);
            if ($u->scheme === '' || ($u->host === '' && $u->opaque === '' && strcasecmp($u->scheme, 'file') !== 0)) {
                return null;
            }
            return $u;
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /** A link with its own scheme ("https://…", "mailto:…", "file:…"). "/path" and "//host/path" are relative. */
    private static function isAbsoluteLink(string $link): bool
    {
        try {
            return self::parseUrl(Chars::trimSpace($link))->scheme !== '';
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    /**
     * The raw attribute value with its character references decoded and made absolute against the page.
     * Percent-escapes are part of the URL and are kept. An empty link gives the page URL.
     */
    public static function correctLink(string $raw, string $pageUrl, ?Url $base): string
    {
        if ($raw === '') {
            return $pageUrl;
        }
        $link = strpbrk($raw, "&\r") === false ? $raw : Decode::decode($raw, true);
        if ($base === null || self::isAbsoluteLink($link)) {
            return $link;
        }
        try {
            return self::displayString(self::resolveReference($base, self::parseUrl(Chars::trimSpace($link))));
        } catch (\UnexpectedValueException) {
            return $link;
        }
    }

    /** Everything after the first ':' of a link with the scheme (mailto, tel), or "". */
    public static function afterScheme(string $rawHref, string $scheme): string
    {
        $href = Chars::trimSpace(Decode::decode($rawHref, true));
        if (strlen($href) < strlen($scheme) || strncasecmp($href, $scheme, strlen($scheme)) !== 0) {
            return '';
        }
        $i = strpos($href, ':');
        return $i === false ? '' : substr($href, $i + 1);
    }
}
