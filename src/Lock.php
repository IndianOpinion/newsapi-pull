<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * One run at a time per site. The lock is an option written with an atomic
 * add (the option name is unique in the table), not a transient, because
 * some object caches make transients unreliable. It holds its expiry time.
 */
final class Lock
{
    public const OPTION = 'newsapi_pull_lock';

    private bool $held = false;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function ttl(): int
    {
        $ttl = apply_filters('newsapi_pull_lock_ttl', 600);
        return min(3600, max(60, is_numeric($ttl) ? (int) $ttl : 600));
    }

    public function acquire(): bool
    {
        $expiry = $this->clock->now() + $this->ttl();
        if ($this->tryAdd($expiry)) {
            return true;
        }
        $current = get_option(self::OPTION, 0);
        if (is_numeric($current) && (int) $current > $this->clock->now()) {
            return false;
        }
        // Expired: take it over. Delete then add, so only one taker wins.
        delete_option(self::OPTION);
        return $this->tryAdd($expiry);
    }

    private function tryAdd(int $expiry): bool
    {
        $this->held = add_option(self::OPTION, $expiry, '', false);
        return $this->held;
    }

    public function release(): void
    {
        if ($this->held) {
            delete_option(self::OPTION);
            $this->held = false;
        }
    }

    /** Clears the lock whoever holds it (deactivation). */
    public static function clear(): void
    {
        delete_option(self::OPTION);
    }
}
