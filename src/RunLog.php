<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * A ring buffer of the last run summaries, written once per run.
 */
final class RunLog
{
    public const OPTION = 'newsapi_pull_log';

    public function size(): int
    {
        $size = apply_filters('newsapi_pull_log_size', 200);
        return min(5000, max(10, is_numeric($size) ? (int) $size : 200));
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function add(array $summary): void
    {
        $entries = $this->all();
        $entries[] = $summary;
        $entries = array_slice($entries, -$this->size());
        update_option(self::OPTION, $entries, false);
    }

    /**
     * Newest last.
     *
     * @return list<array<string, mixed>>
     */
    public function all(?string $pull = null, ?int $lines = null): array
    {
        $value = get_option(self::OPTION, []);
        $entries = [];
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (is_array($entry) && ($pull === null || ($entry['pull'] ?? '') === $pull)) {
                    $entries[] = $entry;
                }
            }
        }
        if ($lines !== null && $lines > 0) {
            $entries = array_slice($entries, -$lines);
        }
        return $entries;
    }
}
