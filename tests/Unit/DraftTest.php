<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Tests\Unit;

use IndianOpinion\NewsApiPull\Article;
use IndianOpinion\NewsApiPull\DraftWriter;
use IndianOpinion\NewsApiPull\PullDefinition;
use IndianOpinion\NewsApiPull\Tests\TestCase;

final class DraftTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure('developer', 'local');
        $this->addPull(['categories' => [7], 'tags' => ['wire'], 'source_tag' => true, 'author' => 1]);
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function oneDraft(array $over = []): array
    {
        $this->queueArticles([$this->article('https://example.com/story', $over)]);
        $summary = $this->runPull();
        self::assertSame(1, $summary['created'], (string) json_encode($summary));
        return \WpStub::$posts[$summary['post_ids'][0]];
    }

    public function testT62Title(): void
    {
        self::assertSame('Title', Article::cleanTitle('Title - Publisher', 'Publisher'));
        self::assertSame('Title - Other', Article::cleanTitle('Title - Other', 'Publisher'));
        self::assertSame('Title', Article::cleanTitle('Title | Publisher', 'Publisher'));
        self::assertSame('Bold & title', Article::cleanTitle("<b>Bold</b> &amp; \x07title", ''));
        $post = $this->oneDraft(['title' => 'Big news - Example News']);
        self::assertSame('Big news', $post['post_title']);
    }

    public function testT63BodyOrderAndNoContentField(): void
    {
        $post = $this->oneDraft();
        $body = $post['post_content'];
        $d = strpos($body, 'A short description');
        $n = strpos($body, 'newsapi-pull-note');
        $a = strpos($body, 'newsapi-pull-attribution');
        self::assertIsInt($d);
        self::assertTrue($d < $n && $n < $a);
        self::assertStringNotContainsString('Truncated content', $body);

        $this->configure('developer', 'local', ['include_snippet' => true]);
        $this->queueArticles([$this->article('https://example.com/with-snippet')]);
        $this->clock->advance(7200);
        $summary = $this->runPull();
        $body = \WpStub::$posts[$summary['post_ids'][0]]['post_content'];
        $snippet = strpos($body, 'Truncated content');
        self::assertIsInt($snippet);
        self::assertTrue($snippet > strpos($body, 'newsapi-pull-note') && $snippet < strpos($body, 'newsapi-pull-attribution'));
    }

    public function testT64NoteFilter(): void
    {
        add_filter('newsapi_pull_editor_note', static fn (): string => 'Check with the desk.');
        self::assertStringContainsString('Check with the desk.', $this->oneDraft()['post_content']);
    }

    public function testT65Attribution(): void
    {
        $body = $this->oneDraft()['post_content'];
        self::assertStringContainsString('Example News', $body);
        self::assertStringContainsString('A. Writer', $body);
        self::assertStringContainsString('href="https://example.com/story" rel="nofollow noopener"', $body);
        self::assertStringContainsString(wp_date('Y-m-d H:i T', self::NOW - 3600), $body);
        self::assertStringContainsString('News data from NewsAPI.org', $body);
        self::assertStringContainsString('href="https://newsapi.org"', $body);

        $this->configure('developer', 'local', ['credit_line' => false]);
        self::assertTrue($this->plugin->settings->creditLine(), 'cannot be turned off on developer');
        self::assertSame(true, $this->plugin->settings->sanitise(['plan' => 'developer', 'credit_line' => ''])['credit_line']);
        $this->configure('paid', 'production', ['credit_line' => false]);
        self::assertFalse($this->plugin->settings->creditLine());
        $writer = new DraftWriter($this->plugin->settings, $this->plugin->logger);
        self::assertSame('', $writer->creditLine());
    }

    public function testCreditTextIsFilterableAndReappendedIfRemoved(): void
    {
        add_filter('newsapi_pull_credit_text', static fn (): string => 'Powered by NewsAPI.org');
        add_filter('newsapi_pull_attribution_html', static fn (): string => '<p>stripped</p>', 10, 2);
        $body = $this->oneDraft()['post_content'];
        self::assertStringContainsString('Powered by NewsAPI.org', $body);
        self::assertStringContainsString('href="https://newsapi.org"', $body);
    }

    public function testLinkRelIsFilterable(): void
    {
        add_filter('newsapi_pull_link_rel', static fn (): string => 'nofollow');
        $writer = new DraftWriter($this->plugin->settings, $this->plugin->logger);
        self::assertStringContainsString('href="https://example.com/x" rel="nofollow"', $writer->attribution(Article::normalise($this->article('https://example.com/x')) + ['published_ts' => 0]));
    }

    public function testT66UntrustedTextIsNeutralised(): void
    {
        $writer = new DraftWriter($this->plugin->settings, $this->plugin->logger);
        $article = Article::normalise($this->article('javascript:alert(1)', ['description' => '<script>alert(1)</script>Hello <img src=x onerror=alert(2)>']));
        $html = $writer->content($article + ['published_ts' => self::NOW], PullDefinition::fromArray(['slug' => 'top']));
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('Hello', $html);
    }

    public function testT67StatusForcedBackToDraft(): void
    {
        add_filter('wp_insert_post_data', static function (array $data): array {
            $data['post_status'] = 'publish';
            return $data;
        });
        $post = $this->oneDraft();
        self::assertSame('draft', $post['post_status']);
        self::assertStringContainsString('set back to "draft"', implode("\n", $this->logLines));
    }

    public function testStatusChangedAfterInsertIsRevertedToo(): void
    {
        add_action('wp_insert_post', static function (int $id): void {
            \WpStub::$posts[$id]['post_status'] = 'publish';
        });
        self::assertSame('draft', $this->oneDraft()['post_status']);
        self::assertStringContainsString('changed to "publish" during insert', implode("\n", $this->logLines));
    }

    public function testT68PostDateInSiteTimezoneAndGmtUnset(): void
    {
        \WpStub::$tz = 'Asia/Kolkata';
        $post = $this->oneDraft(['publishedAt' => '2026-11-14T06:30:00Z']);
        self::assertSame('2026-11-14 12:00:00', $post['post_date']);
        self::assertSame('0000-00-00 00:00:00', $post['post_date_gmt']);
    }

    public function testT69Excerpt(): void
    {
        $words = trim(str_repeat('word ', 100));
        $post = $this->oneDraft(['description' => $words]);
        self::assertLessThanOrEqual(300, mb_strlen($post['post_excerpt']));
        self::assertStringEndsWith('word', $post['post_excerpt']);
        self::assertSame('', trim(str_replace('word', '', $post['post_excerpt'])));
    }

    public function testT70CategoriesTagsAuthor(): void
    {
        $post = $this->oneDraft();
        self::assertSame([7], $post['post_category']);
        self::assertSame(['wire', 'Example News'], $post['tags_input']);
        self::assertSame(1, $post['post_author']);
    }

    public function testT71EveryMetaKeyWritten(): void
    {
        $post = $this->oneDraft();
        $meta = \WpStub::$meta[$post['ID']];
        foreach (DraftWriter::META_KEYS as $key) {
            self::assertArrayHasKey(DraftWriter::metaKey($key), $meta, $key);
        }
        self::assertSame('https://example.com/story', $meta['_newsapi_pull_url']);
        self::assertSame('example-news', $meta['_newsapi_pull_source_id']);
        self::assertSame('top', $meta['_newsapi_pull_pull']);
        self::assertSame(32, strlen($meta['_newsapi_pull_key']));
        self::assertSame('https://img.example.com/a.jpg', $meta['_newsapi_pull_image']);
    }

    public function testT72ImageNotDownloadedByDefaultAndSideloadFailureIsNonFatal(): void
    {
        $calls = 0;
        \WpStub::$sideload = static function () use (&$calls): \WP_Error {
            $calls++;
            return new \WP_Error('http_404', 'Not found');
        };
        $this->oneDraft();
        self::assertSame(0, $calls);

        add_filter('newsapi_pull_sideload_image', static fn (): bool => true, 10, 2);
        $this->clock->advance(7200);
        $this->queueArticles([$this->article('https://example.com/with-image')]);
        $summary = $this->runPull();
        self::assertSame(1, $calls);
        self::assertSame(1, $summary['created']);
        self::assertStringContainsString('Image sideload failed', implode("\n", $this->logLines));
    }

    public function testSideloadSuccessSetsThumbnailAndCaption(): void
    {
        add_filter('newsapi_pull_sideload_image', static fn (): bool => true, 10, 2);
        \WpStub::$sideload = static function (string $file, int $post, string $desc): int {
            \WpStub::$posts[900] = ['ID' => 900, 'post_type' => 'attachment', 'post_status' => 'inherit'];
            return 900;
        };
        $post = $this->oneDraft();
        self::assertSame(900, \WpStub::$meta[$post['ID']]['_thumbnail_id']);
        self::assertSame('Example News', \WpStub::$posts[900]['post_excerpt']);
    }

    public function testT73FailedInsertRecordsNothingAndRetriesNextRun(): void
    {
        \WpStub::$failInserts = 1;
        $this->queueArticles([$this->article('https://example.com/retry')]);
        $summary = $this->runPull();
        self::assertSame(0, $summary['created']);
        self::assertSame([], $GLOBALS['wpdb']->rows());
        $this->clock->advance(7200);
        $this->queueArticles([$this->article('https://example.com/retry')]);
        self::assertSame(1, $this->runPull()['created']);
    }

    public function testT74CreatedActionAndArgsFilterCannotPublish(): void
    {
        add_filter('newsapi_pull_draft_args', static function (array $args): array {
            $args['post_status'] = 'publish';
            $args['post_title'] = 'Changed';
            return $args;
        }, 10, 3);
        $post = $this->oneDraft();
        self::assertSame('draft', $post['post_status']);
        self::assertSame('Changed', $post['post_title']);
        $fired = \WpStub::fired('newsapi_pull_draft_created');
        self::assertCount(1, $fired);
        self::assertSame($post['ID'], $fired[0][0]);
        self::assertInstanceOf(PullDefinition::class, $fired[0][2]);
    }

    public function testPendingStatusIsHonoured(): void
    {
        $this->addPull(['status' => 'pending']);
        self::assertSame('pending', $this->oneDraft()['post_status']);
    }
}
