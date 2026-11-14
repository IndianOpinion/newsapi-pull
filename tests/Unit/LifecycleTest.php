<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Tests\Unit;

use IndianOpinion\NewsApiPull\Admin\AdminPage;
use IndianOpinion\NewsApiPull\Cli\Command;
use IndianOpinion\NewsApiPull\Lock;
use IndianOpinion\NewsApiPull\Meter;
use IndianOpinion\NewsApiPull\Plugin;
use IndianOpinion\NewsApiPull\Scheduler;
use IndianOpinion\NewsApiPull\Uninstaller;
use IndianOpinion\NewsApiPull\Tests\TestCase;

final class LifecycleTest extends TestCase
{
    private function handle(string $action, array $post = []): array
    {
        return (new AdminPage($this->plugin))->handle($action, $post + ['_wpnonce' => wp_create_nonce('newsapi_pull_' . $action)]);
    }

    public function testT88SaveSchedulesPauseClearsSelfHealRestores(): void
    {
        $this->configure();
        $this->handle('save_pull', ['slug' => 'top', 'endpoint' => 'headlines', 'country' => 'us', 'interval' => '30']);
        $next = wp_next_scheduled(Scheduler::HOOK, ['top']);
        self::assertSame(self::NOW + 1800, $next);
        self::assertSame('newsapi_pull_30min', \WpStub::$cron[Scheduler::HOOK . '|["top"]'][1]);
        $schedules = $this->plugin->scheduler->cronSchedules([]);
        self::assertSame(1800, $schedules['newsapi_pull_30min']['interval']);

        $this->handle('toggle_pause', ['slug' => 'top']);
        self::assertFalse(wp_next_scheduled(Scheduler::HOOK, ['top']));
        $this->handle('toggle_pause', ['slug' => 'top']);
        self::assertNotFalse(wp_next_scheduled(Scheduler::HOOK, ['top']));

        wp_clear_scheduled_hook(Scheduler::HOOK, ['top']);
        (new AdminPage($this->plugin))->adminInit();
        self::assertNotFalse(wp_next_scheduled(Scheduler::HOOK, ['top']));
        self::assertNotFalse(wp_next_scheduled(Scheduler::DAILY_HOOK));

        $this->handle('delete_pull', ['slug' => 'top']);
        self::assertFalse(wp_next_scheduled(Scheduler::HOOK, ['top']));
    }

    public function testRenamingAPullOntoAnotherSlugIsRefused(): void
    {
        $this->configure();
        $this->addPull(['slug' => 'one', 'country' => 'us']);
        $this->addPull(['slug' => 'two', 'country' => 'gb']);
        $result = $this->handle('save_pull', ['original_slug' => 'one', 'slug' => 'two', 'endpoint' => 'headlines', 'country' => 'de']);
        self::assertFalse($result['ok']);
        self::assertArrayHasKey('slug', $result['errors'] ?? []);
        self::assertSame('gb', $this->plugin->pulls->get('two')?->country);
        self::assertSame('us', $this->plugin->pulls->get('one')?->country);

        $result = $this->handle('save_pull', ['original_slug' => 'one', 'slug' => 'one', 'endpoint' => 'headlines', 'country' => 'fr']);
        self::assertTrue($result['ok']);
        self::assertSame('fr', $this->plugin->pulls->get('one')?->country);
    }

    public function testT89TooSoon(): void
    {
        $this->configure();
        $this->addPull(['interval' => 60]);
        $this->queueArticles([]);
        $this->plugin->onScheduledRun('top');
        $this->clock->advance(20 * 60);
        $this->plugin->onScheduledRun('top');
        self::assertCount(1, \WpStub::$requests);
        self::assertSame('too_soon', $this->runPull('top', false, false, true)['reason']);
        $this->clock->advance(15 * 60);
        $this->queueArticles([]);
        self::assertSame('ok', $this->runPull('top', false, false, true)['outcome']);
    }

    public function testT90RunDueRunsOnlyDuePulls(): void
    {
        $this->configure();
        $this->addPull(['slug' => 'due-one']);
        $this->addPull(['slug' => 'due-two']);
        $this->addPull(['slug' => 'not-due']);
        $this->addPull(['slug' => 'paused', 'paused' => true]);
        $this->plugin->pulls->saveState('not-due', ['last_run' => self::NOW - 600]);
        $this->queueArticles([]);
        $this->queueArticles([]);
        $finished = [];
        add_action('newsapi_pull_run_finished', static function (array $s) use (&$finished): void {
            $finished[] = $s['pull'];
        });
        (new Command($this->plugin))->run([], ['due' => true]);
        self::assertSame(['due-one', 'due-two'], $finished);
        self::assertCount(2, \WpStub::$requests);
        self::assertStringContainsString('2 due pulls run', implode("\n", \WP_CLI::$out));
        self::assertFalse(get_option(Lock::OPTION));
    }

    public function testT91ConstantKillSwitchWins(): void
    {
        $this->configure('paid', 'production', ['kill_switch' => false]);
        $this->addPull();
        $this->constants['NEWSAPI_PULL_DISABLED'] = true;
        self::assertSame('disabled', $this->runPull('top', true)['reason']);
        self::assertSame([], \WpStub::$requests);
        $this->constants['NEWSAPI_PULL_DISABLED'] = false;
        $this->configure('paid', 'production', ['kill_switch' => true]);
        $this->queueArticles([]);
        self::assertSame('ok', $this->runPull('top', true)['outcome'], 'the constant wins over the setting');
    }

    public function testKillSwitchSettingAndFilter(): void
    {
        $this->configure('paid', 'production', ['kill_switch' => true]);
        $this->addPull();
        self::assertSame('disabled', $this->runPull()['reason']);
        $this->configure();
        add_filter('newsapi_pull_enabled', static fn (): bool => false);
        self::assertSame('disabled', $this->runPull()['reason']);
    }

    public function testT92MultisiteSitesKeepSeparateMetersAndTables(): void
    {
        \WpStub::$multisite = true;
        \WpStub::$sites = [1, 2];
        $plugin = $this->makePlugin(false);
        Plugin::setInstance($plugin);
        $this->plugin = $plugin;
        foreach ([1, 2] as $blog) {
            switch_to_blog($blog);
            $plugin->settings->flush();
            $this->configure();
            $this->addPull();
            $this->queueArticles([$this->article('https://example.com/shared-story')]);
            $summary = $plugin->runner->run('top');
            self::assertSame(1, $summary['created'], "site $blog creates its own draft");
            self::assertSame(1, $plugin->meter->day()['total']);
            restore_current_blog();
        }
        self::assertArrayHasKey('wp_newsapi_pull_seen', $GLOBALS['wpdb']->tables);
        self::assertArrayHasKey('wp_2_newsapi_pull_seen', $GLOBALS['wpdb']->tables);
        self::assertCount(2, $GLOBALS['wpdb']->tables['wp_2_newsapi_pull_seen']);
        self::assertArrayHasKey(Meter::OPTION, \WpStub::$options[2]);
    }

    public function testT93DeactivationClearsEventsAndLockOnly(): void
    {
        $this->configure();
        $this->addPull();
        $this->plugin->scheduler->sync($this->plugin->pulls->get('top'));
        $this->plugin->scheduler->selfHeal();
        add_option(Lock::OPTION, self::NOW + 100);
        Plugin::deactivate();
        self::assertSame([], \WpStub::$cron);
        self::assertFalse(get_option(Lock::OPTION));
        self::assertNotFalse(get_option('newsapi_pull_pulls'));
        self::assertNotFalse(get_option('newsapi_pull_settings'));
    }

    public function testT94UninstallDropsTableAndOptionsButKeepsDrafts(): void
    {
        $this->configure();
        $this->addPull();
        $this->queueArticles([$this->article('https://example.com/keep-me')]);
        $summary = $this->runPull();
        $id = $summary['post_ids'][0];
        $meta = \WpStub::$meta[$id];
        $posts = \WpStub::$posts;
        set_transient('newsapi_pull_catalogue', ['x']);
        Uninstaller::run();
        self::assertSame([], $GLOBALS['wpdb']->tables);
        foreach (Uninstaller::OPTIONS as $option) {
            self::assertFalse(get_option($option), $option);
        }
        self::assertFalse(get_transient('newsapi_pull_catalogue'));
        self::assertSame([], \WpStub::$cron);
        self::assertSame($posts, \WpStub::$posts);
        self::assertSame($meta, \WpStub::$meta[$id]);
    }

    public function testActivationCreatesTheTable(): void
    {
        Plugin::activate();
        self::assertArrayHasKey('wp_newsapi_pull_seen', $GLOBALS['wpdb']->tables);
        self::assertSame(1, get_option('newsapi_pull_db_version'));
    }

    public function testDailyMaintenancePrunesOldRows(): void
    {
        $this->configure();
        $this->plugin->seen->install();
        $GLOBALS['wpdb']->tables['wp_newsapi_pull_seen'] = [
            ['key_hash' => 'aa', 'created_at' => gmdate('Y-m-d H:i:s', self::NOW - 200 * 86400), 'reason' => 'imported', 'post_id' => 1],
            ['key_hash' => 'bb', 'created_at' => gmdate('Y-m-d H:i:s', self::NOW - 10 * 86400), 'reason' => 'imported', 'post_id' => 2],
        ];
        $this->plugin->onDaily();
        self::assertSame(['bb'], array_column($GLOBALS['wpdb']->tables['wp_newsapi_pull_seen'], 'key_hash'));
    }

    public function testNoAutoloadedOptions(): void
    {
        $this->configure();
        $this->addPull();
        $this->queueArticles([$this->article('https://example.com/a')]);
        $this->runPull();
        foreach (\WpStub::$autoload[1] as $name => $autoload) {
            self::assertFalse($autoload, $name);
        }
    }
}
