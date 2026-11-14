<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

use IndianOpinion\NewsApiPull\Exception\ApiException;
use IndianOpinion\NewsApiPull\Exception\NetworkException;
use IndianOpinion\NewsApiPull\Exception\QuotaException;
use IndianOpinion\NewsApiPull\Exception\ResponseException;

/**
 * The NewsAPI.org HTTP client. HTTP goes through the WordPress HTTP API only.
 *
 * The host is a constant, the key travels in the X-Api-Key header only, and
 * TLS verification is always on.
 */
final class Client
{
    public const HOST = 'https://newsapi.org';

    public const VERSION = '0.1.0';

    /** Header names read for a remaining-quota value, if the API sends one. */
    public const REMAINING_HEADERS = ['x-ratelimit-remaining', 'ratelimit-remaining', 'x-api-quota-remaining'];

    /** @var \Closure(Query): void|null */
    private ?\Closure $beforeSend;

    /**
     * @param (callable(Query): void)|null $beforeSend Called once per request before it is sent (the meter counts here).
     */
    public function __construct(private readonly string $apiKey, ?callable $beforeSend = null)
    {
        $this->beforeSend = $beforeSend !== null ? \Closure::fromCallable($beforeSend) : null;
    }

    public function headlines(Query $query): ArticlesResponse
    {
        return $this->articles($query, 'headlines');
    }

    public function everything(Query $query): ArticlesResponse
    {
        return $this->articles($query, 'everything');
    }

    public function sources(Query $query): SourcesResponse
    {
        if ($query->endpoint !== 'sources') {
            throw new \InvalidArgumentException('A sources query is required.');
        }
        [$data, $remaining] = $this->send($query);
        if (!isset($data['sources']) || !is_array($data['sources'])) {
            throw new ResponseException('The response has no sources array.');
        }
        $sources = [];
        foreach ($data['sources'] as $s) {
            if (!is_array($s) || !isset($s['id']) || !is_string($s['id']) || $s['id'] === '') {
                continue;
            }
            $sources[] = [
                'id' => $s['id'],
                'name' => self::s($s, 'name'),
                'description' => self::s($s, 'description'),
                'url' => self::s($s, 'url'),
                'category' => self::s($s, 'category'),
                'language' => strtolower(self::s($s, 'language')),
                'country' => strtolower(self::s($s, 'country')),
            ];
        }
        return new SourcesResponse($sources, $remaining);
    }

    /** The full request URL. Never contains the key. */
    public static function url(Query $query): string
    {
        $url = self::HOST . $query->path();
        if ($query->params !== []) {
            $url .= '?' . http_build_query($query->params, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }

    public static function timeout(): int
    {
        $timeout = apply_filters('newsapi_pull_timeout', 15);
        return min(60, max(5, is_numeric($timeout) ? (int) $timeout : 15));
    }

    public static function userAgent(): string
    {
        $ua = 'newsapi-pull/' . self::VERSION . '; ' . home_url('/');
        $filtered = apply_filters('newsapi_pull_user_agent', $ua);
        return is_string($filtered) && $filtered !== '' ? $filtered : $ua;
    }

    private function articles(Query $query, string $endpoint): ArticlesResponse
    {
        if ($query->endpoint !== $endpoint) {
            throw new \InvalidArgumentException(sprintf('A %s query is required.', $endpoint));
        }
        [$data, $remaining] = $this->send($query);
        if (!isset($data['articles']) || !is_array($data['articles'])) {
            throw new ResponseException('The response has status ok but no articles array.');
        }
        $total = isset($data['totalResults']) && is_numeric($data['totalResults']) ? (int) $data['totalResults'] : count($data['articles']);
        return new ArticlesResponse(array_values($data['articles']), $total, $remaining);
    }

    /**
     * @return array{0: array<mixed>, 1: int|null}
     */
    private function send(Query $query): array
    {
        $args = [
            'method' => 'GET',
            'timeout' => self::timeout(),
            'user-agent' => self::userAgent(),
            'redirection' => 2,
            'headers' => ['Accept' => 'application/json'],
        ];
        $filtered = apply_filters('newsapi_pull_request_args', $args, $query->endpoint, $query->pull);
        if (is_array($filtered)) {
            $args = $filtered;
        }
        $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower((string) $name), ['x-api-key', 'authorization'], true)) {
                unset($headers[$name]);
            }
        }
        $headers['X-Api-Key'] = $this->apiKey;
        $args['headers'] = $headers;
        $args['sslverify'] = true;
        $args['method'] = 'GET';
        $args['timeout'] = min(60, max(5, isset($args['timeout']) && is_numeric($args['timeout']) ? (int) $args['timeout'] : 15));
        $url = self::url($query);

        do_action('newsapi_pull_before_request', $query->endpoint, $query->params, $query->pull->slug ?? '');
        if ($this->beforeSend !== null) {
            ($this->beforeSend)($query);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new NetworkException($this->redact('Network error: ' . $response->get_error_message()));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $remaining = self::remaining($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            if ($status === 429) {
                throw new QuotaException('rateLimited', 'Rate limited (HTTP 429).', 429);
            }
            if ($status >= 500) {
                throw new ApiException('unexpectedError', sprintf('Server error (HTTP %d).', $status), $status);
            }
            throw new ResponseException(
                sprintf('The response is not JSON (HTTP %d).', $status),
                $this->redact(substr($body, 0, 200))
            );
        }
        $apiStatus = $data['status'] ?? null;
        if ($apiStatus === 'error' || $status >= 400) {
            $code = isset($data['code']) && is_string($data['code']) && $data['code'] !== '' ? $data['code'] : '';
            if ($code === '') {
                $code = $status === 429 ? 'rateLimited' : ($status >= 500 ? 'unexpectedError' : 'unknown');
            }
            $message = isset($data['message']) && is_string($data['message']) ? $data['message'] : '';
            $message = $this->redact(mb_substr($message, 0, 500, 'UTF-8'));
            if ($code === 'apiKeyExhausted' || $code === 'rateLimited' || $status === 429) {
                throw new QuotaException($code, $message, $status);
            }
            throw new ApiException($code, $message, $status);
        }
        if ($apiStatus !== 'ok') {
            throw new ResponseException('The response has no status ok.', $this->redact(substr($body, 0, 200)));
        }
        return [$data, $remaining];
    }

    /**
     * @param array<mixed> $response
     */
    private static function remaining(array $response): ?int
    {
        $names = apply_filters('newsapi_pull_remaining_headers', self::REMAINING_HEADERS);
        if (!is_array($names)) {
            return null;
        }
        foreach ($names as $name) {
            if (!is_string($name)) {
                continue;
            }
            $value = wp_remote_retrieve_header($response, $name);
            if (is_array($value)) {
                $value = end($value);
            }
            if (is_string($value) && preg_match('/^\s*\d+\s*$/', $value)) {
                return (int) $value;
            }
        }
        return null;
    }

    private function redact(string $text): string
    {
        return Redactor::redact($text, $this->apiKey);
    }

    /**
     * @param array<mixed> $a
     */
    private static function s(array $a, string $k): string
    {
        return isset($a[$k]) && is_string($a[$k]) ? $a[$k] : '';
    }
}
