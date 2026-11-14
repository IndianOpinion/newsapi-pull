<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * The daily request meter (PRD 4.4).
 *
 * Every request is counted before it is sent. Days are UTC calendar days.
 * The meter is scoped to the API key: per site, or once for the network when
 * the plugin is network-activated, so sites sharing a key share one count.
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
        $day = $this->day();
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
