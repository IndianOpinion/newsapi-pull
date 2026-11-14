<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Tests\Unit;

use IndianOpinion\NewsApiPull\Tests\TestCase;

final class ArticleChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure();
        $this->addPull();
    }

    public function testT55RemovedPlaceholdersAreRejectedAndNotRecorded(): void
    {
        $this->queueArticles([
            $this->article('https://removed.com', ['title' => '[Removed]']),
            $this->article('https://example.com/real', ['title' => '[Removed]']),
            $this->article('https://removed.com/other', ['title' => 'Looks real']),
        ]);
        $summary = $this->runPull();
        self::assertSame(['removed' => 3], $summary['rejections']);
        self::assertSame([], $GLOBALS['wpdb']->rows());
    }

    public function testT56EmptyTitleIsRejectedAndRecorded(): void
    {
        $this->queueArticles([$this->article('https://example.com/no-title', ['title' => "   \t"])]);
        $summary = $this->runPull();
        self::assertSame(['no_title' => 1], $summary['rejections']);
        $rows = $GLOBALS['wpdb']->rows();
        self::assertCount(2, $rows);
        self::assertSame('no_title', $rows[0]['reason']);
        self::assertSame(0, $rows[0]['post_id']);
    }

    public function testT57DeniedSourceAndHost(): void
    {
        add_filter('newsapi_pull_denied_sources', static fn (): array => ['bad-source']);
        add_filter('newsapi_pull_denied_hosts', static fn (): array => ['denied.example.org']);
        $this->queueArticles([
            $this->article('https://example.com/1', ['source' => ['id' => 'bad-source', 'name' => 'Bad']]),
            $this->article('https://www.denied.example.org/2'),
            $this->article('https://example.com/3'),
        ]);
        $summary = $this->runPull();
        self::assertSame(['source_denied' => 2], $summary['rejections']);
        self::assertSame(1, $summary['created']);
    }

    public function testT59ArticleAge(): void
    {
        $this->queueArticles([
            $this->article('https://example.com/old', ['publishedAt' => gmdate('Y-m-d\TH:i:s\Z', self::NOW - 100 * 3600)]),
            $this->article('https://example.com/missing', ['publishedAt' => null]),
        ]);
        $summary = $this->runPull();
        self::assertSame(['too_old' => 1], $summary['rejections']);
        $id = $summary['post_ids'][0];
        self::assertSame('date_missing', get_post_meta($id, '_newsapi_pull_flags', true));
        self::assertSame(wp_date('Y-m-d H:i:s', self::NOW), \WpStub::$posts[$id]['post_date']);

        \WpStub::reset();
        parent::setUp();
        $this->configure('developer', 'local');
        $this->addPull();
        $this->queueArticles([$this->article('https://example.com/ninety', ['publishedAt' => gmdate('Y-m-d\TH:i:s\Z', self::NOW - 90 * 3600)])]);
        self::assertSame(1, $this->runPull()['created']);
    }

    public function testT60FutureDateIsClampedAndFlagged(): void
    {
        $this->queueArticles([$this->article('https://example.com/future', ['publishedAt' => gmdate('Y-m-d\TH:i:s\Z', self::NOW + 2 * 3600)])]);
        $summary = $this->runPull();
        $id = $summary['post_ids'][0];
        self::assertSame('date_clamped', get_post_meta($id, '_newsapi_pull_flags', true));
        self::assertSame(wp_date('Y-m-d H:i:s', self::NOW), \WpStub::$posts[$id]['post_date']);
    }

    public function testT61AcceptFilterRejectsWithASlug(): void
    {
        add_filter('newsapi_pull_accept_article', static fn (string $r, array $a): string => str_contains((string) $a['url'], 'sport') ? 'off topic' : '', 10, 3);
        $this->queueArticles([$this->article('https://example.com/sport/1'), $this->article('https://example.com/politics/1')]);
        $summary = $this->runPull();
        self::assertSame(['off-topic' => 1], $summary['rejections']);
        self::assertSame(1, $summary['created']);
        $rejected = \WpStub::fired('newsapi_pull_article_rejected');
        self::assertSame('off-topic', $rejected[0][0]);
        self::assertSame('top', $rejected[0][2]);
    }
}
