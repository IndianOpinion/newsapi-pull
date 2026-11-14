<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Tests\Unit;

use IndianOpinion\NewsApiPull\Meter;
use IndianOpinion\NewsApiPull\Tests\TestCase;

final class QuotaMeterTest extends TestCase
{
    public function testT21TimeoutAndCatalogueRequestsAreCounted(): void
    {
        $this->configure();
        $this->addPull();
        \WpStub::$responses[] = new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
        $summary = $this->runPull();
        self::assertSame('network', $summary['reason']);
        self::assertSame(1, $this->plugin->meter->day()['total']);

        $fetch = $this->plugin->catalogueFetcher();
        self::assertNotNull($fetch);
        $this->queueSources($this->catalogue());
        $this->plugin->catalogue->get($fetch, true);
        self::assertSame(2, $this->plugin->meter->day()['total']);
        self::assertSame(1, $this->plugin->meter->day()['pulls']['_catalogue']);
    }

    public function testT22DefaultLimits(): void
    {
        $this->configure('developer', 'local');
        self::assertSame(100, $this->plugin->settings->dailyLimit());
        $this->configure('paid');
        self::assertNull($this->plugin->settings->dailyLimit());
        $this->configure('paid', 'production', ['daily_limit' => 500]);
        self::assertSame(500, $this->plugin->settings->dailyLimit());
    }

    public function testDefaultReserveIsTwentyRequests(): void
    {
        $this->configure('developer', 'local');
        self::assertSame(20, $this->plugin->meter->snapshot()['reserve']);
    }

    private function setUsed(int $used): void
    {
        update_option(Meter::OPTION, [$this->plugin->settings->keyFingerprint() => [gmdate('Y-m-d', self::NOW) => ['total' => $used]]]);
    }

    public function testT23ReserveIsForManualRunsAndNothingRunsAtTheLimit(): void
    {
        $this->configure('developer', 'local', ['reserve' => 10]);
        $this->addPull();
        $this->setUsed(90);
        self::assertSame('quota', $this->runPull()['reason']);
        self::assertSame([], \WpStub::$requests);
        $this->setUsed(89);
        $this->queueArticles([]);
        self::assertSame('ok', $this->runPull()['outcome']);

        $this->setUsed(90);
        $this->queueArticles([]);
        self::assertSame('ok', $this->runPull('top', true)['outcome']);
        self::assertCount(2, \WpStub::$requests);

        $this->setUsed(100);
        self::assertSame('quota', $this->runPull('top', true)['reason']);
        self::assertCount(2, \WpStub::$requests);
    }

    public function testT24ThreePageRunWithOneRequestLeft(): void
    {
        $this->configure('paid', 'production', ['daily_limit' => 100, 'reserve' => 0]);
        $this->addPull(['page_size' => 2, 'max_pages' => 3]);
        $this->setUsed(99);
        $this->queueArticles([$this->article('https://a.example.com/1'), $this->article('https://a.example.com/2')], 6);
        $summary = $this->runPull();
        self::assertSame(1, $summary['requests']);
        self::assertSame(2, $summary['created']);
        self::assertCount(2, $this->drafts());
        self::assertStringContainsString('Paging stopped early', $summary['message']);
    }

    public function testT25ExhaustedAndRateLimited(): void
    {
        $this->configure();
        $this->addPull();
        $this->queueError('apiKeyExhausted', 429);
        $summary = $this->runPull();
        self::assertSame('quota_exhausted', $summary['reason']);
        self::assertTrue($this->plugin->meter->day()['exhausted']);
        self::assertSame('api', $this->plugin->meter->day()['exhausted_source']);
        self::assertSame([['api']], \WpStub::fired('newsapi_pull_quota_exhausted'));
        self::assertSame('quota', $this->runPull('top', true)['reason']);

        \WpStub::reset();
        $this->setUp();
        $this->configure();
        $this->addPull(['max_pages' => 3, 'page_size' => 1]);
        $this->queueError('rateLimited', 429);
        $this->queueArticles([$this->article('https://a.example.com/1')], 3);
        $summary = $this->runPull();
        self::assertSame('rate_limited', $summary['reason']);
        self::assertCount(1, \WpStub::$requests, 'no retry within the run');
        self::assertTrue($this->plugin->state->backoffActive());
        self::assertTrue($this->plugin->meter->day()['exhausted']);
    }

    public function testT26QuotaFunction(): void
    {
        $this->configure('developer', 'local');
        $this->setUsed(7);
        $q = newsapi_pull_quota();
        self::assertSame(7, $q['used']);
        self::assertSame(100, $q['limit']);
        self::assertSame(20, $q['reserve']);
        self::assertSame(93, $q['remaining']);
        self::assertSame('2026-11-15T00:00:00Z', $q['reset']);
    }

    public function testT27DaysOlderThanThirtyArePruned(): void
    {
        $this->configure();
        $fp = $this->plugin->settings->keyFingerprint();
        update_option(Meter::OPTION, [$fp => [
            gmdate('Y-m-d', self::NOW - 31 * 86400) => ['total' => 5],
            gmdate('Y-m-d', self::NOW - 29 * 86400) => ['total' => 6],
        ]]);
        $this->plugin->meter->count('top');
        $stored = get_option(Meter::OPTION)[$fp];
        self::assertArrayNotHasKey(gmdate('Y-m-d', self::NOW - 31 * 86400), $stored);
        self::assertArrayHasKey(gmdate('Y-m-d', self::NOW - 29 * 86400), $stored);
        self::assertArrayHasKey(gmdate('Y-m-d', self::NOW), $stored);
    }

    public function testT28FailingMeterWriteDoesNotStopTheRun(): void
    {
        $this->configure();
        $this->addPull();
        \WpStub::$failWrites = [Meter::OPTION];
        $this->queueArticles([$this->article('https://a.example.com/1')]);
        $summary = $this->runPull();
        self::assertSame('ok', $summary['outcome']);
        self::assertSame(1, $summary['created']);
        self::assertStringContainsString('Quota meter write failed', implode("\n", $this->logLines));
    }

    public function testT29PerPullCap(): void
    {
        $this->configure();
        $this->addPull(['daily_cap' => 2]);
        $this->addPull(['slug' => 'other']);
        for ($i = 0; $i < 3; $i++) {
            $this->queueArticles([]);
            $this->clock->advance(3600);
            $summary = $this->runPull();
        }
        self::assertSame('pull_quota', $summary['reason']);
        self::assertSame(2, $this->plugin->meter->day()['pulls']['top']);
        $this->queueArticles([]);
        self::assertSame('ok', $this->runPull('other')['outcome']);
    }

    public function testRemainingHeaderCorrectsTheMeter(): void
    {
        $this->configure('developer', 'local');
        $this->addPull();
        $this->queueArticles([], 0, ['X-RateLimit-Remaining' => '40']);
        $this->runPull();
        $snap = $this->plugin->meter->snapshot();
        self::assertSame(60, $snap['used']);
        self::assertSame(40, $snap['remaining']);
        self::assertSame('header', $snap['source']);
    }

    public function testNetworkMeterIsSharedPerKey(): void
    {
        \WpStub::$multisite = true;
        \WpStub::$sites = [1, 2];
        $plugin = $this->makePlugin(true);
        $this->plugin = $plugin;
        $this->configure();
        $plugin->meter->count('a');
        switch_to_blog(2);
        $this->configure();
        $plugin->settings->flush();
        $plugin->meter->count('b');
        self::assertSame(2, $plugin->meter->day()['total']);
        restore_current_blog();
        $plugin->settings->flush();
        self::assertSame(2, $plugin->meter->day()['total']);
        self::assertSame('network', $plugin->meter->snapshot()['scope']);
    }

    public function testMeterWriteKeepsCountsAnotherProcessWrote(): void
    {
        $this->configure();
        \WpStub::$cachedOptions = [Meter::OPTION];
        $this->plugin->meter->count('a');
        // Another PHP process (another site on the network, or an admin
        // catalogue fetch) counts four requests while this one holds a
        // cached copy of the option.
        $fp = $this->plugin->settings->keyFingerprint();
        \WpStub::$options[1][Meter::OPTION][$fp][gmdate('Y-m-d', self::NOW)]['total'] = 5;
        $this->plugin->meter->count('a');
        self::assertSame(6, $this->plugin->meter->day()['total']);
        self::assertSame([], $GLOBALS['wpdb']->locks, 'The meter lock is released after each write.');
        self::assertNotSame([], $GLOBALS['wpdb']->lockLog);
    }

    public function testClaimRefusesWhenAnotherRunTookTheLastRequest(): void
    {
        $this->configure('paid', 'production', ['daily_limit' => 10, 'reserve' => 0]);
        $this->addPull();
        $this->setUsed(8);
        // Between the pre-check and the send, a concurrent run takes the rest.
        add_action('newsapi_pull_before_request', function (): void {
            $this->setUsed(10);
        });
        $this->queueArticles([$this->article('https://a.example.com/1')]);
        $summary = $this->runPull();
        self::assertSame('skipped', $summary['outcome']);
        self::assertSame('quota', $summary['reason']);
        self::assertSame(0, $summary['requests']);
        self::assertSame([], \WpStub::$requests);
        self::assertSame(10, $this->plugin->meter->day()['total']);
    }

    public function testMeterWriteGoesAheadWhenTheLockTimesOut(): void
    {
        $this->configure();
        $GLOBALS['wpdb']->lockFails = true;
        self::assertNull($this->plugin->meter->claim(true, 'a'));
        self::assertSame(1, $this->plugin->meter->day()['total']);
    }
}
