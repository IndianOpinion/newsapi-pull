<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

use IndianOpinion\NewsApiPull\Exception\ApiException;
use IndianOpinion\NewsApiPull\Exception\DatabaseException;
use IndianOpinion\NewsApiPull\Exception\DuplicateKeyException;
use IndianOpinion\NewsApiPull\Exception\InsertException;
use IndianOpinion\NewsApiPull\Exception\NetworkException;
use IndianOpinion\NewsApiPull\Exception\NewsApiPullException;
use IndianOpinion\NewsApiPull\Exception\QuotaException;
use IndianOpinion\NewsApiPull\Exception\ResponseException;

/**
 * Runs one pull (PRD 4.5): pre-checks, lock, requests, article checks,
 * drafts, and one state write at the end.
 */
final class Runner
{
    public const KEY_ERRORS = ['apiKeyMissing', 'apiKeyInvalid', 'apiKeyDisabled'];

    public const PULL_ERRORS = ['parameterInvalid', 'parametersMissing', 'sourcesTooMany', 'sourceDoesNotExist'];

    /** Codes that end paging cleanly: the plan cannot page further. */
    public const PAGING_LIMIT_ERRORS = ['maximumResultsReached'];

    /** Reasons that count as a failure for the consecutive-failure back-off. */
    public const FAILURE_REASONS = ['api_error', 'network', 'bad_response'];

    /** Refusals not written to the run log, because they repeat on every tick. */
    public const QUIET_REASONS = ['locked', 'too_soon', 'paused', 'pull_disabled', 'backoff'];

    public const MAX_INSERT_ERRORS = 3;

    /** @var \Closure(string, callable(Query): void): Client */
    private readonly \Closure $clientFactory;

    /** @var list<array<string, string>>|null The catalogue for the run in progress, for the language check. */
    private ?array $runCatalogue = null;

    /**
     * @param (callable(string, callable(Query): void): Client)|null $clientFactory
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly PullRepository $pulls,
        private readonly Meter $meter,
        private readonly Lock $lock,
        private readonly State $state,
        private readonly SeenStore $seen,
        private readonly Keys $keys,
        private readonly Catalogue $catalogue,
        private readonly DraftWriter $writer,
        private readonly RunLog $log,
        private readonly Logger $logger,
        private readonly Clock $clock,
        ?callable $clientFactory = null
    ) {
        $this->clientFactory = \Closure::fromCallable($clientFactory ?? static fn (string $key, callable $before): Client => new Client($key, $before));
    }

    /**
     * Runs one pull and returns its summary.
     *
     * @param bool $manual Run now or WP-CLI: may use the reserve, ignores pause and failure back-off.
     * @param bool $dryRun Sends requests (they count) but writes no draft, record or state.
     * @param bool $scheduled From WP-Cron: skips with `too_soon` when the pull ran less than half an interval ago.
     * @return array<string, mixed>
     */
    public function run(string $slug, bool $manual = false, bool $dryRun = false, bool $scheduled = false): array
    {
        $started = $this->clock->now();
        $summary = [
            'pull' => $slug,
            'manual' => $manual,
            'dry_run' => $dryRun,
            'outcome' => 'ok',
            'reason' => '',
            'message' => '',
            'requests' => 0,
            'received' => 0,
            'created' => 0,
            'duplicates' => 0,
            'rejections' => [],
            'post_ids' => [],
            'would_create' => [],
            'left_out' => 0,
            'started' => gmdate('Y-m-d\TH:i:s\Z', $started),
            'finished' => '',
        ];

        $pull = $this->pulls->get($slug);
        $refusal = $this->preCheck($pull, $slug, $manual);
        if ($refusal !== null) {
            return $this->finish($summary, 'skipped', $refusal[0], $refusal[1], null, $dryRun);
        }
        /** @var PullDefinition $pull */
        if (!$this->lock->acquire()) {
            return $this->finish($summary, 'skipped', 'locked', 'Another run holds the lock.', null, true);
        }
        try {
            $state = $this->pulls->state($slug);
            $lastRun = (int) $state['last_run'];
            if ($scheduled && $lastRun > 0 && $started - $lastRun < $pull->interval * 30) {
                return $this->finish($summary, 'skipped', 'too_soon', 'The pull ran less than half an interval ago.', null, true);
            }
            $meterRefusal = $this->meter->refusal($manual, $slug, $pull->dailyCap);
            if ($meterRefusal !== null) {
                return $this->finish($summary, 'skipped', $meterRefusal, $this->quotaMessage($meterRefusal), null, $dryRun);
            }
            $this->seen->ensure();
            $this->execute($pull, $summary, $state, $manual, $dryRun, $started);
        } catch (\Throwable $e) {
            $this->logger->error('Run of "' . $slug . '" stopped by an exception: ' . $e->getMessage());
            $summary['outcome'] = 'error';
            $summary['reason'] = 'exception';
            $summary['message'] = $this->logger->redact($e->getMessage());
        } finally {
            $this->lock->release();
        }
        return $this->finish($summary, $summary['outcome'], $summary['reason'], $summary['message'], $pull, $dryRun);
    }

    /**
     * Runs every pull that is due, one after another.
     *
     * @return list<array<string, mixed>>
     */
    public function runDue(): array
    {
        $out = [];
        $now = $this->clock->now();
        foreach ($this->pulls->all() as $slug => $pull) {
            $state = $this->pulls->state($slug);
            if ($pull->paused || $state['disabled_reason'] !== '') {
                continue;
            }
            $last = (int) $state['last_run'];
            if ($last > 0 && $now - $last < $pull->interval * 60) {
                continue;
            }
            $out[] = $this->run($slug, false, false, false);
        }
        return $out;
    }

    /**
     * The pre-checks of case 34, in order. Null when the run may proceed.
     *
     * @return array{0: string, 1: string}|null
     */
    public function preCheck(?PullDefinition $pull, string $slug, bool $manual): ?array
    {
        if ($this->settings->killSwitch() || apply_filters('newsapi_pull_enabled', true) !== true) {
            return ['disabled', 'The kill switch is on.'];
        }
        $plan = $this->settings->plan();
        if ($plan === '') {
            return ['plan_missing', 'Choose a plan in the settings before any pull runs.'];
        }
        if (!$this->settings->environmentAllowed()) {
            return ['plan_environment', 'The developer plan is for development and testing only; this environment needs plan paid.'];
        }
        if ($this->settings->apiKey() === '') {
            return ['key_missing', 'No API key is set.'];
        }
        $keyError = $this->state->keyError();
        if ($keyError !== '') {
            return ['key_error', sprintf('The API rejected the key (%s). Change the key to resume.', $keyError)];
        }
        if ($pull === null) {
            return ['pull_missing', sprintf('No pull with slug "%s".', $slug)];
        }
        $errors = $pull->validate($plan);
        if ($errors !== []) {
            return ['pull_invalid', (string) reset($errors)];
        }
        $state = $this->pulls->state($slug);
        if ($state['disabled_reason'] !== '') {
            return ['pull_disabled', 'Disabled after an API error: ' . (string) $state['disabled_message'] . ' Edit and save the pull to re-enable it.'];
        }
        if ($pull->paused && !$manual) {
            return ['paused', 'The pull is paused.'];
        }
        if ($this->state->backoffActive()) {
            return ['backoff', 'Rate limited; every pull waits until ' . gmdate('H:i', $this->state->backoffUntil()) . ' UTC.'];
        }
        if (
            !$manual && (int) $state['failures'] >= 5
            && $this->clock->now() - (int) $state['last_run'] < $pull->interval * 60 * 4
        ) {
            return ['backoff', 'Five or more consecutive failures; the pull runs at four times its interval until one succeeds.'];
        }
        return null;
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $state
     */
    private function execute(PullDefinition $pull, array &$summary, array $state, bool $manual, bool $dryRun, int $started): void
    {
        $plan = $this->settings->plan();
        $client = ($this->clientFactory)($this->settings->apiKey(), function (Query $q) use (&$summary, $pull): void {
            $summary['requests']++;
            $this->meter->count($q->endpoint === 'sources' ? null : $pull->slug);
        });

        $catalogueFetch = function () use ($client): array {
            if ($this->meter->refusal(true) !== null) {
                throw new NewsApiPullException('The quota meter has no room for the source catalogue.');
            }
            return $client->sources(Query::sources())->sources;
        };

        $languageSources = null;
        $catalogueSources = null;
        if ($pull->language !== '') {
            $copy = $this->catalogue->get($catalogueFetch);
            $catalogueSources = $copy['sources'] ?? null;
            if ($pull->endpoint === 'headlines' && $pull->sources === []) {
                if ($catalogueSources === null) {
                    $this->fail($summary, 'catalogue_unavailable', 'The source catalogue is unavailable, so the language cannot be applied.');
                    return;
                }
                $match = array_column(Catalogue::filter($catalogueSources, $pull->language, $pull->country, $pull->category), 'id');
                if ($match === []) {
                    $this->fail($summary, 'no_sources_for_language', sprintf('No catalogue source matches language "%s".', $pull->language));
                    return;
                }
                $summary['left_out'] = max(0, count($match) - PullDefinition::MAX_SOURCES);
                $languageSources = array_slice($match, 0, PullDefinition::MAX_SOURCES);
            }
        }

        $from = $pull->endpoint === 'everything' ? $this->windowStart($plan, (int) $state['last_success']) : null;
        $maxPages = $plan === 'developer' ? 1 : $pull->maxPages;
        $cap = apply_filters('newsapi_pull_max_drafts_per_run', 50);
        $cap = max(1, is_numeric($cap) ? (int) $cap : 50);
        $this->runCatalogue = $catalogueSources;
        $ctx = ['inrun' => [], 'insert_errors' => 0, 'stop' => '', 'cap' => $cap];

        for ($page = 1; $page <= $maxPages; $page++) {
            if ($page > 1) {
                $refusal = $this->meter->refusal($manual, $pull->slug, $pull->dailyCap);
                if ($refusal !== null) {
                    $summary['message'] = $this->quotaMessage($refusal) . ' Paging stopped early; drafts already made are kept.';
                    break;
                }
            }
            $query = Query::forPull($pull, $page, $from, $languageSources);
            try {
                $response = $pull->endpoint === 'headlines' ? $client->headlines($query) : $client->everything($query);
            } catch (QuotaException $e) {
                $this->meter->markExhausted('api');
                if ($e->isRateLimit()) {
                    $backoff = apply_filters('newsapi_pull_rate_limit_backoff', 900);
                    $this->state->setBackoff(max(60, is_numeric($backoff) ? (int) $backoff : 900));
                    $this->fail($summary, 'rate_limited', 'Rate limited by the API: ' . $e->getMessage());
                } else {
                    $this->fail($summary, 'quota_exhausted', 'The API says the daily quota is used up: ' . $e->getMessage());
                }
                return;
            } catch (ApiException $e) {
                $code = $e->apiCode();
                if (in_array($code, self::PAGING_LIMIT_ERRORS, true)) {
                    $summary['message'] = 'The plan allows no further pages (' . $code . ').';
                    break;
                }
                if (in_array($code, self::KEY_ERRORS, true)) {
                    if (!$dryRun) {
                        $this->state->setKeyError($code);
                    }
                    $this->fail($summary, 'key_error', $code . ': ' . $e->getMessage());
                    return;
                }
                if (in_array($code, self::PULL_ERRORS, true)) {
                    $summary['disable'] = $code . ': ' . $e->getMessage();
                    $this->fail($summary, 'pull_invalid', $code . ': ' . $e->getMessage());
                    return;
                }
                $this->logger->warning(sprintf('API error on pull "%s": code %s, message %s', $pull->slug, $code, $e->getMessage()));
                $this->fail($summary, 'api_error', $code . ': ' . $e->getMessage());
                return;
            } catch (NetworkException $e) {
                $this->fail($summary, 'network', $e->getMessage());
                return;
            } catch (ResponseException $e) {
                $this->logger->debug('Bad response on pull "' . $pull->slug . '": ' . $e->getMessage() . ' First bytes: ' . $e->snippet());
                $this->fail($summary, 'bad_response', $e->getMessage());
                return;
            }
            if ($response->remaining !== null) {
                $this->meter->applyRemaining($response->remaining);
            }
            $count = count($response->articles);
            $summary['received'] += $count;
            $seenOnPage = 0;
            foreach ($response->articles as $raw) {
                if ($summary['created'] + count($summary['would_create']) >= $ctx['cap']) {
                    $ctx['stop'] = 'cap';
                    break;
                }
                $result = $this->process($raw, $pull, $plan, $summary, $ctx, $dryRun, $started);
                if ($result === 'seen') {
                    $seenOnPage++;
                }
                if ($ctx['stop'] !== '') {
                    break;
                }
            }
            if ($ctx['stop'] === 'db_error') {
                $this->fail($summary, 'db_error', 'The de-duplication table failed; no more drafts this run.');
                return;
            }
            if ($ctx['stop'] === 'insert_error') {
                $this->fail($summary, 'insert_error', sprintf('%d draft inserts failed; the run stopped.', self::MAX_INSERT_ERRORS));
                return;
            }
            if ($ctx['stop'] === 'cap') {
                $summary['message'] = sprintf('The per-run draft cap of %d was reached.', $ctx['cap']);
                break;
            }
            if ($count > 0 && $seenOnPage === $count) {
                break;
            }
            if ($count < $pull->pageSize || $page * $pull->pageSize >= $response->totalResults) {
                break;
            }
        }
    }

    /**
     * Handles one article. Returns `created`, `seen`, `rejected` or `stop`.
     *
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $ctx
     */
    private function process(mixed $raw, PullDefinition $pull, string $plan, array &$summary, array &$ctx, bool $dryRun, int $now): string
    {
        $a = Article::normalise($raw);

        $placeholderHosts = Vocabulary::stringList(apply_filters('newsapi_pull_placeholder_hosts', ['removed.com']));
        $urlHost = '';
        if ($a['url_valid']) {
            $parts = UrlKey::parse(UrlKey::prepare($a['url']));
            $urlHost = $parts === null ? '' : UrlKey::asciiLower(rtrim($parts['host'], '.'));
        }
        if (trim($a['title']) === '[Removed]' || ($urlHost !== '' && Keys::hostIn($urlHost, $placeholderHosts))) {
            return $this->reject('removed', $a, $pull, $summary, $ctx, null, $dryRun);
        }
        if (!$a['url_valid']) {
            return $this->reject('bad_url', $a, $pull, $summary, $ctx, null, $dryRun);
        }
        $keys = $this->keys->forUrl($a['url']);
        if ($keys['status'] !== 'ok') {
            return $this->reject($keys['status'], $a, $pull, $summary, $ctx, null, $dryRun);
        }
        $a['url'] = $keys['url'];
        if (in_array($keys['host'], $this->keys->ownHosts(), true)) {
            return $this->reject('own_host', $a, $pull, $summary, $ctx, null, $dryRun);
        }

        $hashes = [bin2hex($keys['canonical_hash']), bin2hex($keys['plain_hash'])];
        if (isset($ctx['inrun'][$hashes[0]]) || isset($ctx['inrun'][$hashes[1]])) {
            return $this->seen($a, $pull, $summary);
        }
        try {
            $found = $this->seen->find([$keys['canonical_hash'], $keys['plain_hash']]);
        } catch (DatabaseException $e) {
            $this->dbError($e, $ctx);
            return 'stop';
        }
        if ($found !== null) {
            $ctx['inrun'][$hashes[0]] = $ctx['inrun'][$hashes[1]] = true;
            return $this->seen($a, $pull, $summary);
        }

        $reason = $this->check($a, $pull, $plan, $ctx, $now);
        if ($reason !== '') {
            return $this->reject($reason, $a, $pull, $summary, $ctx, $keys, $dryRun);
        }

        $ctx['inrun'][$hashes[0]] = $ctx['inrun'][$hashes[1]] = true;
        if ($dryRun) {
            $summary['would_create'][] = Article::cleanTitle($a['title'], $a['source_name']);
            return 'created';
        }
        try {
            $postId = $this->writer->write($a, $pull, $hashes[0], $now);
        } catch (InsertException $e) {
            $this->logger->warning($e->getMessage());
            unset($ctx['inrun'][$hashes[0]], $ctx['inrun'][$hashes[1]]);
            $ctx['insert_errors']++;
            if ($ctx['insert_errors'] >= self::MAX_INSERT_ERRORS) {
                $ctx['stop'] = 'insert_error';
            }
            return 'rejected';
        }
        try {
            $this->seen->record($keys, $postId, 'imported', $pull->slug);
        } catch (DuplicateKeyException $e) {
            wp_trash_post($postId);
            $this->logger->warning(sprintf('Draft %d duplicated an article stored by a concurrent run; it was moved to the trash.', $postId));
            return $this->seen($a, $pull, $summary);
        } catch (DatabaseException $e) {
            $this->dbError($e, $ctx);
            return 'stop';
        }
        $summary['created']++;
        $summary['post_ids'][] = $postId;
        do_action('newsapi_pull_draft_created', $postId, $a, $pull);
        return 'created';
    }

    /**
     * The article checks of 4.7 that need a key. Returns '' to accept.
     *
     * @param array<string, mixed> $a Normalised article; gains `published_ts` and `flags`.
     * @param array<string, mixed> $ctx
     */
    private function check(array &$a, PullDefinition $pull, string $plan, array &$ctx, int $now): string
    {
        $a['flags'] = [];
        if (!$a['title_valid'] || Article::cleanTitle($a['title'], '') === '') {
            return 'no_title';
        }
        $deniedSources = Vocabulary::stringList(apply_filters('newsapi_pull_denied_sources', []));
        $deniedHosts = Vocabulary::stringList(apply_filters('newsapi_pull_denied_hosts', []));
        $parts = UrlKey::parse($a['url']);
        $host = $parts === null ? '' : UrlKey::asciiLower(rtrim($parts['host'], '.'));
        if (($a['source_id'] !== '' && in_array(strtolower($a['source_id']), $deniedSources, true)) || Keys::hostIn($host, $deniedHosts)) {
            return 'source_denied';
        }
        if ($pull->language !== '' && $this->runCatalogue !== null && $a['source_id'] !== '') {
            $lang = Catalogue::languageOf($this->runCatalogue, $a['source_id']);
            if ($lang !== null && $lang !== '' && $lang !== $pull->language) {
                return 'language_mismatch';
            }
        }
        $ts = $a['published_at'] !== '' ? strtotime($a['published_at']) : false;
        if ($ts === false) {
            $ts = $now;
            $a['flags'][] = 'date_missing';
        } elseif ($ts > $now + 3600) {
            $ts = $now;
            $a['flags'][] = 'date_clamped';
        }
        $a['published_ts'] = $ts;
        $maxAge = $pull->maxAgeHours ?? ($plan === 'developer' ? 96 : 72);
        if (!in_array('date_missing', $a['flags'], true) && $now - $ts > $maxAge * 3600) {
            return 'too_old';
        }
        $verdict = apply_filters('newsapi_pull_accept_article', '', $a, $pull);
        if (is_string($verdict) && trim($verdict) !== '') {
            $slug = sanitize_title($verdict);
            return $slug !== '' ? substr($slug, 0, 32) : 'filtered';
        }
        return '';
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $summary
     */
    private function seen(array $a, PullDefinition $pull, array &$summary): string
    {
        $summary['duplicates']++;
        $summary['rejections']['seen'] = ($summary['rejections']['seen'] ?? 0) + 1;
        do_action('newsapi_pull_article_rejected', 'seen', $a, $pull->slug);
        return 'seen';
    }

    /**
     * Counts a rejection and, when the URL has keys, records it so the next
     * run does not look at it again.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $ctx
     * @param array{status: string, url: string, canonical: string, plain: string, canonical_hash: string, plain_hash: string, version: int, host: string}|null $keys
     */
    private function reject(string $reason, array $a, PullDefinition $pull, array &$summary, array &$ctx, ?array $keys, bool $dryRun): string
    {
        $summary['rejections'][$reason] = ($summary['rejections'][$reason] ?? 0) + 1;
        $this->logger->debug(sprintf('Rejected (%s) on pull "%s": %s', $reason, $pull->slug, (string) $a['url']));
        do_action('newsapi_pull_article_rejected', $reason, $a, $pull->slug);
        if ($keys !== null) {
            $ctx['inrun'][bin2hex($keys['canonical_hash'])] = $ctx['inrun'][bin2hex($keys['plain_hash'])] = true;
            if (!$dryRun) {
                try {
                    $this->seen->record($keys, 0, $reason, $pull->slug);
                } catch (DuplicateKeyException $e) {
                    // Recorded by a concurrent run; nothing to do.
                } catch (DatabaseException $e) {
                    $this->dbError($e, $ctx);
                    return 'stop';
                }
            }
        }
        return 'rejected';
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function dbError(DatabaseException $e, array &$ctx): void
    {
        if ($ctx['stop'] !== 'db_error') {
            $this->logger->error($e->getMessage());
        }
        $ctx['stop'] = 'db_error';
    }

    /**
     * The oldest date for an everything search (case 18).
     */
    public function windowStart(string $plan, int $lastSuccess): string
    {
        $now = $this->clock->now();
        $overlap = apply_filters('newsapi_pull_window_overlap', 6 * 3600);
        $overlap = max(0, is_numeric($overlap) ? (int) $overlap : 6 * 3600);
        $lookback = apply_filters('newsapi_pull_lookback', 2 * 86400);
        $lookback = max(3600, is_numeric($lookback) ? (int) $lookback : 2 * 86400);
        if ($plan === 'developer') {
            $lookback = min($lookback, 29 * 86400);
        }
        $oldest = $now - $lookback;
        $from = $lastSuccess > 0 ? max($lastSuccess - $overlap, $oldest) : $oldest;
        return gmdate('Y-m-d\TH:i:s', $from);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function fail(array &$summary, string $reason, string $message): void
    {
        $summary['outcome'] = 'error';
        $summary['reason'] = $reason;
        $summary['message'] = $this->logger->redact($message);
    }

    private function quotaMessage(string $reason): string
    {
        return $reason === 'pull_quota'
            ? 'This pull reached its own daily cap.'
            : 'The daily request quota has no room for this run (resets at ' . gmdate('H:i', $this->meter->resetAt()) . ' UTC).';
    }

    /**
     * Writes state and the run log once, fires the finished action.
     *
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function finish(array $summary, string $outcome, string $reason, string $message, ?PullDefinition $pull, bool $noWrite): array
    {
        $summary['outcome'] = $outcome;
        $summary['reason'] = $reason;
        $summary['message'] = $this->logger->redact($message);
        $summary['finished'] = gmdate('Y-m-d\TH:i:s\Z', $this->clock->now());
        ksort($summary['rejections']);
        $disable = $summary['disable'] ?? null;
        unset($summary['disable']);

        if (!$noWrite && $pull !== null) {
            $state = $this->pulls->state($pull->slug);
            $startedTs = (int) strtotime((string) $summary['started']);
            $state['last_run'] = $startedTs;
            $state['last_outcome'] = $outcome;
            $state['last_reason'] = $reason;
            $state['left_out'] = $summary['left_out'];
            $state['last_summary'] = array_intersect_key($summary, array_flip(['outcome', 'reason', 'requests', 'received', 'created', 'duplicates', 'rejections', 'finished']));
            if ($outcome === 'ok') {
                $state['last_success'] = $startedTs;
                $state['failures'] = 0;
            } elseif (in_array($reason, self::FAILURE_REASONS, true)) {
                $state['failures'] = (int) $state['failures'] + 1;
            }
            if (is_string($disable)) {
                $state['disabled_reason'] = 'pull_invalid';
                $state['disabled_message'] = $this->logger->redact($disable);
            }
            $this->pulls->saveState($pull->slug, $state);
        }
        if (!$summary['dry_run'] && !in_array($reason, self::QUIET_REASONS, true)) {
            $this->log->add($summary);
        }
        $this->logger->debug(sprintf(
            'Run "%s": %s %s, %d requests, %d received, %d created, %d duplicates.',
            (string) $summary['pull'],
            $outcome,
            $reason,
            (int) $summary['requests'],
            (int) $summary['received'],
            (int) $summary['created'],
            (int) $summary['duplicates']
        ));
        do_action('newsapi_pull_run_finished', $summary);
        return $summary;
    }
}
