<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * Drops this process's cached copy of an option before a read-modify-write,
 * so a long run does not write back a copy that another process has since
 * changed. Without a persistent object cache WordPress keeps the first value
 * it read for the whole request; a run lasts minutes.
 */
final class OptionCache
{
    public static function forget(string $option, bool $network = false): void
    {
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        if ($network && function_exists('is_multisite') && is_multisite()) {
            $prefix = (function_exists('get_current_network_id') ? get_current_network_id() : 1) . ':';
            wp_cache_delete($prefix . $option, 'site-options');
            self::forgetMissing($prefix . 'notoptions', 'site-options', $option);
            return;
        }
        wp_cache_delete($option, 'options');
        self::forgetMissing('notoptions', 'options', $option);
    }

    private static function forgetMissing(string $key, string $group, string $option): void
    {
        $missing = wp_cache_get($key, $group);
        if (is_array($missing) && isset($missing[$option])) {
            unset($missing[$option]);
            wp_cache_set($key, $missing, $group);
        }
    }
}
