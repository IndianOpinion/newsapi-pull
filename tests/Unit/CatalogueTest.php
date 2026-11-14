<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Tests\Unit;

use IndianOpinion\NewsApiPull\Admin\AdminPage;
use IndianOpinion\NewsApiPull\Catalogue;
use IndianOpinion\NewsApiPull\Tests\TestCase;

final class CatalogueTest extends TestCase
{
    public function testT13HeadlinesLanguageResolvesSources(): void
    {
        $this->configure();
        $this->addPull(['country' => '', 'language' => 'de']);
        $this->queueSources($this->catalogue());
        $this->queueArticles([]);
        $summary = $this->runPull();
        self::assertSame('ok', $summary['outcome']);
        self::assertSame(2, $summary['requests']);
        self::assertStringContainsString('/v2/top-headlines/sources', \WpStub::$requests[0]['url']);
        $params = $this->requestParams(1);
        self::assertArrayNotHasKey('country', $params);
        $ids = explode(',', $params['sources']);
        self::assertCount(20, $ids);
        self::assertSame('de-de-1', $ids[0]);
        self::assertSame(5, $summary['left_out']);
        self::assertSame(5, $this->plugin->pulls->state('top')['left_out']);

        $this->addPull(['slug' => 'sv', 'country' => '', 'language' => 'sv']);
        $summary = $this->runPull('sv');
        self::assertSame('no_sources_for_language', $summary['reason']);
    }

    public function testT14AndT58LanguageMismatchRejectedAndRecorded(): void
    {
        $this->configure();
        $this->addPull(['slug' => 'en', 'endpoint' => 'everything', 'country' => '', 'q' => 'x', 'language' => 'en']);
        $this->queueSources($this->catalogue());
        $french = $this->article('https://fr.example.com/1', ['source' => ['id' => 'fr-fr-1', 'name' => 'Source fr 1']]);
        $unknown = $this->article('https://unknown.example.com/1', ['source' => ['id' => 'not-in-catalogue', 'name' => 'Unknown']]);
        $english = $this->article('https://en.example.com/1', ['source' => ['id' => 'en-us-1', 'name' => 'Source en 1']]);
        $this->queueArticles([$french, $unknown, $english]);
        $summary = $this->runPull('en');
        self::assertSame(['language_mismatch' => 1], $summary['rejections']);
        self::assertSame(2, $summary['created']);
        self::assertSame('en', $this->requestParams(1)['language']);

        $this->clock->advance(7200);
        $this->queueArticles([$french]);
        $summary = $this->runPull('en');
        self::assertSame(['seen' => 1], $summary['rejections'], 'the rejection was recorded');
    }

    public function testT75FetchedOnceInTtlAndOnlyWhenNeeded(): void
    {
        $this->configure();
        $this->addPull();
        $this->queueArticles([]);
        $this->runPull();
        self::assertCount(1, \WpStub::$requests, 'no language: no catalogue fetch');

        $this->addPull(['slug' => 'de', 'country' => '', 'language' => 'de']);
        $this->queueSources($this->catalogue());
        $this->queueArticles([]);
        $this->runPull('de');
        self::assertSame(1, $this->plugin->meter->day()['pulls']['_catalogue'] ?? 0, 'the fetch is counted');
        $this->clock->advance(23 * 3600);
        $this->queueArticles([]);
        $this->runPull('de');
        $sourceCalls = array_filter(\WpStub::$requests, static fn (array $r): bool => str_contains($r['url'], '/sources'));
        self::assertCount(1, $sourceCalls);

        $this->clock->advance(2 * 3600);
        $this->queueSources($this->catalogue());
        $this->queueArticles([]);
        $this->makePlugin()->runner->run('de');
        $sourceCalls = array_filter(\WpStub::$requests, static fn (array $r): bool => str_contains($r['url'], '/sources'));
        self::assertCount(2, $sourceCalls, 'fetched again after 24 hours');
    }

    public function testT76StaleCopyUsedAndNoCopyIsUnavailable(): void
    {
        $this->configure();
        $this->addPull(['country' => '', 'language' => 'de']);
        \WpStub::$responses[] = \WpStub::response('oops', 500);
        $summary = $this->runPull();
        self::assertSame('catalogue_unavailable', $summary['reason']);

        update_option(Catalogue::FALLBACK_OPTION, ['sources' => $this->catalogue(), 'fetched_at' => self::NOW - 3 * 86400]);
        $this->clock->advance(7200);
        \WpStub::$responses[] = \WpStub::response('oops', 500);
        $this->queueArticles([]);
        $summary = $this->makePlugin()->runner->run('top');
        self::assertSame('ok', $summary['outcome']);
        $copy = $this->plugin->catalogue->cached();
        self::assertNotNull($copy);
        self::assertTrue($copy['stale']);
        $notices = array_column((new AdminPage($this->plugin))->noticeList(), 1);
        self::assertStringContainsString('stale (fetched ' . gmdate('Y-m-d H:i', self::NOW - 3 * 86400), implode(' ', $notices));
    }

    public function testT77PickerLimitUnknownIdWarningAndSourceDoesNotExist(): void
    {
        $this->configure();
        update_option(Catalogue::FALLBACK_OPTION, ['sources' => $this->catalogue(), 'fetched_at' => self::NOW]);
        $page = new AdminPage($this->plugin);
        $nonce = wp_create_nonce('newsapi_pull_save_pull');
        $ids = array_map(static fn (int $i): string => 'de-de-' . $i, range(1, 21));
        $result = $page->handle('save_pull', ['slug' => 'pick', 'endpoint' => 'headlines', 'sources' => $ids, '_wpnonce' => $nonce]);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('21', $result['errors']['sources'] ?? '');

        $result = $page->handle('save_pull', ['slug' => 'pick', 'endpoint' => 'headlines', 'sources' => 'de-de-1, made-up', '_wpnonce' => $nonce]);
        self::assertTrue($result['ok']);
        self::assertStringContainsString('"made-up" is not in the cached source catalogue', $result['message']);

        $this->queueError('sourceDoesNotExist', 400, 'You have requested a source which does not exist.');
        $summary = $this->runPull('pick');
        self::assertSame('pull_invalid', $summary['reason']);
        $state = $this->plugin->pulls->state('pick');
        self::assertSame('pull_invalid', $state['disabled_reason']);
        self::assertSame('pull_disabled', $this->runPull('pick', true)['reason']);
    }
}
