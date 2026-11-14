<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Tests\Unit;

use IndianOpinion\NewsApiPull\SeenStore;
use IndianOpinion\NewsApiPull\Tests\TestCase;
use IndianOpinion\NewsApiPull\UrlKey;
use IndianOpinion\NewsApiPull\UrlKeyRules;

final class UrlKeyTest extends TestCase
{
    private function key(string $url): ?string
    {
        return (new UrlKey())->canonical($url);
    }

    private function assertMeet(string ...$urls): void
    {
        $first = $this->key($urls[0]);
        self::assertNotNull($first, $urls[0]);
        foreach ($urls as $url) {
            self::assertSame($first, $this->key($url), $url);
        }
    }

    private function assertDiffer(string $a, string $b): void
    {
        self::assertNotSame($this->key($a), $this->key($b), "$a vs $b");
    }

    public function testT40EntitiesDecodedOnceAndBadUrls(): void
    {
        self::assertSame('v1|example.com/news.php?a=1&b=2', $this->key('https://example.com/news.php?a=1&amp;b=2'));
        // Decoded once only: a double decode would give a=1&b=2.
        self::assertSame('v1|example.com/news.php?a=1&amp%3Bb=2', $this->key('https://example.com/news.php?a=1&amp;amp;b=2'));
        foreach (['ftp://x', 'example.com/a', 'https:///a', 'javascript:alert(1)', ''] as $bad) {
            self::assertNull($this->key($bad), $bad);
            self::assertNull(UrlKey::plain($bad), $bad);
        }
        $this->configure();
        $this->addPull();
        $this->queueArticles([$this->article('ftp://x'), $this->article('example.com/a'), $this->article('https:///a')]);
        $summary = $this->runPull();
        self::assertSame(['bad_url' => 3], $summary['rejections']);
        self::assertSame([], $GLOBALS['wpdb']->rows());
    }

    public function testT41UrlFilter(): void
    {
        $before = newsapi_pull_keys('https://wrap.example.net/r/abc');
        add_filter('newsapi_pull_url', static fn (string $url): string => str_contains($url, 'wrap.example.net') ? 'https://publisher.example.com/story' : $url, 10, 2);
        $after = newsapi_pull_keys('https://wrap.example.net/r/abc');
        self::assertSame('v1|publisher.example.com/story', $after['canonical']);
        self::assertNotSame($before['canonical'], $after['canonical']);
        self::assertNotSame($before['plain'], $after['plain']);

        \WpStub::reset();
        add_filter('newsapi_pull_url', static fn (): mixed => null);
        self::assertSame('v1|example.com/a', newsapi_pull_keys('https://example.com/a')['canonical']);
    }

    public function testT42WrapperHostRejectedWithNoRow(): void
    {
        add_filter('newsapi_pull_wrapper_hosts', static fn (): array => ['news.aggregator.example']);
        $this->configure();
        $this->addPull();
        $this->queueArticles([$this->article('https://news.aggregator.example/rss/articles/xyz')]);
        $summary = $this->runPull();
        self::assertSame(['wrapper_url' => 1], $summary['rejections']);
        self::assertSame([], $GLOBALS['wpdb']->rows());
        self::assertSame([], newsapi_pull_keys('https://news.aggregator.example/rss/articles/xyz'));
    }

    public function testT43UserInfoDropped(): void
    {
        self::assertSame('v1|example.com/a', $this->key('https://u:p@example.com/a'));
        self::assertSame('example.com/a', UrlKey::plain('https://u:p@example.com/a'));
    }

    public function testT44SchemePortHostCase(): void
    {
        $this->assertMeet('http://example.com/a', 'https://example.com/a', 'https://example.com:443/a', 'http://example.com:80/a', 'https://EXAMPLE.com/a', 'https://example.com./a');
        $this->assertDiffer('https://example.com/a', 'https://example.com:8080/a');
        if (function_exists('idn_to_ascii')) {
            $this->assertMeet('https://bücher.example/a', 'https://xn--bcher-kva.example/a', 'https://XN--BCHER-KVA.example/a');
        }
    }

    public function testT45DeliveryLabels(): void
    {
        $this->assertMeet('https://example.com/a', 'https://www.example.com/a', 'https://m.example.com/a', 'https://amp.example.com/a', 'https://www.m.example.com/a', 'https://mobile.example.com/a');
        self::assertSame('v1|m.com/a', $this->key('https://m.com/a'));
        self::assertSame('v1|192.168.1.10/a', $this->key('http://192.168.1.10/a'));
        $this->assertDiffer('https://hindi.example.com/a', 'https://example.com/a');
        $this->assertDiffer('https://en.example.com/a', 'https://example.com/a');
    }

    public function testT46Path(): void
    {
        $this->assertMeet('https://example.com/a/b', 'https://example.com/a//b/', 'https://example.com/a/./b', 'https://example.com/A/B/index.html', 'https://example.com/a/c/../b');
        $this->assertDiffer('https://example.com/a%2Fb', 'https://example.com/a/b');
        $this->assertMeet('https://example.com/%7E', 'https://example.com/~', 'https://example.com/%7e');
        $this->assertMeet('https://example.com/caf%C3%A9', 'https://example.com/café', 'https://example.com/caf%c3%a9');
        self::assertSame('v1|example.com/', $this->key('https://example.com'));

        add_filter('newsapi_pull_lowercase_path', static fn (bool $lower, string $host): bool => $host !== 'cased.example.com', 10, 2);
        $keys = new \IndianOpinion\NewsApiPull\Keys();
        $upper = $keys->forUrl('https://cased.example.com/A');
        $lower = $keys->forUrl('https://cased.example.com/a');
        self::assertNotSame($upper['canonical'], $lower['canonical']);
        self::assertSame($keys->forUrl('https://other.example.com/A')['canonical'], $keys->forUrl('https://other.example.com/a')['canonical']);
    }

    public function testT47AmpForms(): void
    {
        $this->assertMeet(
            'https://example.com/news/story',
            'https://example.com/amp/news/story',
            'https://example.com/news/amp/story',
            'https://example.com/news/story/amp',
            'https://example.com/news/story/amp/',
            'https://example.com/news/story/amp/1',
            'https://example.com/news/story.amp'
        );
        $this->assertMeet('https://example.com/news/story.html', 'https://example.com/news/story.amp.html', 'https://example.com/news/story-amp.html');
        self::assertSame('v1|example.com/camp', $this->key('https://example.com/camp/'));
        self::assertSame('v1|example.com/amplifier', $this->key('https://example.com/amplifier'));
    }

    public function testT48Query(): void
    {
        $this->assertMeet('https://example.com/a/story', 'https://example.com/a/story?utm_source=x&utm_medium=y', 'https://example.com/a/story?fbclid=1&gclid=2', 'https://example.com/a/story?ref=home');
        $this->assertMeet('https://example.com/list.php?b=2&a=1', 'https://example.com/list.php?a=1&b=2');
        $this->assertMeet('https://example.com/story?page=2', 'https://example.com/story');
        $this->assertDiffer('https://example.com/news?id=42', 'https://example.com/news?id=43');
        $this->assertDiffer('https://example.com/?p=1', 'https://example.com/?p=2');
        self::assertSame('v1|example.com/news?id=2', $this->key('https://example.com/news?id=1&id=2'));
        self::assertSame('v1|example.com/x.php?q=Case', $this->key('https://example.com/x.php?Q=Case&empty=&arr[]=1'));
        self::assertSame('v1|example.com/x', $this->key('https://example.com/x?'));
    }

    public function testT49FragmentFilterLocaleVersion(): void
    {
        $this->assertMeet('https://example.com/a', 'https://example.com/a#section');
        add_filter('newsapi_pull_canonical_key', static fn (string $key): string => 'custom|' . $key, 10, 2);
        self::assertSame('custom|v1|example.com/a', newsapi_pull_keys('https://example.com/a')['canonical']);
        \WpStub::reset();
        add_filter('newsapi_pull_canonical_key', static fn (): string => '', 10, 2);
        self::assertSame('v1|example.com/a', newsapi_pull_keys('https://example.com/a')['canonical']);

        $previous = setlocale(LC_ALL, '0');
        $a = $this->key('https://WWW.EXAMPLE.COM/Ii/İstanbul?ID=1');
        setlocale(LC_ALL, 'tr_TR.UTF-8', 'tr_TR', 'C');
        $b = $this->key('https://WWW.EXAMPLE.COM/Ii/İstanbul?ID=1');
        setlocale(LC_ALL, (string) $previous);
        self::assertSame($a, $b);
        self::assertSame(1, newsapi_pull_keys('https://example.com/a')['version']);
        self::assertStringStartsWith('v' . UrlKey::VERSION . '|', (string) $a);
    }

    public function testT50PlainKeyMatchesFrozenFixture(): void
    {
        $lines = file(dirname(__DIR__) . '/fixtures/plain-keys.tsv', FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(20, $lines);
        foreach ($lines as $line) {
            [$url, $expected] = explode("\t", $line);
            self::assertSame($expected, UrlKey::plain($url), $url);
        }
    }

    public function testT51OldCanonicalVersionStillMatchesThroughPlainKey(): void
    {
        $this->configure();
        $this->plugin->seen->install();
        $url = 'https://www.example.com/news/story/';
        $plain = (string) UrlKey::plain($url);
        $this->plugin->seen->record([
            'canonical_hash' => UrlKey::hash('v0|old-rules|example.com/news/story/'),
            'plain_hash' => UrlKey::hash($plain),
            'version' => 0,
        ], 55, 'imported', 'top');
        self::assertTrue(newsapi_pull_is_seen($url));
        self::assertFalse(newsapi_pull_is_seen('https://www.example.com/news/other/'));
    }

    public function testT52SameUrlTwiceInOneResponseCreatesOneDraft(): void
    {
        $this->configure();
        $this->addPull();
        $this->queueArticles([
            $this->article('https://example.com/a/story'),
            $this->article('https://m.example.com/a/story/?utm_source=feed'),
        ]);
        $summary = $this->runPull();
        self::assertSame(1, $summary['created']);
        self::assertSame(['seen' => 1], $summary['rejections']);
    }

    public function testT53OwnHostRejectedAndNotRecorded(): void
    {
        $this->configure();
        $this->addPull();
        $this->queueArticles([$this->article('https://www.my-site.test/2026/11/our-story/')]);
        $summary = $this->runPull();
        self::assertSame(['own_host' => 1], $summary['rejections']);
        self::assertSame([], $GLOBALS['wpdb']->rows());
    }

    public function testT54ConcurrentInsertIsSeenAndTheDraftIsTrashed(): void
    {
        $this->configure();
        $this->addPull();
        $this->plugin->seen->install();
        $GLOBALS['wpdb']->duplicateNext = true;
        $this->queueArticles([$this->article('https://example.com/race')]);
        $summary = $this->runPull();
        self::assertSame(0, $summary['created']);
        self::assertSame(1, $summary['duplicates']);
        self::assertSame('trash', \WpStub::$posts[100]['post_status']);
        self::assertStringContainsString('moved to the trash', implode("\n", $this->logLines));
    }

    public function testRulesAreConfigurable(): void
    {
        $k = new UrlKey(new UrlKeyRules(['news'], [], ['page'], ['php']));
        self::assertSame('v1|example.com/story?page=2', $k->canonical('https://news.example.com/story?page=2&utm_x=1'));
        self::assertSame(SeenStore::TABLE, 'newsapi_pull_seen');
    }
}
