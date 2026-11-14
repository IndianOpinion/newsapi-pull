<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * The two de-duplication keys for an article URL.
 *
 * Pure functions, no WordPress. The canonical key collapses the many URLs of
 * one article (mobile and AMP hosts, AMP paths, tracking queries, trailing
 * slashes). The plain key is frozen at release so rows keep matching after
 * the canonical rules change.
 */
final class UrlKey
{
    /** Raised whenever a default canonical rule changes. */
    public const VERSION = 1;

    private const UNRESERVED = '/^[A-Za-z0-9\-._~]$/';

    /** Bytes kept literally in a path besides the unreserved set. */
    private const PATH_SAFE = "!$&'()*+,;=:@/";

    /** Bytes kept literally in a query value besides the unreserved set. */
    private const QUERY_SAFE = "!$'()*+,;:@/?";

    private readonly UrlKeyRules $rules;

    public function __construct(?UrlKeyRules $rules = null)
    {
        $this->rules = $rules ?? new UrlKeyRules();
    }

    /**
     * Trims and decodes HTML entities exactly once.
     */
    public static function prepare(string $url): string
    {
        return trim(html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Parses an absolute http or https URL with a host. Returns null for
     * anything else. The input must already be prepared.
     *
     * @return array{scheme: string, host: string, port: int|null, path: string, query: string}|null
     */
    public static function parse(string $url): ?array
    {
        if (!preg_match('#^https?://#i', $url) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }
        $host = $parts['host'];
        if (str_starts_with($host, '[')) {
            if (!str_ends_with($host, ']') || filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
        } elseif (!preg_match('/^[\p{L}\p{N}\p{M}._\-]+$/u', $host) || !preg_match('/[\p{L}\p{N}]/u', $host)) {
            return null;
        }
        return [
            'scheme' => self::asciiLower($parts['scheme']),
            'host' => $host,
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'path' => $parts['path'] ?? '',
            'query' => $parts['query'] ?? '',
        ];
    }

    /**
     * The canonical key, prefixed with its version, or null when the value is
     * not an absolute http(s) URL with a host.
     */
    public function canonical(string $url, bool $prepared = false): ?string
    {
        $url = $prepared ? $url : self::prepare($url);
        $parts = self::parse($url);
        if ($parts === null) {
            return null;
        }
        $host = $this->host($parts['host']);
        $port = '';
        if ($parts['port'] !== null) {
            $default = $parts['scheme'] === 'https' ? 443 : 80;
            if ($parts['port'] !== $default) {
                $port = ':' . $parts['port'];
            }
        }
        $lower = $this->rules->lowercasePath($host);
        $script = false;
        $path = $this->path($parts['path'], $lower, $script);
        $query = $this->query($parts['query'], $script);
        return 'v' . self::VERSION . '|' . $host . $port . $path . ($query !== '' ? '?' . $query : '');
    }

    /**
     * The frozen plain key. Never change this method: rows stored under it
     * must keep matching for the life of the table.
     */
    public static function plain(string $url, bool $prepared = false): ?string
    {
        $url = $prepared ? $url : self::prepare($url);
        if (self::parse($url) === null) {
            return null;
        }
        $key = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $url);
        $hash = strpos($key, '#');
        if ($hash !== false) {
            $key = substr($key, 0, $hash);
        }
        $slash = strpos($key, '/');
        $at = strpos($key, '@');
        if ($at !== false && ($slash === false || $at < $slash)) {
            $key = substr($key, $at + 1);
        }
        if (strncasecmp($key, 'www.', 4) === 0) {
            $key = substr($key, 4);
        }
        $q = strpos($key, '?');
        $before = $q === false ? $key : substr($key, 0, $q);
        $after = $q === false ? '' : substr($key, $q);
        if (str_ends_with($before, '/')) {
            $before = substr($before, 0, -1);
        }
        return mb_strtolower($before . $after, 'UTF-8');
    }

    /**
     * A fixed-length binary digest for compact equality lookup (not security).
     */
    public static function hash(string $key): string
    {
        return md5($key, true);
    }

    /**
     * Lower-cases ASCII letters only, whatever the locale.
     */
    public static function asciiLower(string $value): string
    {
        return strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /**
     * Normalised host for the canonical key (also used for own-host checks).
     */
    public function host(string $host): string
    {
        $host = mb_strtolower(rtrim($host, '.'), 'UTF-8');
        if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        if (preg_match('/[^\x00-\x7f]/', $host) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = self::asciiLower($ascii);
            }
        }
        $labels = explode('.', $host);
        while (count($labels) > 2 && in_array($labels[0], $this->rules->mirrorLabels, true)) {
            array_shift($labels);
        }
        return implode('.', $labels);
    }

    private function path(string $path, bool $lower, bool &$script): string
    {
        $path = self::normaliseEncoding($path, self::PATH_SAFE, $lower);
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        $script = $this->isScriptEndpoint('/' . implode('/', $segments));
        $last = count($segments) - 1;
        if ($last >= 0 && in_array(self::asciiLower($segments[$last]), ['index.html', 'index.htm', 'index.php'], true)) {
            array_pop($segments);
        }
        $segments = self::stripAmp($segments);
        return '/' . implode('/', $segments);
    }

    /**
     * Collapses AMP path forms to the article path.
     *
     * @param list<string> $segments
     * @return list<string>
     */
    private static function stripAmp(array $segments): array
    {
        $n = count($segments);
        if ($n >= 2 && self::asciiLower($segments[$n - 2]) === 'amp' && $segments[$n - 1] === '1') {
            array_splice($segments, $n - 2, 2);
        }
        $segments = array_values(array_filter(
            $segments,
            static fn (string $s): bool => self::asciiLower($s) !== 'amp'
        ));
        $n = count($segments);
        if ($n > 0) {
            $last = $segments[$n - 1];
            $replaced = preg_replace(['/^(.+)[.\-]amp(\.html?)$/i', '/^(.+)\.amp$/i'], ['$1$2', '$1'], $last);
            $segments[$n - 1] = is_string($replaced) ? $replaced : $last;
        }
        return array_values($segments);
    }

    private function query(string $query, bool $script): string
    {
        if ($query === '') {
            return '';
        }
        $kept = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $rawName = $eq === false ? $pair : substr($pair, 0, $eq);
            $rawValue = $eq === false ? '' : substr($pair, $eq + 1);
            $name = self::asciiLower(rawurldecode(str_replace('+', ' ', $rawName)));
            if ($name === '' || $rawValue === '' || str_contains($name, '[')) {
                continue;
            }
            if (str_starts_with($name, 'utm_') || in_array($name, $this->rules->trackingParams, true)) {
                continue;
            }
            if (!$script && !in_array($name, $this->rules->idParams, true)) {
                continue;
            }
            unset($kept[$name]);
            $kept[$name] = self::normaliseEncoding($rawValue, self::QUERY_SAFE, false);
        }
        if ($kept === []) {
            return '';
        }
        ksort($kept, SORT_STRING);
        $out = [];
        foreach ($kept as $name => $value) {
            $out[] = self::normaliseEncoding((string) $name, '', false) . '=' . $value;
        }
        return implode('&', $out);
    }

    private function isScriptEndpoint(string $path): bool
    {
        if ($path === '/') {
            return true;
        }
        $last = substr($path, (int) strrpos($path, '/') + 1);
        $dot = strrpos($last, '.');
        if ($dot === false) {
            return false;
        }
        return in_array(self::asciiLower(substr($last, $dot + 1)), $this->rules->scriptExtensions, true);
    }

    /**
     * Upper-cases percent-escapes, decodes unreserved ones, encodes raw bytes
     * outside the safe set (so raw UTF-8 meets its encoded form), and
     * optionally lower-cases ASCII letters outside escapes.
     */
    private static function normaliseEncoding(string $value, string $safe, bool $lower): string
    {
        $out = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            if ($c === '%' && $i + 2 < $len && ctype_xdigit(substr($value, $i + 1, 2))) {
                $decoded = chr((int) hexdec(substr($value, $i + 1, 2)));
                $i += 2;
                if (preg_match(self::UNRESERVED, $decoded)) {
                    $out .= $lower ? self::asciiLower($decoded) : $decoded;
                } else {
                    $out .= '%' . strtoupper(bin2hex($decoded));
                }
                continue;
            }
            if (preg_match(self::UNRESERVED, $c) || ($safe !== '' && str_contains($safe, $c))) {
                $out .= $lower ? self::asciiLower($c) : $c;
                continue;
            }
            $out .= '%' . strtoupper(bin2hex($c));
        }
        return $out;
    }
}
