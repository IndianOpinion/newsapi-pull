<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * The daily request meter (PRD 4.4).
 *
 * Every request is counted before it is sent. Days are UTC calendar days.
 * The meter is scoped to the API key: per site, or once for the network when
 * the plugin is network-activated, so sites sharing a key share one count.
 * Writes run under a MySQL named lock and always re-read the stored option,
 * and claim() checks and counts in one step, so concurrent runs on different
 * sites cannot both take the last request.
 */
final class Meter
{
    public const OPTION = 'newsapi_pull_meter';

    public const KEEP_DAYS = 30;

    public function __construct(
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly bool $network = false
    ) {
    }

    public function isNetwork(): bool
    {
        return $this->network;
    }

    public function today(): string
    {
        return gmdate('Y-m-d', $this->clock->now());
    }

    /** Next UTC midnight as Unix time. */
    public function resetAt(): int
    {
        $now = $this->clock->now();
        return (int) (floor($now / 86400) + 1) * 86400;
    }

    /**
     * Today's record for the current key.
     *
     * @return array{total: int, pulls: array<string, int>, exhausted: bool, exhausted_source: string, api_remaining: int|null}
     */
    public function day(): array
    {
        $all = $this->load();
        $days = $all[$this->settings->keyFingerprint()] ?? [];
        $day = is_array($days) ? ($days[$this->today()] ?? []) : [];
        return self::normaliseDay(is_array($day) ? $day : []);
    }

    /**
     * Whether a request may be sent now. Returns null when it may, or the
     * reason it may not: `quota` (meter full or exhausted) or `pull_quota`
     * (the pull's own daily cap).
     *
     * Scheduled runs stop when used plus reserve reaches the limit; manual
     * runs and the catalogue may use the reserve. Nothing runs at the limit.
     */
    public function refusal(bool $useReserve, ?string $pull = null, ?int $pullCap = null): ?string
    {
        return $this->refusalFor($this->day(), $useReserve, $pull, $pullCap);
    }

    /**
     * Checks the meter and counts one request in a single step under the
     * meter mutex, so two runs (or two sites sharing a network meter) cannot
     * both take the last request. Returns null when the request was counted
     * and may be sent, or the refusal reason when nothing was counted.
     *
     * A failed write does not refuse the request (fail open on bookkeeping).
     */
    public function claim(bool $useReserve, ?string $pull = null, ?int $pullCap = null): ?string
    {
        $refusal = null;
        $this->mutate(function (array $day) use ($useReserve, $pull, $pullCap, &$refusal): array {
            $refusal = $this->refusalFor($day, $useReserve, $pull, $pullCap);
            if ($refusal !== null) {
                return $day;
            }
            $day['total']++;
            $slug = $pull ?? '_catalogue';
            $day['pulls'][$slug] = ($day['pulls'][$slug] ?? 0) + 1;
            return $day;
        });
        return $refusal;
    }

    /**
     * @param array{total: int, pulls: array<string, int>, exhausted: bool, exhausted_source: string, api_remaining: int|null} $day
     */
    private function refusalFor(array $day, bool $useReserve, ?string $pull, ?int $pullCap): ?string
    {
        if ($day['exhausted']) {
            return 'quota';
        }
        $limit = $this->settings->dailyLimit();
        $used = $day['total'];
        if ($day['api_remaining'] !== null && $day['api_remaining'] <= 0) {
            return 'quota';
        }
        if ($limit !== null) {
            if ($used >= $limit) {
                return 'quota';
            }
            if (!$useReserve && $used + $this->effectiveReserve($limit) >= $limit) {
                return 'quota';
            }
        }
        if ($pull !== null && $pullCap !== null && ($day['pulls'][$pull] ?? 0) >= $pullCap) {
            return 'pull_quota';
        }
        return null;
    }

    /** The reserve, never larger than the limit minus one. */
    public function effectiveReserve(?int $limit = null): int
    {
        $limit ??= $this->settings->dailyLimit();
        if ($limit === null) {
            return 0;
        }
        return max(0, min($this->settings->reserve(), $limit - 1));
    }

    /**
     * Counts one request. A failed write is logged and does not block the
     * run: the API's own quota error remains the hard stop.
     */
    public function count(?string $pull = null): bool
    {
        return $this->mutate(function (array $day) use ($pull): array {
            $day['total']++;
            $slug = $pull ?? '_catalogue';
            $day['pulls'][$slug] = ($day['pulls'][$slug] ?? 0) + 1;
            return $day;
        });
    }

    /**
     * Marks the meter full for the rest of the quota day.
     *
     * @param string $source `api` when the API said so, `meter` otherwise.
     */
    public function markExhausted(string $source): void
    {
        $this->mutate(static function (array $day) use ($source): array {
            $day['exhausted'] = true;
            $day['exhausted_source'] = $source;
            return $day;
        });
        do_action('newsapi_pull_quota_exhausted', $source);
    }

    /**
     * Corrects the local count from a remaining-quota response header.
     */
    public function applyRemaining(int $remaining): void
    {
        $limit = $this->settings->dailyLimit();
        $this->mutate(static function (array $day) use ($remaining, $limit): array {
            $day['api_remaining'] = max(0, $remaining);
            if ($limit !== null) {
                $day['total'] = max($day['total'], $limit - max(0, $remaining));
            }
            return $day;
        });
    }

    /**
     * @return array{used: int, limit: int|null, reserve: int, remaining: int|null, reset: string, pulls: array<string, int>, exhausted: bool, exhausted_source: string, source: string, scope: string}
     */
    public function snapshot(): array
    {
        $day = $this->day();
        $limit = $this->settings->dailyLimit();
        $remaining = $limit === null ? null : max(0, $limit - $day['total']);
        $source = 'local';
        if ($day['api_remaining'] !== null) {
            $remaining = $remaining === null ? $day['api_remaining'] : min($remaining, $day['api_remaining']);
            $source = 'header';
        }
        if ($day['exhausted']) {
            $remaining = 0;
        }
        return [
            'used' => $day['total'],
            'limit' => $limit,
            'reserve' => $this->effectiveReserve($limit),
            'remaining' => $remaining,
            'reset' => gmdate('Y-m-d\TH:i:s\Z', $this->resetAt()),
            'pulls' => $day['pulls'],
            'exhausted' => $day['exhausted'],
            'exhausted_source' => $day['exhausted_source'],
            'source' => $source,
            'scope' => $this->network ? 'network' : 'site',
        ];
    }

    public function delete(): void
    {
        if ($this->network) {
            delete_site_option(self::OPTION);
        } else {
            delete_option(self::OPTION);
        }
    }

    /**
     * @param callable(array{total: int, pulls: array<string, int>, exhausted: bool, exhausted_source: string, api_remaining: int|null}): array{total: int, pulls: array<string, int>, exhausted: bool, exhausted_source: string, api_remaining: int|null} $change
     */
    private function mutate(callable $change): bool
    {
        $locked = $this->lock();
        try {
            $all = $this->load();
            $before = $all;
            $fp = $this->settings->keyFingerprint();
            $days = isset($all[$fp]) && is_array($all[$fp]) ? $all[$fp] : [];
            $today = $this->today();
            $day = self::normaliseDay(isset($days[$today]) && is_array($days[$today]) ? $days[$today] : []);
            $days[$today] = $change($day);
            $all[$fp] = $days;
            $all = $this->prune($all);
            if ($all === $before) {
                return true;
            }
            $ok = $this->network
                ? update_site_option(self::OPTION, $all)
                : update_option(self::OPTION, $all, false);
            if (!$ok) {
                $this->logger->warning('Quota meter write failed; the run continues and the API quota error stays the hard stop.');
            }
            return (bool) $ok;
        } finally {
            if ($locked !== null) {
                $this->unlock($locked);
            }
        }
    }

    /**
     * Takes a MySQL named lock around a meter write. Named locks are atomic
     * across processes and, unlike an option row, work for the network meter
     * too (the site meta table has no unique key to make an add atomic).
     * Returns the lock name, or null when no lock could be taken; the write
     * then goes ahead unlocked (fail open on bookkeeping).
     */
    private function lock(): ?string
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return null;
        }
        $prefix = isset($wpdb->prefix) && is_string($wpdb->prefix) ? $wpdb->prefix : '';
        if ($this->network && isset($wpdb->base_prefix) && is_string($wpdb->base_prefix)) {
            $prefix = $wpdb->base_prefix;
        }
        $name = 'newsapi_pull_meter_' . md5($prefix . ($this->network ? '|network' : '|site'));
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, 5));
        if ((string) $got !== '1') {
            $this->logger->debug('Quota meter lock not taken; the meter write goes ahead without it.');
            return null;
        }
        return $name;
    }

    private function unlock(string $name): void
    {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    /**
     * Drops days older than KEEP_DAYS, for every key.
     *
     * @param array<mixed> $all
     * @return array<mixed>
     */
    public function prune(array $all): array
    {
        $cutoff = gmdate('Y-m-d', $this->clock->now() - self::KEEP_DAYS * 86400);
        foreach ($all as $fp => $days) {
            if (!is_array($days)) {
                unset($all[$fp]);
                continue;
            }
            foreach (array_keys($days) as $date) {
                if ((string) $date < $cutoff) {
                    unset($days[$date]);
                }
            }
            if ($days === []) {
                unset($all[$fp]);
            } else {
                $all[$fp] = $days;
            }
        }
        return $all;
    }

    /**
     * @return array<mixed>
     */
    private function load(): array
    {
        OptionCache::forget(self::OPTION, $this->network);
        $value = $this->network ? get_site_option(self::OPTION, []) : get_option(self::OPTION, []);
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<mixed> $day
     * @return array{total: int, pulls: array<string, int>, exhausted: bool, exhausted_source: string, api_remaining: int|null}
     */
    private static function normaliseDay(array $day): array
    {
        $pulls = [];
        if (isset($day['pulls']) && is_array($day['pulls'])) {
            foreach ($day['pulls'] as $slug => $n) {
                $pulls[(string) $slug] = (int) $n;
            }
        }
        return [
            'total' => isset($day['total']) ? (int) $day['total'] : 0,
            'pulls' => $pulls,
            'exhausted' => !empty($day['exhausted']),
            'exhausted_source' => isset($day['exhausted_source']) && is_string($day['exhausted_source']) ? $day['exhausted_source'] : '',
            'api_remaining' => isset($day['api_remaining']) && is_numeric($day['api_remaining']) ? (int) $day['api_remaining'] : null,
        ];
    }
}
