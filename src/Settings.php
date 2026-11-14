<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * The settings option, with constants from wp-config.php taking precedence.
 */
final class Settings
{
    public const OPTION = 'newsapi_pull_settings';

    public const PLANS = ['developer', 'paid'];

    public const DEVELOPER_LIMIT = 100;

    public const DEFAULT_RESERVE = 20;

    public const DEFAULT_RETENTION_DAYS = 180;

    /** @var \Closure(string): mixed */
    private readonly \Closure $constant;

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * @param (callable(string): mixed)|null $constant Reads a constant by name, null when undefined.
     */
    public function __construct(?callable $constant = null)
    {
        $this->constant = \Closure::fromCallable($constant ?? static function (string $name): mixed {
            return defined($name) ? constant($name) : null;
        });
    }

    /**
     * Stored values merged over defaults.
     *
     * @return array{plan: string, api_key: string, daily_limit: int|null, reserve: int, credit_line: bool, include_snippet: bool, kill_switch: bool, debug_log: bool, catalogue_ttl_hours: int, retention_days: int}
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            $this->cache = is_array($stored) ? $stored : [];
        }
        return self::normalise($this->cache);
    }

    /**
     * @param array<mixed> $raw
     * @return array{plan: string, api_key: string, daily_limit: int|null, reserve: int, credit_line: bool, include_snippet: bool, kill_switch: bool, debug_log: bool, catalogue_ttl_hours: int, retention_days: int}
     */
    public static function normalise(array $raw): array
    {
        $plan = isset($raw['plan']) && is_string($raw['plan']) && in_array($raw['plan'], self::PLANS, true) ? $raw['plan'] : '';
        $limit = $raw['daily_limit'] ?? null;
        $limit = is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;
        $reserve = isset($raw['reserve']) && is_numeric($raw['reserve']) ? max(0, (int) $raw['reserve']) : self::DEFAULT_RESERVE;
        $ttl = isset($raw['catalogue_ttl_hours']) && is_numeric($raw['catalogue_ttl_hours']) ? (int) $raw['catalogue_ttl_hours'] : 24;
        $retention = isset($raw['retention_days']) && is_numeric($raw['retention_days']) ? (int) $raw['retention_days'] : self::DEFAULT_RETENTION_DAYS;
        return [
            'plan' => $plan,
            'api_key' => isset($raw['api_key']) && is_string($raw['api_key']) ? trim($raw['api_key']) : '',
            'daily_limit' => $limit,
            'reserve' => $reserve,
            'credit_line' => !array_key_exists('credit_line', $raw) || (bool) $raw['credit_line'],
            'include_snippet' => !empty($raw['include_snippet']),
            'kill_switch' => !empty($raw['kill_switch']),
            'debug_log' => !empty($raw['debug_log']),
            'catalogue_ttl_hours' => max(6, $ttl),
            'retention_days' => max(30, $retention),
        ];
    }

    /**
     * Validates a submitted settings form and returns the array to store.
     * A blank key field keeps the stored key.
     *
     * @param array<mixed> $input
     * @return array<string, mixed>
     */
    public function sanitise(array $input): array
    {
        $current = $this->all();
        $key = isset($input['api_key']) && is_string($input['api_key']) ? trim($input['api_key']) : '';
        if ($key === '' || !preg_match('/^[A-Za-z0-9]{8,128}$/', $key)) {
            $key = $current['api_key'];
        }
        $plan = isset($input['plan']) && is_string($input['plan']) && in_array($input['plan'], self::PLANS, true)
            ? $input['plan'] : $current['plan'];
        $limit = $input['daily_limit'] ?? '';
        $credit = !empty($input['credit_line']);
        if ($plan !== 'paid') {
            $credit = true;
        }
        return [
            'plan' => $plan,
            'api_key' => $key,
            'daily_limit' => is_numeric($limit) && (int) $limit > 0 ? min(1000000, (int) $limit) : null,
            'reserve' => isset($input['reserve']) && is_numeric($input['reserve']) ? min(10000, max(0, (int) $input['reserve'])) : self::DEFAULT_RESERVE,
            'credit_line' => $credit,
            'include_snippet' => !empty($input['include_snippet']),
            'kill_switch' => !empty($input['kill_switch']),
            'debug_log' => !empty($input['debug_log']),
            'catalogue_ttl_hours' => isset($input['catalogue_ttl_hours']) && is_numeric($input['catalogue_ttl_hours'])
                ? min(24 * 30, max(6, (int) $input['catalogue_ttl_hours'])) : 24,
            'retention_days' => isset($input['retention_days']) && is_numeric($input['retention_days'])
                ? min(3650, max(30, (int) $input['retention_days'])) : self::DEFAULT_RETENTION_DAYS,
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    public function save(array $values): bool
    {
        $this->cache = $values;
        if (get_option(self::OPTION, null) === null) {
            return add_option(self::OPTION, $values, '', false);
        }
        update_option(self::OPTION, $values, false);
        return true;
    }

    public function flush(): void
    {
        $this->cache = null;
    }

    /** The declared plan, '' when none has been chosen. */
    public function plan(): string
    {
        $constant = ($this->constant)('NEWSAPI_PULL_PLAN');
        if (is_string($constant) && in_array($constant, self::PLANS, true)) {
            return $constant;
        }
        return $this->all()['plan'];
    }

    public function planFromConstant(): bool
    {
        $constant = ($this->constant)('NEWSAPI_PULL_PLAN');
        return is_string($constant) && in_array($constant, self::PLANS, true);
    }

    public function apiKey(): string
    {
        $constant = ($this->constant)('NEWSAPI_PULL_API_KEY');
        if (is_string($constant) && trim($constant) !== '') {
            return trim($constant);
        }
        return $this->all()['api_key'];
    }

    public function keyFromConstant(): bool
    {
        $constant = ($this->constant)('NEWSAPI_PULL_API_KEY');
        return is_string($constant) && trim($constant) !== '';
    }

    /** The last four characters of the key, for display. */
    public function keyHint(): string
    {
        $key = $this->apiKey();
        return $key === '' ? '' : substr($key, -4);
    }

    /** A short fingerprint of the key, used to scope the meter and key errors. */
    public function keyFingerprint(): string
    {
        $key = $this->apiKey();
        return $key === '' ? 'none' : substr(hash('sha256', 'newsapi-pull|' . $key), 0, 16);
    }

    /** The daily limit, null when no local limit applies. */
    public function dailyLimit(): ?int
    {
        $limit = $this->all()['daily_limit'];
        if ($limit !== null) {
            return $limit;
        }
        return $this->plan() === 'paid' ? null : self::DEVELOPER_LIMIT;
    }

    public function reserve(): int
    {
        return $this->all()['reserve'];
    }

    /** The credit line can be turned off only on plan paid. */
    public function creditLine(): bool
    {
        return $this->plan() !== 'paid' || $this->all()['credit_line'];
    }

    public function includeSnippet(): bool
    {
        return $this->all()['include_snippet'];
    }

    /** The constant, when defined, wins over the setting. */
    public function killSwitch(): bool
    {
        $constant = ($this->constant)('NEWSAPI_PULL_DISABLED');
        if ($constant !== null) {
            return (bool) $constant;
        }
        return $this->all()['kill_switch'];
    }

    public function debugLog(): bool
    {
        return $this->all()['debug_log'];
    }

    public function catalogueTtl(): int
    {
        $ttl = apply_filters('newsapi_pull_catalogue_ttl', $this->all()['catalogue_ttl_hours'] * 3600);
        return max(6 * 3600, is_numeric($ttl) ? (int) $ttl : 86400);
    }

    public function retentionDays(): int
    {
        $days = apply_filters('newsapi_pull_retention_days', $this->all()['retention_days']);
        return max(30, is_numeric($days) ? (int) $days : self::DEFAULT_RETENTION_DAYS);
    }

    /**
     * The environment rule (PRD 4.2). Filters may only make it stricter.
     */
    public function environmentAllowed(): bool
    {
        $plan = $this->plan();
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        $base = $plan === 'paid' || ($plan === 'developer' && in_array($env, ['local', 'development'], true));
        $filtered = apply_filters('newsapi_pull_environment_allowed', $base, $env, $plan);
        return $base && $filtered === true;
    }
}
