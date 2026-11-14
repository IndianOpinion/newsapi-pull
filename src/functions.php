<?php

declare(strict_types=1);

use IndianOpinion\NewsApiPull\Plugin;
use IndianOpinion\NewsApiPull\UrlKey;

/*
 * Stable global functions. None of them throws.
 */

if (!function_exists('newsapi_pull_run')) {
    /**
     * Runs one pull and returns the run summary.
     *
     * @return array<string, mixed>
     */
    function newsapi_pull_run(string $pull_slug, bool $manual = false): array
    {
        try {
            return Plugin::instance()->runner->run($pull_slug, $manual);
        } catch (\Throwable $e) {
            return [
                'pull' => $pull_slug,
                'outcome' => 'error',
                'reason' => 'exception',
                'message' => Plugin::instance()->logger->redact($e->getMessage()),
                'requests' => 0,
                'received' => 0,
                'created' => 0,
                'rejections' => [],
                'post_ids' => [],
            ];
        }
    }
}

if (!function_exists('newsapi_pull_is_seen')) {
    function newsapi_pull_is_seen(string $url): bool
    {
        try {
            $plugin = Plugin::instance();
            $keys = $plugin->keys->forUrl($url, 'check');
            if ($keys['status'] !== 'ok') {
                return false;
            }
            $plugin->seen->ensure();
            return $plugin->seen->find([$keys['canonical_hash'], $keys['plain_hash']]) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('newsapi_pull_keys')) {
    /**
     * Canonical key, plain key, their hashes (hex) and the key version, or an
     * empty array for a value that is not an http(s) URL.
     *
     * @return array<string, mixed>
     */
    function newsapi_pull_keys(string $url): array
    {
        try {
            $keys = Plugin::instance()->keys->forUrl($url, 'check');
        } catch (\Throwable $e) {
            return [];
        }
        if ($keys['status'] !== 'ok') {
            return [];
        }
        return [
            'canonical' => $keys['canonical'],
            'plain' => $keys['plain'],
            'canonical_hash' => bin2hex($keys['canonical_hash']),
            'plain_hash' => bin2hex($keys['plain_hash']),
            'version' => UrlKey::VERSION,
        ];
    }
}

if (!function_exists('newsapi_pull_quota')) {
    /**
     * Used, limit, reserve, remaining, reset time (UTC, ISO 8601) and per-pull counts.
     *
     * @return array<string, mixed>
     */
    function newsapi_pull_quota(): array
    {
        try {
            return Plugin::instance()->meter->snapshot();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
