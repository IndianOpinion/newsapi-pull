<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Tests\Unit;

use IndianOpinion\NewsApiPull\Cli\Command;
use IndianOpinion\NewsApiPull\Tests\TestCase;

final class ErrorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure();
        $this->addPull();
        $this->addPull(['slug' => 'other']);
    }

    public function testT78KeyErrorsPauseEveryPullUntilTheKeyChanges(): void
    {
        foreach (['apiKeyMissing', 'apiKeyInvalid', 'apiKeyDisabled'] as $code) {
            \WpStub::reset();
            $this->setUp();
            $this->queueError($code, 401);
            self::assertSame('key_error', $this->runPull()['reason'], $code);
            self::assertSame('key_error', $this->runPull('other')['reason'], $code);
            self::assertCount(1, \WpStub::$requests, $code);
            $this->configure('paid', 'production', ['api_key' => 'ffffffffffffffffffffffffffffffff']);
            $this->queueArticles([]);
            self::assertSame('ok', $this->runPull('other')['outcome'], $code);
        }
    }

    public function testT78PullErrorsDisableOnlyThatPull(): void
    {
        foreach (['parameterInvalid', 'parametersMissing', 'sourcesTooMany', 'sourceDoesNotExist'] as $code) {
            \WpStub::reset();
            $this->setUp();
            $this->queueError($code, 400, 'Bad parameter.');
            self::assertSame('pull_invalid', $this->runPull()['reason'], $code);
            self::assertSame('pull_invalid', $this->plugin->pulls->state('top')['disabled_reason']);
            $this->queueArticles([]);
            self::assertSame('ok', $this->runPull('other')['outcome']);
            $this->plugin->pulls->save($this->plugin->pulls->get('top'));
            self::assertSame('', $this->plugin->pulls->state('top')['disabled_reason'], 'saving re-enables it');
        }
    }

    public function testT78ServerErrorsRetryNextRun(): void
    {
        $this->queueError('unexpectedError', 500);
        self::assertSame('api_error', $this->runPull()['reason']);
        \WpStub::$responses[] = \WpStub::response('<html>502</html>', 502);
        $this->clock->advance(3600);
        self::assertSame('api_error', $this->runPull()['reason']);
        self::assertSame(2, $this->plugin->pulls->state('top')['failures']);
        $this->clock->advance(3600);
        $this->queueArticles([]);
        self::assertSame('ok', $this->runPull()['outcome']);
    }

    public function testT79BackoffAfterFiveFailures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->queueError('unexpectedError', 500);
            $this->runPull();
            $this->clock->advance(3600);
        }
        self::assertSame(5, $this->plugin->pulls->state('top')['failures']);
        self::assertSame('backoff', $this->runPull('top', false, false, true)['reason']);
        $this->clock->advance(3 * 3600);
        $this->queueArticles([]);
        self::assertSame('ok', $this->runPull('top', false, false, true)['outcome']);
        self::assertSame(0, $this->plugin->pulls->state('top')['failures']);
    }

    public function testT80UnknownCodeIsApiErrorAndLogged(): void
    {
        $this->queueError('brandNewCode', 400, 'Something new happened.');
        $summary = $this->runPull();
        self::assertSame('api_error', $summary['reason']);
        $log = implode("\n", $this->logLines);
        self::assertStringContainsString('brandNewCode', $log);
        self::assertStringContainsString('Something new happened.', $log);
    }

    public function testT81NetworkErrorIsCounted(): void
    {
        \WpStub::$responses[] = new \WP_Error('http_request_failed', 'Could not resolve host');
        $summary = $this->runPull();
        self::assertSame('network', $summary['reason']);
        self::assertSame(1, $summary['requests']);
        self::assertSame(1, $this->plugin->meter->day()['total']);
    }

    public function testT82HtmlPageIsBadResponseAndOnly200BytesLogged(): void
    {
        $this->configure('paid', 'production', ['debug_log' => true]);
        $html = '<html>' . str_repeat('x', 500) . 'TAIL</html>';
        \WpStub::$responses[] = \WpStub::response($html, 200);
        $summary = $this->runPull();
        self::assertSame('bad_response', $summary['reason']);
        $line = implode("\n", array_filter($this->logLines, static fn (string $l): bool => str_contains($l, 'First bytes')));
        self::assertStringContainsString('<html>xxx', $line);
        self::assertStringNotContainsString('TAIL', $line);
        self::assertStringNotContainsString('<html>', $summary['message']);
    }

    public function testT83NonStringTitleRejectedSingly(): void
    {
        $this->queueArticles([
            $this->article('https://example.com/1', ['title' => 12345]),
            $this->article('https://example.com/2'),
            $this->article('https://example.com/3', ['url' => ['not', 'a', 'string']]),
            $this->article('https://example.com/4'),
        ]);
        $summary = $this->runPull();
        self::assertSame(2, $summary['created']);
        self::assertSame(['bad_url' => 1, 'no_title' => 1], $summary['rejections']);
    }

    public function testT84DatabaseErrorStopsDraftsAndQueriesOnce(): void
    {
        $this->plugin->seen->install();
        $GLOBALS['wpdb']->failOn = 'SELECT';
        $GLOBALS['wpdb']->queries = [];
        $this->queueArticles([$this->article('https://example.com/1'), $this->article('https://example.com/2'), $this->article('https://example.com/3')]);
        $summary = $this->runPull();
        self::assertSame('db_error', $summary['reason']);
        self::assertSame(0, $summary['created']);
        self::assertCount(1, $GLOBALS['wpdb']->queries);
        $errors = array_filter($this->logLines, static fn (string $l): bool => str_contains($l, 'De-duplication table query failed'));
        self::assertCount(1, $errors);
    }

    public function testT85ThreeInsertErrorsEndTheRun(): void
    {
        \WpStub::$failInserts = 3;
        $articles = [];
        for ($i = 1; $i <= 5; $i++) {
            $articles[] = $this->article('https://example.com/ins/' . $i);
        }
        $this->queueArticles($articles);
        $summary = $this->runPull();
        self::assertSame('insert_error', $summary['reason']);
        self::assertSame(0, $summary['created']);
        self::assertSame([], $GLOBALS['wpdb']->rows());
    }

    public function testT86ExceptionMidRunLeavesDraftsAndRecordsIntact(): void
    {
        $n = 0;
        add_action('newsapi_pull_draft_created', static function () use (&$n): void {
            if (++$n === 3) {
                throw new \RuntimeException('Simulated time limit');
            }
        });
        $articles = [];
        for ($i = 1; $i <= 5; $i++) {
            $articles[] = $this->article('https://example.com/ex/' . $i);
        }
        $this->queueArticles($articles);
        $summary = $this->runPull();
        self::assertSame('exception', $summary['reason']);
        self::assertCount(3, $this->drafts());
        self::assertCount(6, $GLOBALS['wpdb']->rows(), 'three articles, two keys each');
        self::assertFalse(get_option('newsapi_pull_lock'), 'lock released');
    }

    public function testT87KeyIsRedactedEverywhere(): void
    {
        $this->queueError('brandNewCode', 400, 'Your key ' . self::KEY . ' is odd.');
        $summary = $this->runPull();
        self::assertStringNotContainsString(self::KEY, $summary['message']);
        self::assertStringContainsString('[redacted]', $summary['message']);
        self::assertStringNotContainsString(self::KEY, implode("\n", $this->logLines));
        self::assertStringContainsString('[redacted]', implode("\n", $this->logLines));

        $this->plugin->logger->warning('line with ' . self::KEY);
        self::assertStringEndsWith('line with [redacted]', end($this->logLines));

        $this->clock->advance(7200);
        $this->queueError('brandNewCode', 400, 'Again ' . self::KEY);
        try {
            (new Command($this->plugin))->run(['top'], []);
            self::fail('WP-CLI error expected');
        } catch (\WpCliError $e) {
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
            self::assertStringContainsString('[redacted]', $e->getMessage());
        }
        self::assertStringNotContainsString(self::KEY, implode("\n", \WP_CLI::$out));
    }
}
