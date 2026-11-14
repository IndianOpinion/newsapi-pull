<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Cli;

use IndianOpinion\NewsApiPull\Admin\AdminPage;
use IndianOpinion\NewsApiPull\Catalogue;
use IndianOpinion\NewsApiPull\Plugin;
use IndianOpinion\NewsApiPull\UrlKey;

/**
 * Manage NewsAPI pulls: run, validate, check the quota and keys, browse the
 * source catalogue, read the log, export and import.
 */
final class Command
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * Lists pulls with status, interval, last run and last outcome.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * @subcommand list
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function list_(array $args, array $assoc): void
    {
        $rows = [];
        foreach ($this->plugin->pulls->all() as $slug => $pull) {
            $state = $this->plugin->pulls->state($slug);
            $status = $pull->paused ? 'paused' : 'active';
            if ($state['disabled_reason'] !== '') {
                $status = 'disabled';
            }
            $rows[] = [
                'slug' => $slug,
                'endpoint' => $pull->endpoint,
                'status' => $status,
                'interval' => $pull->interval,
                'last_run' => (int) $state['last_run'] > 0 ? gmdate('Y-m-d H:i:s', (int) $state['last_run']) : 'never',
                'last_outcome' => trim((string) $state['last_outcome'] . ' ' . (string) $state['last_reason']),
            ];
        }
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['slug', 'endpoint', 'status', 'interval', 'last_run', 'last_outcome']);
    }

    /**
     * Runs one pull now, or every due pull.
     *
     * Obeys every rule a scheduled run obeys, including the environment rule
     * for the developer plan. There is no override flag.
     *
     * ## OPTIONS
     *
     * [<slug>]
     * : The pull to run.
     *
     * [--due]
     * : Run every pull that is due, one after another (for a system cron).
     *
     * [--dry-run]
     * : Send the requests (they count against the meter) and show what would be created; write nothing.
     *
     * [--yes]
     * : Skip the dry-run confirmation.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        if (!empty($assoc['due'])) {
            $summaries = $this->plugin->runner->runDue();
            foreach ($summaries as $summary) {
                $this->report($summary);
            }
            \WP_CLI::success(sprintf('%d due pulls run.', count($summaries)));
            return;
        }
        $slug = $args[0] ?? '';
        if ($slug === '') {
            \WP_CLI::error('Give a pull slug, or --due.');
        }
        $dry = !empty($assoc['dry-run']);
        if ($dry) {
            \WP_CLI::confirm('A dry run sends real requests that count against the daily quota. Continue?', $assoc);
        }
        $summary = $this->plugin->runner->run($slug, true, $dry);
        $this->report($summary);
        if ($summary['outcome'] === 'ok') {
            \WP_CLI::success('Done.');
            return;
        }
        if ($summary['outcome'] === 'skipped') {
            \WP_CLI::warning('The run did not start.');
            return;
        }
        \WP_CLI::error($this->plugin->logger->redact(sprintf('Run failed: %s. %s', (string) $summary['reason'], (string) $summary['message'])));
    }

    /**
     * Validates a pull without sending any request.
     *
     * ## OPTIONS
     *
     * <slug>
     * : The pull.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function validate(array $args, array $assoc): void
    {
        $pull = $this->plugin->pulls->get($args[0] ?? '');
        if ($pull === null) {
            \WP_CLI::error('No such pull.');
            return;
        }
        $errors = $pull->validate($this->plugin->settings->plan());
        foreach ($pull->warnings($this->plugin->catalogue->cachedIds()) as $warning) {
            \WP_CLI::warning($warning);
        }
        if ($errors === []) {
            \WP_CLI::success('The pull is valid.');
            return;
        }
        foreach ($errors as $field => $message) {
            \WP_CLI::line($field . ': ' . $message);
        }
        \WP_CLI::error(sprintf('%d validation errors.', count($errors)));
    }

    /**
     * Shows the quota meter.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function quota(array $args, array $assoc): void
    {
        $m = $this->plugin->meter->snapshot();
        if (($assoc['format'] ?? 'table') === 'json') {
            \WP_CLI::line((string) wp_json_encode($m));
            return;
        }
        $rows = [];
        foreach (['used', 'limit', 'reserve', 'remaining', 'reset', 'exhausted', 'source', 'scope'] as $k) {
            $v = $m[$k];
            $rows[] = ['field' => $k, 'value' => is_bool($v) ? ($v ? 'yes' : 'no') : ($v === null ? 'none' : (string) $v)];
        }
        foreach ($m['pulls'] as $slug => $n) {
            $rows[] = ['field' => 'pull ' . $slug, 'value' => (string) $n];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['field', 'value']);
    }

    /**
     * Shows both keys of a URL, their hashes, the version, and whether each is stored.
     *
     * ## OPTIONS
     *
     * <url>
     * : The article URL.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function check(array $args, array $assoc): void
    {
        $keys = $this->plugin->keys->forUrl($args[0] ?? '', 'check');
        if ($keys['status'] !== 'ok') {
            \WP_CLI::error('No key: ' . $keys['status'] . '.');
            return;
        }
        $this->plugin->seen->ensure();
        $rows = [];
        foreach (['canonical', 'plain'] as $kind) {
            $found = $this->plugin->seen->find([$keys[$kind . '_hash']]);
            $rows[] = [
                'kind' => $kind,
                'key' => $keys[$kind],
                'hash' => bin2hex($keys[$kind . '_hash']),
                'present' => $found === null ? 'no' : 'yes (' . $found['reason'] . ($found['post_id'] > 0 ? ', post ' . $found['post_id'] : '') . ')',
            ];
        }
        \WP_CLI::line('Key version: ' . UrlKey::VERSION);
        \WP_CLI\Utils\format_items('table', $rows, ['kind', 'key', 'hash', 'present']);
    }

    /**
     * Lists the source catalogue.
     *
     * ## OPTIONS
     *
     * [--country=<code>]
     * : Filter by country.
     *
     * [--language=<code>]
     * : Filter by language.
     *
     * [--category=<category>]
     * : Filter by category.
     *
     * [--refresh]
     * : Fetch a fresh copy (one request against the meter).
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function sources(array $args, array $assoc): void
    {
        $fetch = $this->plugin->catalogueFetcher();
        $copy = $this->plugin->catalogue->get($fetch, !empty($assoc['refresh']) && $fetch !== null);
        if ($copy === null) {
            \WP_CLI::error('No catalogue is available. Check the plan, environment, key and quota.');
            return;
        }
        if ($copy['stale']) {
            \WP_CLI::warning(sprintf('The catalogue is stale (fetched %s UTC).', gmdate('Y-m-d H:i', $copy['fetched_at'])));
        }
        $rows = Catalogue::filter(
            $copy['sources'],
            strtolower((string) ($assoc['language'] ?? '')),
            strtolower((string) ($assoc['country'] ?? '')),
            strtolower((string) ($assoc['category'] ?? ''))
        );
        \WP_CLI\Utils\format_items('table', $rows, ['id', 'name', 'country', 'language', 'category']);
    }

    /**
     * Shows the run log.
     *
     * ## OPTIONS
     *
     * [--lines=<n>]
     * : How many entries.
     * ---
     * default: 20
     * ---
     *
     * [--pull=<slug>]
     * : Only this pull.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function log(array $args, array $assoc): void
    {
        $entries = $this->plugin->log->all(isset($assoc['pull']) ? (string) $assoc['pull'] : null, max(1, (int) ($assoc['lines'] ?? 20)));
        $rows = [];
        foreach ($entries as $e) {
            $rej = [];
            foreach ((array) ($e['rejections'] ?? []) as $r => $n) {
                $rej[] = $r . ':' . (int) $n;
            }
            $rows[] = [
                'finished' => (string) ($e['finished'] ?? ''),
                'pull' => (string) ($e['pull'] ?? ''),
                'outcome' => trim((string) ($e['outcome'] ?? '') . ' ' . (string) ($e['reason'] ?? '')),
                'requests' => (int) ($e['requests'] ?? 0),
                'created' => (int) ($e['created'] ?? 0),
                'rejections' => implode(' ', $rej),
                'message' => $this->plugin->logger->redact((string) ($e['message'] ?? '')),
            ];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['finished', 'pull', 'outcome', 'requests', 'created', 'rejections', 'message']);
    }

    /**
     * Prints the pulls as JSON (never the key).
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function export(array $args, array $assoc): void
    {
        \WP_CLI::line((new AdminPage($this->plugin))->export());
    }

    /**
     * Imports pulls from a JSON file. Each is validated; key fields are ignored; imported pulls start paused.
     *
     * ## OPTIONS
     *
     * <file>
     * : The JSON file.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function import(array $args, array $assoc): void
    {
        $file = $args[0] ?? '';
        if ($file === '' || !is_readable($file)) {
            \WP_CLI::error('The file cannot be read.');
            return;
        }
        $result = (new AdminPage($this->plugin))->import((string) file_get_contents($file));
        foreach ($result['errors'] ?? [] as $slug => $message) {
            \WP_CLI::warning($slug . ': ' . $message);
        }
        if ($result['ok']) {
            \WP_CLI::success($result['message']);
        } else {
            \WP_CLI::error($result['message']);
        }
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function report(array $summary): void
    {
        $rej = [];
        foreach ((array) $summary['rejections'] as $r => $n) {
            $rej[] = $r . ': ' . (int) $n;
        }
        \WP_CLI::line($this->plugin->logger->redact(sprintf(
            '%s: %s%s. Requests %d, received %d, created %d, duplicates %d.%s%s',
            (string) $summary['pull'],
            (string) $summary['outcome'],
            $summary['reason'] !== '' ? ' (' . (string) $summary['reason'] . ')' : '',
            (int) $summary['requests'],
            (int) $summary['received'],
            (int) $summary['created'],
            (int) ($summary['duplicates'] ?? 0),
            $rej !== [] ? ' Rejections: ' . implode(', ', $rej) . '.' : '',
            (string) $summary['message'] !== '' ? ' ' . (string) $summary['message'] : ''
        )));
        foreach ((array) ($summary['would_create'] ?? []) as $title) {
            \WP_CLI::line('  would create: ' . (string) $title);
        }
        if (!empty($summary['dry_run'])) {
            \WP_CLI::line('Dry run: nothing was written.');
        }
    }
}
