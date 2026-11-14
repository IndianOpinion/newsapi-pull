<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Tests\Unit;

use IndianOpinion\NewsApiPull\Admin\AdminPage;
use IndianOpinion\NewsApiPull\PullDefinition;
use IndianOpinion\NewsApiPull\Tests\TestCase;

final class PullValidationTest extends TestCase
{
    /**
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    private function errors(array $fields, string $plan = 'paid'): array
    {
        return PullDefinition::fromArray(array_merge(['slug' => 'p', 'endpoint' => 'headlines', 'author' => 1], $fields))->validate($plan);
    }

    public function testT7EndpointSlugAndUnknownFields(): void
    {
        self::assertArrayHasKey('endpoint', $this->errors(['endpoint' => 'sources']));
        self::assertArrayHasKey('slug', $this->errors(['slug' => 'Bad Slug!']));
        $pull = PullDefinition::fromArray(['slug' => 'p', 'endpoint' => 'headlines', 'colour' => 'red']);
        self::assertSame([], $pull->validate('paid'));
        self::assertArrayNotHasKey('colour', $pull->toArray());

        $this->configure();
        $page = new AdminPage($this->plugin);
        $page->handle('save_pull', ['slug' => 'p', 'endpoint' => 'headlines', 'colour' => 'red', '_wpnonce' => wp_create_nonce('newsapi_pull_save_pull')]);
        $stored = get_option('newsapi_pull_pulls');
        self::assertArrayNotHasKey('colour', $stored['p']['definition']);
    }

    public function testT8Country(): void
    {
        $pull = PullDefinition::fromArray(['slug' => 'p', 'endpoint' => 'headlines', 'country' => 'IN']);
        self::assertSame('in', $pull->country);
        self::assertSame([], $pull->validate('paid'));
        self::assertArrayHasKey('country', $this->errors(['country' => 'xx']));
        self::assertArrayHasKey('country', $this->errors(['endpoint' => 'everything', 'q' => 'x', 'country' => 'us']));
    }

    public function testCountryOutsideTheDocumentedListIsAcceptedWithAWarning(): void
    {
        $pull = PullDefinition::fromArray(['slug' => 'p', 'endpoint' => 'headlines', 'country' => 'ke']);
        self::assertSame([], $pull->validate('paid'));
        self::assertStringContainsString('not in the documented list', $pull->warnings()[0]);
        add_filter('newsapi_pull_countries', static fn (array $c): array => array_merge($c, ['ke']));
        self::assertSame([], $pull->warnings());
    }

    public function testT9Category(): void
    {
        self::assertArrayHasKey('category', $this->errors(['category' => 'politics']));
        self::assertArrayHasKey('category', $this->errors(['endpoint' => 'everything', 'q' => 'x', 'category' => 'business']));
        self::assertSame([], $this->errors(['category' => 'business']));
    }

    public function testT10TwentyOneSources(): void
    {
        $sources = array_map(static fn (int $i): string => 'src-' . $i, range(1, 21));
        $errors = $this->errors(['sources' => $sources]);
        self::assertStringContainsString('21', $errors['sources']);
        self::assertSame([], $this->errors(['sources' => array_slice($sources, 0, 20)]));
    }

    public function testT11SourcesWithCountryOrCategory(): void
    {
        self::assertArrayHasKey('sources', $this->errors(['sources' => 'bbc-news', 'country' => 'gb']));
        self::assertArrayHasKey('sources', $this->errors(['sources' => 'bbc-news', 'category' => 'health']));
    }

    public function testT12Language(): void
    {
        self::assertArrayHasKey('language', $this->errors(['endpoint' => 'everything', 'q' => 'x', 'language' => 'hi']));
        self::assertSame([], $this->errors(['endpoint' => 'everything', 'q' => 'x', 'language' => 'en']));
    }

    public function testT15Keywords(): void
    {
        self::assertArrayHasKey('q', $this->errors(['endpoint' => 'everything', 'q' => str_repeat('a', 501)]));
        self::assertSame([], $this->errors(['endpoint' => 'everything', 'q' => str_repeat('a', 500)]));
        self::assertArrayHasKey('q', $this->errors(['endpoint' => 'everything']));
        self::assertSame([], $this->errors(['endpoint' => 'everything', 'domains' => 'example.com']));
    }

    public function testT16SearchInAndSort(): void
    {
        self::assertArrayHasKey('search_in', $this->errors(['endpoint' => 'everything', 'q' => 'x', 'search_in' => ['body']]));
        self::assertArrayHasKey('sort_by', $this->errors(['endpoint' => 'everything', 'q' => 'x', 'sort_by' => 'newest']));
        self::assertSame([], $this->errors(['endpoint' => 'everything', 'q' => 'x', 'search_in' => ['title', 'content'], 'sort_by' => 'relevancy']));
    }

    public function testT17Domains(): void
    {
        $pull = PullDefinition::fromArray(['slug' => 'p', 'endpoint' => 'everything', 'domains' => 'https://Example.com/path, news.example.org']);
        self::assertSame(['example.com', 'news.example.org'], $pull->domains);
        $errors = $this->errors(['endpoint' => 'everything', 'domains' => 'example.com, not a host']);
        self::assertStringContainsString('not a host', $errors['domains']);
    }

    public function testT19RangesAndInterval(): void
    {
        self::assertArrayHasKey('page_size', $this->errors(['page_size' => 0]));
        self::assertArrayHasKey('page_size', $this->errors(['page_size' => 101]));
        self::assertArrayHasKey('max_pages', $this->errors(['max_pages' => 6]));
        self::assertArrayHasKey('interval', $this->errors(['interval' => 10]));
        self::assertSame([], $this->errors(['page_size' => 100, 'max_pages' => 5, 'interval' => 15]));
    }

    public function testPagingIsRefusedOnTheDeveloperPlan(): void
    {
        self::assertArrayHasKey('max_pages', $this->errors(['max_pages' => 2], 'developer'));
        self::assertSame([], $this->errors(['max_pages' => 2], 'paid'));
    }

    public function testT20PublishStatusRefusedOnSaveAndCoercedAtWrite(): void
    {
        self::assertArrayHasKey('status', $this->errors(['status' => 'publish']));
        foreach (['future', 'private'] as $status) {
            self::assertArrayHasKey('status', $this->errors(['status' => $status]));
        }
        $pull = PullDefinition::fromArray(['slug' => 'p', 'endpoint' => 'headlines', 'status' => 'publish']);
        self::assertSame('draft', $pull->status());
        self::assertSame('draft', $pull->toArray()['status']);
        self::assertSame('pending', PullDefinition::fromArray(['status' => 'pending'])->status());
    }

    public function testPostTypeAndAuthorAreChecked(): void
    {
        self::assertArrayHasKey('post_type', $this->errors(['post_type' => 'attachment']));
        self::assertArrayHasKey('author', $this->errors(['author' => 99]));
    }
}
