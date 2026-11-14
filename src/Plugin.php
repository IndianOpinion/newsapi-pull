<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

use IndianOpinion\NewsApiPull\Admin\AdminPage;
use IndianOpinion\NewsApiPull\Admin\PostsColumn;
use IndianOpinion\NewsApiPull\Cli\Command;
use IndianOpinion\NewsApiPull\Exception\NewsApiPullException;

/**
 * Builds the services and registers the hooks.
 */
final class Plugin
{
    private static ?self $instance = null;

    public readonly Clock $clock;
    public readonly Settings $settings;
    public readonly Logger $logger;
    public readonly PullRepository $pulls;
    public readonly Meter $meter;
    public readonly Lock $lock;
    public readonly State $state;
    public readonly SeenStore $seen;
    public readonly Keys $keys;
    public readonly Catalogue $catalogue;
    public readonly DraftWriter $writer;
    public readonly RunLog $log;
    public readonly Scheduler $scheduler;
    public readonly Runner $runner;

    /** @var \Closure(string, callable(Query): void): Client */
    private readonly \Closure $clientFactory;

    /**
     * @param (callable(string): void)|null $logSink
     * @param (callable(string, callable(Query): void): Client)|null $clientFactory
     */
    public function __construct(
        ?Clock $clock = null,
        ?Settings $settings = null,
        ?callable $logSink = null,
        ?callable $clientFactory = null,
        ?bool $network = null
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->settings = $settings ?? new Settings();
        $this->logger = new Logger($this->settings, $logSink);
        $this->pulls = new PullRepository();
        $this->meter = new Meter($this->settings, $this->clock, $this->logger, $network ?? self::isNetworkActive());
        $this->lock = new Lock($this->clock);
        $this->state = new State($this->settings, $this->clock);
        $this->seen = new SeenStore($this->clock);
        $this->keys = new Keys();
        $this->catalogue = new Catalogue($this->settings, $this->clock, $this->logger);
        $this->writer = new DraftWriter($this->settings, $this->logger);
        $this->log = new RunLog();
        $this->scheduler = new Scheduler($this->pulls, $this->clock);
        $this->clientFactory = \Closure::fromCallable($clientFactory ?? static fn (string $key, callable $before): Client => new Client($key, $before));
        $this->runner = new Runner(
            $this->settings,
            $this->pulls,
            $this->meter,
            $this->lock,
            $this->state,
            $this->seen,
            $this->keys,
            $this->catalogue,
            $this->writer,
            $this->log,
            $this->logger,
            $this->clock,
            $this->clientFactory
        );
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Replaces the shared instance (tests, or a site wiring its own services). */
    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    public static function basename(): string
    {
        return defined('NEWSAPI_PULL_FILE') ? plugin_basename((string) constant('NEWSAPI_PULL_FILE')) : 'newsapi-pull/newsapi-pull.php';
    }

    /** True when the plugin is network-activated on a multisite network. */
    public static function isNetworkActive(): bool
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return false;
        }
        $active = get_site_option('active_sitewide_plugins', []);
        return is_array($active) && isset($active[self::basename()]);
    }

    public function register(): void
    {
        add_filter('cron_schedules', [$this->scheduler, 'cronSchedules']);
        add_action(Scheduler::HOOK, [$this, 'onScheduledRun'], 10, 1);
        add_action(Scheduler::DAILY_HOOK, [$this, 'onDaily']);
        if (is_admin()) {
            (new AdminPage($this))->register();
            (new PostsColumn())->register();
        }
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('newsapi-pull', new Command($this));
        }
    }

    public function onScheduledRun(mixed $slug): void
    {
        if (is_string($slug) && $slug !== '') {
            $this->runner->run($slug, false, false, true);
        }
    }

    public function onDaily(): void
    {
        try {
            $this->seen->ensure();
            $deleted = $this->seen->prune($this->settings->retentionDays());
            $this->logger->debug(sprintf('Daily maintenance pruned %d rows.', $deleted));
        } catch (NewsApiPullException $e) {
            $this->logger->error('Daily maintenance failed: ' . $e->getMessage());
        }
    }

    /**
     * A catalogue fetcher that obeys the same rules as a run (kill switch,
     * plan, environment, key, meter), or null when a fetch is not allowed.
     *
     * @return (callable(): list<array<string, string>>)|null
     */
    public function catalogueFetcher(): ?callable
    {
        $refusal = $this->runner->preCheck(null, '', true);
        if ($refusal !== null && $refusal[0] !== 'pull_missing') {
            return null;
        }
        return function (): array {
            if ($this->meter->refusal(true) !== null) {
                throw new NewsApiPullException('The quota meter has no room for the source catalogue.');
            }
            $client = ($this->clientFactory)($this->settings->apiKey(), function (Query $q): void {
                $this->meter->count(null);
            });
            return $client->sources(Query::sources())->sources;
        };
    }

    /**
     * Activation: creates the table on this site (or every site when
     * network-wide) and schedules the daily event.
     */
    public static function activate(bool $networkWide = false): void
    {
        self::eachSite($networkWide, static function (): void {
            $plugin = new self();
            $plugin->seen->install();
            $plugin->scheduler->selfHeal();
        });
    }

    /**
     * Deactivation clears every scheduled event and the lock. Nothing else.
     */
    public static function deactivate(bool $networkWide = false): void
    {
        self::eachSite($networkWide, static function (): void {
            (new self())->scheduler->clearAll();
            Lock::clear();
        });
    }

    /**
     * @param callable(): void $fn
     */
    public static function eachSite(bool $networkWide, callable $fn): void
    {
        if (!$networkWide || !function_exists('is_multisite') || !is_multisite()) {
            $fn();
            return;
        }
        $sites = get_sites(['fields' => 'ids', 'number' => 0]);
        foreach ($sites as $siteId) {
            switch_to_blog((int) $siteId);
            try {
                $fn();
            } finally {
                restore_current_blog();
            }
        }
    }
}
