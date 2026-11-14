<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Tests\Unit;

use IndianOpinion\NewsApiPull\Lock;
use IndianOpinion\NewsApiPull\Meter;
use IndianOpinion\NewsApiPull\PullRepository;
use IndianOpinion\NewsApiPull\Tests\TestCase;

final class RunSequenceTest extends TestCase
{
    public function testT18SearchWindow(): void
    {
        $this->configure();
        $this->addPull(['slug' => 's', 'endpoint' => 'everything', 'q' => 'monsoon', 'country' => '']);
        $this->queueArticles([]);
        $this->runPull('s');
        self::assertSame(gmdate('Y-m-d\TH:i:s', self::NOW - 2 * 86400), $this->requestParams(0)['from'], 'first run: look-back');

        $this->clock->advance(3600);
        $this->queueArticles([]);
        $this->runPull('s');
        self::assertSame(gmdate('Y-m-d\TH:i:s', self::NOW - 6 * 3600), $this->requestParams(1)['from'], 'last success minus overlap');

        $this->clock->advance(3600);
        \WpStub::$responses[] = \WpStub::response('<html>bad gateway</html>', 502);
        $this->runPull('s');
        $this->clock->advance(3600);
        $this->queueArticles([]);
        $this->runPull('s');
        self::assertSame(gmdate('Y-m-d\TH:i:s', self::NOW + 3600 - 6 * 3600), $this->requestParams(3)['from'], 'a failed run does not move the window');

        self::assertSame(
            gmdate('Y-m-d\TH:i:s', self::NOW + 3 * 3600 - 29 * 86400),
            (function (): string {
                add_filter('newsapi_pull_lookback', static fn (): int => 40 * 86400);
                return $this->plugin->runner->windowStart('developer', 0);
            })()
        );
        self::assertSame(gmdate('Y-m-d\TH:i:s', self::NOW + 3 * 3600 - 40 * 86400), $this->plugin->runner->windowStart('paid', 0));
    }

    public function testT30LockHeldAndExpiredLock(): void
    {
        $this->configure();
        $this->addPull();
        add_option(Lock::OPTION, self::NOW + 300);
        $summary = $this->runPull();
        self::assertSame('locked', $summary['reason']);
        self::assertSame('skipped', $summary['outcome']);
        self::assertSame([], \WpStub::$requests);

        update_option(Lock::OPTION, self::NOW - 1);
        $this->queueArticles([]);
        self::assertSame('ok', $this->runPull()['outcome']);
        self::assertFalse(get_option(Lock::OPTION), 'released after the run');
    }

    public function testT31PreChecksInOrder(): void
    {
        $steps = [
            'disabled' => fn () => $this->configure('paid', 'production', ['kill_switch' => true]),
            'plan_missing' => fn () => $this->plugin->settings->save(['api_key' => self::KEY]),
            'plan_environment' => fn () => $this->configure('developer', 'production'),
            'key_missing' => fn () => $this->configure('paid', 'production', ['api_key' => '']),
            'pull_invalid' => function (): void {
                $this->configure();
                $this->addPull(['page_size' => 500]);
            },
            'paused' => function (): void {
                $this->configure();
                $this->addPull(['paused' => true]);
            },
            'backoff' => function (): void {
                $this->configure();
                $this->addPull();
                $this->plugin->state->setBackoff(600);
            },
            'quota' => function (): void {
                $this->configure('developer', 'local');
                $this->addPull();
                update_option(Meter::OPTION, [$this->plugin->settings->keyFingerprint() => [gmdate('Y-m-d', self::NOW) => ['total' => 100]]]);
            },
        ];
        foreach ($steps as $reason => $arrange) {
            \WpStub::reset();
            $this->setUp();
            $this->addPull();
            $arrange();
            $summary = $this->runPull();
            self::assertSame($reason, $summary['reason'], $reason);
            self::assertSame(0, $summary['requests'], $reason);
            self::assertSame([], \WpStub::$requests, $reason);
        }
    }

    public function testT32KeyInHeaderOnlyAndTlsAlwaysOn(): void
    {
        $this->configure();
        $this->addPull();
        add_filter('newsapi_pull_request_args', static function (array $args): array {
            $args['sslverify'] = false;
            $args['redirection'] = 5;
            $args['stream'] = true;
            $args['headers']['X-Api-Key'] = 'stolen';
            return $args;
        });
        $this->queueArticles([]);
        $this->runPull();
        $request = \WpStub::$requests[0];
        self::assertSame(self::KEY, $request['args']['headers']['X-Api-Key']);
        self::assertTrue($request['args']['sslverify']);
        // A redirect would carry the key header to whatever host it names.
        self::assertSame(0, $request['args']['redirection']);
        self::assertFalse($request['args']['stream']);
        self::assertStringNotContainsString(self::KEY, $request['url']);
        self::assertStringStartsWith('https://newsapi.org/v2/top-headlines?', $request['url']);
    }

    public function testT33UserAgentAndTimeout(): void
    {
        $this->configure();
        $this->addPull();
        add_filter('newsapi_pull_timeout', static fn (): int => 120);
        $this->queueArticles([]);
        $this->runPull();
        $args = \WpStub::$requests[0]['args'];
        self::assertSame(60, $args['timeout']);
        self::assertStringStartsWith('newsapi-pull/0.1.0; https://my-site.test/', $args['user-agent']);

        add_filter('newsapi_pull_timeout', static fn (): int => 1, 20);
        add_filter('newsapi_pull_user_agent', static fn (): string => 'custom-agent');
        $this->clock->advance(7200);
        $this->queueArticles([]);
        $this->runPull();
        self::assertSame(5, \WpStub::$requests[1]['args']['timeout']);
        self::assertSame('custom-agent', \WpStub::$requests[1]['args']['user-agent']);
    }

    public function testT34OkWithoutArticlesIsBadResponse(): void
    {
        $this->configure();
        $this->addPull();
        \WpStub::$responses[] = \WpStub::response(['status' => 'ok', 'totalResults' => 3]);
        self::assertSame('bad_response', $this->runPull()['reason']);
    }

    public function testT35ArticlesProcessedInApiOrder(): void
    {
        $this->configure();
        $this->addPull();
        $urls = ['https://a.example.com/3', 'https://a.example.com/1', 'https://a.example.com/2'];
        $this->queueArticles(array_map(fn (string $u): array => $this->article($u), $urls));
        $summary = $this->runPull();
        $order = array_map(static fn (int $id): string => (string) get_post_meta($id, '_newsapi_pull_url', true), $summary['post_ids']);
        self::assertSame($urls, $order);
    }

    public function testT36AllSeenPageStopsPaging(): void
    {
        $this->configure();
        $this->addPull(['page_size' => 2, 'max_pages' => 3]);
        $page = [$this->article('https://a.example.com/1'), $this->article('https://a.example.com/2')];
        $this->queueArticles($page, 6);
        $this->queueArticles([$this->article('https://a.example.com/3'), $this->article('https://a.example.com/4')], 6);
        $this->queueArticles([], 6);
        $this->runPull();
        self::assertCount(3, \WpStub::$requests);

        \WpStub::$requests = [];
        $this->clock->advance(7200);
        $this->queueArticles($page, 6);
        $this->queueArticles([$this->article('https://a.example.com/5')], 6);
        $summary = $this->runPull();
        self::assertCount(1, \WpStub::$requests);
        self::assertSame(2, $summary['duplicates']);
    }

    public function testT37PagingStops(): void
    {
        $this->configure();
        $this->addPull(['page_size' => 2, 'max_pages' => 5]);
        $n = 0;
        $page = function () use (&$n): array {
            return [$this->article('https://a.example.com/p' . ++$n), $this->article('https://a.example.com/p' . ++$n)];
        };
        $this->queueArticles($page(), 4);
        $this->queueArticles($page(), 4);
        $this->queueArticles($page(), 4);
        $this->runPull();
        self::assertCount(2, \WpStub::$requests, 'stops at totalResults');

        \WpStub::$requests = [];
        \WpStub::$responses = [];
        $this->clock->advance(7200);
        $this->queueArticles($page(), 100);
        $this->queueArticles([$this->article('https://a.example.com/short')], 100);
        $this->queueArticles($page(), 100);
        $this->runPull();
        self::assertCount(2, \WpStub::$requests, 'stops at a short page');

        \WpStub::$requests = [];
        \WpStub::$responses = [];
        $this->clock->advance(7200);
        $this->addPull(['page_size' => 2, 'max_pages' => 2]);
        for ($i = 0; $i < 3; $i++) {
            $this->queueArticles($page(), 100);
        }
        $this->runPull();
        self::assertCount(2, \WpStub::$requests, 'stops at max pages');
    }

    public function testDeveloperPlanFetchesPageOneOnly(): void
    {
        $this->configure('developer', 'local');
        update_option('newsapi_pull_pulls', ['top' => ['definition' => [
            'slug' => 'top', 'endpoint' => 'headlines', 'country' => 'us', 'page_size' => 1, 'max_pages' => 1, 'author' => 1,
        ]]]);
        $this->queueArticles([$this->article('https://a.example.com/1')], 50);
        $this->queueArticles([$this->article('https://a.example.com/2')], 50);
        $this->runPull();
        self::assertCount(1, \WpStub::$requests);
    }

    public function testMaximumResultsReachedStopsPagingCleanly(): void
    {
        $this->configure();
        $this->addPull(['page_size' => 1, 'max_pages' => 3]);
        $this->queueArticles([$this->article('https://a.example.com/1')], 50);
        $this->queueError('maximumResultsReached', 426);
        $summary = $this->runPull();
        self::assertSame('ok', $summary['outcome']);
        self::assertSame(1, $summary['created']);
        self::assertSame('', $this->plugin->pulls->state('top')['disabled_reason']);
    }

    public function testT38SummaryStoredWithCountsByReason(): void
    {
        $this->configure();
        $this->addPull();
        $this->queueArticles([
            $this->article('https://a.example.com/1'),
            $this->article('https://a.example.com/2', ['title' => '']),
            $this->article('not a url'),
        ]);
        $this->runPull();
        $state = $this->plugin->pulls->state('top');
        self::assertSame('ok', $state['last_outcome']);
        self::assertSame(1, $state['last_summary']['created']);
        self::assertSame(['bad_url' => 1, 'no_title' => 1], $state['last_summary']['rejections']);
        self::assertSame(self::NOW, $state['last_success']);
        $log = $this->plugin->log->all();
        self::assertCount(1, $log);
        self::assertSame(3, $log[0]['received']);
        self::assertCount(1, \WpStub::fired('newsapi_pull_run_finished'));
    }

    public function testT39DraftCapPerRun(): void
    {
        $this->configure();
        $this->addPull(['page_size' => 100]);
        $articles = [];
        for ($i = 0; $i < 80; $i++) {
            $articles[] = $this->article('https://a.example.com/cap/' . $i);
        }
        $this->queueArticles($articles);
        $summary = $this->runPull();
        self::assertSame(50, $summary['created']);
        self::assertCount(100, $GLOBALS['wpdb']->rows(), '50 drafts, two rows each');

        $this->clock->advance(7200);
        $this->queueArticles($articles);
        $summary = $this->runPull();
        self::assertSame(30, $summary['created']);
        self::assertSame(50, $summary['duplicates']);
    }

    public function testPauseMadeDuringARunSurvivesTheStateWrite(): void
    {
        $this->configure();
        $this->addPull();
        \WpStub::$cachedOptions = [PullRepository::OPTION];
        $this->plugin->pulls->get('top');
        // An editor pauses the pull from another request while this run waits on the API.
        add_action('newsapi_pull_before_request', static function (): void {
            \WpStub::$options[1][PullRepository::OPTION]['top']['definition']['paused'] = true;
        });
        $this->queueArticles([$this->article('https://a.example.com/1')]);
        $summary = $this->runPull();
        self::assertSame('ok', $summary['outcome']);
        $pull = $this->plugin->pulls->get('top');
        self::assertNotNull($pull);
        self::assertTrue($pull->paused);
        self::assertSame('ok', $this->plugin->pulls->state('top')['last_outcome']);
    }
}
