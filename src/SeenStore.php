<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

use IndianOpinion\NewsApiPull\Exception\DatabaseException;
use IndianOpinion\NewsApiPull\Exception\DuplicateKeyException;

/**
 * The de-duplication table: one row per key hash, unique index on the hash.
 *
 * After the first database error the store refuses every further call in
 * this request without issuing a query (fail closed, and no query storm).
 */
final class SeenStore
{
    public const TABLE = 'newsapi_pull_seen';

    public const DB_VERSION = 1;

    public const DB_VERSION_OPTION = 'newsapi_pull_db_version';

    public const KIND_CANONICAL = 0;

    public const KIND_PLAIN = 1;

    private bool $broken = false;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public function isBroken(): bool
    {
        return $this->broken;
    }

    /**
     * Creates the table when this site has not got it at the current version.
     */
    public function ensure(): void
    {
        if ((int) get_option(self::DB_VERSION_OPTION, 0) >= self::DB_VERSION) {
            return;
        }
        $this->install();
    }

    public function install(): void
    {
        global $wpdb;
        $table = $this->table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  key_hash binary(16) NOT NULL,
  key_kind tinyint(3) unsigned NOT NULL DEFAULT 0,
  key_version smallint(5) unsigned NOT NULL DEFAULT 0,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  reason varchar(32) NOT NULL DEFAULT '',
  pull_slug varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY key_hash (key_hash),
  KEY created_at (created_at)
) {$charset};";
        if (!function_exists('dbDelta')) {
            require_once (string) constant('ABSPATH') . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
        // dbDelta can skip silently; check the table really exists.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($found === $table) {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
        }
    }

    public function drop(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . $this->table());
        delete_option(self::DB_VERSION_OPTION);
    }

    /**
     * The first stored row matching any of the hashes (both in one query).
     *
     * @param list<string> $hashes Binary hashes.
     * @return array{post_id: int, reason: string}|null
     */
    public function find(array $hashes): ?array
    {
        $this->guard();
        if ($hashes === []) {
            return null;
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($hashes), 'UNHEX(%s)'));
        $hex = array_map('bin2hex', $hashes);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare("SELECT post_id, reason FROM {$this->table()} WHERE key_hash IN ({$placeholders}) LIMIT 1", ...$hex);
        $row = $wpdb->get_row($sql, ARRAY_A);
        $this->check();
        if (!is_array($row)) {
            return null;
        }
        return ['post_id' => (int) $row['post_id'], 'reason' => (string) $row['reason']];
    }

    /**
     * Records both keys of one article in one insert.
     *
     * @param array{canonical_hash: string, plain_hash: string, version: int} $keys
     * @throws DuplicateKeyException when a concurrent run stored a hash first.
     * @throws DatabaseException on any other database error.
     */
    public function record(array $keys, int $postId, string $reason, string $pull): void
    {
        $this->guard();
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s', $this->clock->now());
        $rows = [[$keys['canonical_hash'], self::KIND_CANONICAL, $keys['version']]];
        if ($keys['plain_hash'] !== $keys['canonical_hash']) {
            $rows[] = [$keys['plain_hash'], self::KIND_PLAIN, 0];
        }
        $values = [];
        $args = [];
        foreach ($rows as [$hash, $kind, $version]) {
            $values[] = '(UNHEX(%s), %d, %d, %d, %s, %s, %s)';
            array_push($args, bin2hex($hash), $kind, $version, $postId, substr($reason, 0, 32), substr($pull, 0, 64), $now);
        }
        $sql = $wpdb->prepare(
            "INSERT INTO {$this->table()} (key_hash, key_kind, key_version, post_id, reason, pull_slug, created_at) VALUES " . implode(', ', $values),
            ...$args
        );
        $result = $wpdb->query($sql);
        if ($result === false) {
            $error = (string) $wpdb->last_error;
            if (stripos($error, 'Duplicate entry') !== false) {
                throw new DuplicateKeyException('A key hash was stored by a concurrent run.');
            }
            $this->broken = true;
            throw new DatabaseException('Insert into the de-duplication table failed: ' . $error);
        }
    }

    /**
     * Deletes rows older than the retention period, in batches.
     *
     * @return int Rows deleted.
     */
    public function prune(int $days, int $batch = 1000, int $maxBatches = 50): int
    {
        $this->guard();
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', $this->clock->now() - max(30, $days) * 86400);
        $total = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$this->table()} WHERE created_at < %s LIMIT %d", $cutoff, $batch));
            $this->check();
            if (!is_int($deleted) || $deleted <= 0) {
                break;
            }
            $total += $deleted;
            if ($deleted < $batch) {
                break;
            }
        }
        return $total;
    }

    private function guard(): void
    {
        if ($this->broken) {
            throw new DatabaseException('The de-duplication table failed earlier in this request.');
        }
    }

    private function check(): void
    {
        global $wpdb;
        if ((string) $wpdb->last_error !== '') {
            $this->broken = true;
            throw new DatabaseException('De-duplication table query failed: ' . $wpdb->last_error);
        }
    }
}
