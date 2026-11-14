<?php

declare(strict_types=1);

/**
 * In-memory state behind the WordPress function stubs. Reset before each test.
 */
final class WpStub
{
    /** @var array<int, array<string, mixed>> */
    public static array $options = [];
    /** @var array<int, array<string, bool>> */
    public static array $autoload = [];
    /** @var array<string, mixed> */
    public static array $siteOptions = [];
    /** @var array<int, array<string, mixed>> */
    public static array $transients = [];
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $filters = [];
    /** @var array<string, list<array<mixed>>> */
    public static array $fired = [];
    /** @var array<int, array<string, mixed>> */
    public static array $posts = [];
    /** @var array<int, array<string, mixed>> */
    public static array $meta = [];
    public static int $nextPostId = 100;
    /** @var array<string, array{0: int, 1: string, 2: string, 3: array<mixed>}> */
    public static array $cron = [];
    public static string $env = 'production';
    /** @var list<string> */
    public static array $caps = ['manage_options', 'edit_posts'];
    /** @var array<int, list<string>> */
    public static array $userCaps = [1 => ['edit_posts', 'manage_options']];
    public static int $userId = 1;
    /** @var list<string> Option names whose writes fail. */
    public static array $failWrites = [];
    /** @var list<mixed> Queued HTTP responses (arrays or WP_Error), consumed in order. */
    public static array $responses = [];
    /** @var list<array{url: string, args: array<string, mixed>}> */
    public static array $requests = [];
    public static int $failInserts = 0;
    /** @var (callable(string, int, string, string): mixed)|null */
    public static $sideload = null;
    public static bool $multisite = false;
    public static int $blog = 1;
    /** @var list<int> */
    public static array $blogStack = [];
    /** @var list<int> */
    public static array $sites = [1];
    public static bool $admin = true;
    public static string $home = 'https://my-site.test';
    public static string $tz = 'Europe/London';

    public static function reset(): void
    {
        self::$options = [];
        self::$autoload = [];
        self::$siteOptions = [];
        self::$transients = [];
        self::$filters = [];
        self::$fired = [];
        self::$posts = [];
        self::$meta = [];
        self::$nextPostId = 100;
        self::$cron = [];
        self::$env = 'production';
        self::$caps = ['manage_options', 'edit_posts'];
        self::$userCaps = [1 => ['edit_posts', 'manage_options']];
        self::$userId = 1;
        self::$failWrites = [];
        self::$responses = [];
        self::$requests = [];
        self::$failInserts = 0;
        self::$sideload = null;
        self::$multisite = false;
        self::$blog = 1;
        self::$blogStack = [];
        self::$sites = [1];
        self::$admin = true;
        self::$home = 'https://my-site.test';
        self::$tz = 'Europe/London';
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /**
     * A JSON HTTP response.
     *
     * @param array<mixed>|string $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public static function response(array|string $body, int $code = 200, array $headers = []): array
    {
        return [
            'headers' => array_change_key_case($headers, CASE_LOWER),
            'body' => is_array($body) ? (string) json_encode($body) : $body,
            'response' => ['code' => $code, 'message' => ''],
        ];
    }

    /**
     * @return list<array<mixed>>
     */
    public static function fired(string $hook): array
    {
        return self::$fired[$hook] ?? [];
    }
}

final class WP_Error
{
    public function __construct(private string $code = '', private string $message = '')
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_code(): string
    {
        return $this->code;
    }
}

final class WpDieException extends \RuntimeException
{
}

/**
 * A tiny in-memory stand-in for $wpdb that understands exactly the queries
 * the de-duplication store issues.
 */
final class FakeWpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables = [];
    /** @var list<string> */
    public array $queries = [];
    /** Fail every query whose SQL starts with this verb (SELECT, INSERT, DELETE), or '' for none. */
    public string $failOn = '';
    /** Report a duplicate-key error on the next INSERT regardless of content. */
    public bool $duplicateNext = false;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback('/%[sd]/', static function (array $m) use (&$i, $args): string {
            $value = $args[$i++] ?? '';
            return $m[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, $query);
    }

    public function create(string $table): void
    {
        $this->tables[$table] ??= [];
    }

    private function fail(string $sql): bool
    {
        $this->last_error = '';
        $this->queries[] = $sql;
        if ($this->failOn !== '' && stripos(ltrim($sql), $this->failOn) === 0) {
            $this->last_error = 'Simulated database failure';
            return true;
        }
        return false;
    }

    public function query(string $sql): int|bool
    {
        if ($this->fail($sql)) {
            return false;
        }
        if (preg_match('/^DROP TABLE IF EXISTS (\S+)/', $sql, $m)) {
            unset($this->tables[$m[1]]);
            return true;
        }
        if (preg_match('/^INSERT INTO (\S+) /', $sql, $m)) {
            $table = $m[1];
            if (!isset($this->tables[$table])) {
                $this->last_error = "Table '$table' doesn't exist";
                return false;
            }
            preg_match_all("/\\(UNHEX\\('([0-9a-f]+)'\\), (\\d+), (\\d+), (\\d+), '([^']*)', '([^']*)', '([^']*)'\\)/", $sql, $rows, PREG_SET_ORDER);
            $existing = array_column($this->tables[$table], 'key_hash');
            $new = array_column($rows, 1);
            if ($this->duplicateNext || array_intersect($existing, $new) !== [] || count($new) !== count(array_unique($new))) {
                $this->duplicateNext = false;
                $this->last_error = "Duplicate entry for key 'key_hash'";
                return false;
            }
            foreach ($rows as $r) {
                $this->tables[$table][] = [
                    'key_hash' => $r[1], 'key_kind' => (int) $r[2], 'key_version' => (int) $r[3],
                    'post_id' => (int) $r[4], 'reason' => $r[5], 'pull_slug' => $r[6], 'created_at' => $r[7],
                ];
            }
            return count($rows);
        }
        if (preg_match("/^DELETE FROM (\\S+) WHERE created_at < '([^']+)' LIMIT (\\d+)/", $sql, $m)) {
            $rows = $this->tables[$m[1]] ?? [];
            $deleted = 0;
            $keep = [];
            foreach ($rows as $row) {
                if ($deleted < (int) $m[3] && $row['created_at'] < $m[2]) {
                    $deleted++;
                } else {
                    $keep[] = $row;
                }
            }
            $this->tables[$m[1]] = $keep;
            return $deleted;
        }
        $this->last_error = 'Unsupported query in FakeWpdb: ' . $sql;
        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_row(string $sql, string $output = 'OBJECT'): ?array
    {
        if ($this->fail($sql)) {
            return null;
        }
        if (preg_match('/FROM (\S+) WHERE key_hash IN \((.+)\) LIMIT 1/', $sql, $m)) {
            if (!isset($this->tables[$m[1]])) {
                $this->last_error = "Table '{$m[1]}' doesn't exist";
                return null;
            }
            preg_match_all("/UNHEX\\('([0-9a-f]+)'\\)/", $m[2], $h);
            foreach ($this->tables[$m[1]] as $row) {
                if (in_array($row['key_hash'], $h[1], true)) {
                    return ['post_id' => (string) $row['post_id'], 'reason' => $row['reason']];
                }
            }
            return null;
        }
        $this->last_error = 'Unsupported query in FakeWpdb: ' . $sql;
        return null;
    }

    public function get_var(string $sql): ?string
    {
        if ($this->fail($sql)) {
            return null;
        }
        if (preg_match("/^SHOW TABLES LIKE '(.+)'$/", $sql, $m)) {
            $name = stripslashes(str_replace('\\_', '_', $m[1]));
            return isset($this->tables[$name]) ? $name : null;
        }
        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $suffix = 'newsapi_pull_seen'): array
    {
        return $this->tables[$this->prefix . $suffix] ?? [];
    }
}
