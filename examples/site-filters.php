<?php

/**
 * Example site plugin showing the most useful hooks. Drop it in
 * wp-content/mu-plugins/ and edit to taste.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Let a redirect-unwrapping tool hand back the publisher URL before keys are computed.
add_filter('newsapi_pull_url', static function (string $url, string $context): string {
    return $url;
}, 10, 2);

// Treat links on an aggregator's redirect host as unusable unless unwrapped above.
add_filter('newsapi_pull_wrapper_hosts', static function (array $hosts): array {
    $hosts[] = 'redirect.aggregator.example';
    return $hosts;
});

// Never import from these sources or hosts.
add_filter('newsapi_pull_denied_sources', static fn (array $ids): array => array_merge($ids, ['some-source-id']));
add_filter('newsapi_pull_denied_hosts', static fn (array $hosts): array => array_merge($hosts, ['tabloid.example.com']));

// Reject articles by your own rule. Return a short reason to reject, '' to accept.
add_filter('newsapi_pull_accept_article', static function (string $reason, array $article, $pull): string {
    if (stripos((string) $article['title'], 'sponsored') !== false) {
        return 'sponsored';
    }
    return $reason;
}, 10, 3);

// Stricter environment rule: never fetch from a staging copy, whatever the plan.
add_filter('newsapi_pull_environment_allowed', static function (bool $allowed, string $env, string $plan): bool {
    return $allowed && $env !== 'staging';
}, 10, 3);

// Send each run summary to your own monitoring.
add_action('newsapi_pull_run_finished', static function (array $summary): void {
    if ($summary['outcome'] === 'error') {
        error_log('newsapi-pull run failed: ' . $summary['pull'] . ' ' . $summary['reason']);
    }
});
