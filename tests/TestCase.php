<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Tests;

use IndianOpinion\NewsApiPull\Plugin;
use IndianOpinion\NewsApiPull\PullDefinition;
use IndianOpinion\NewsApiPull\Settings;

/**
 * Shared set-up: a fresh stub WordPress, a fixed clock, captured log lines.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const KEY = 'abcdef0123456789abcdef0123456789';

    /** 2026-11-14 12:00:00 UTC */
    public const NOW = 1794657600;

    protected FixedClock $clock;

    /** @var list<string> */
    protected array $logLines = [];

    /** @var array<string, mixed> */
    protected array $constants = [];

    protected Plugin $plugin;

    protected function setUp(): void
    {
        \WpStub::reset();
        \WP_CLI::reset();
        $this->clock = new FixedClock(self::NOW);
        $this->logLines = [];
        $this->constants = [];
        $this->plugin = $this->makePlugin();
        Plugin::setInstance($this->plugin);
    }

    protected function tearDown(): void
    {
        Plugin::setInstance(null);
    }

    protected function makePlugin(?bool $network = false): Plugin
    {
        $settings = new Settings(fn (string $name): mixed => $this->constants[$name] ?? null);
        return new Plugin($this->clock, $settings, function (string $line): void {
            $this->logLines[] = $line;
        }, null, $network);
    }

    /**
     * Plan, key and environment ready for a run.
     *
     * @param array<string, mixed> $extra
     */
    protected function configure(string $plan = 'paid', string $env = 'production', array $extra = []): void
    {
        \WpStub::$env = $env;
        $this->plugin->settings->save(array_merge(['plan' => $plan, 'api_key' => self::KEY], $extra));
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function addPull(array $fields = []): PullDefinition
    {
        $pull = PullDefinition::fromArray(array_merge([
            'slug' => 'top',
            'endpoint' => 'headlines',
            'country' => 'us',
            'page_size' => 20,
            'interval' => 60,
            'author' => 1,
        ], $fields));
        $this->plugin->pulls->save($pull);
        return $pull;
    }

    /**
     * One raw API article.
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    protected function article(string $url, array $over = []): array
    {
        return array_merge([
            'source' => ['id' => 'example-news', 'name' => 'Example News'],
            'author' => 'A. Writer',
            'title' => 'A headline about ' . md5($url),
            'description' => 'A short description of the story.',
            'url' => $url,
            'urlToImage' => 'https://img.example.com/a.jpg',
            'publishedAt' => gmdate('Y-m-d\TH:i:s\Z', self::NOW - 3600),
            'content' => 'Truncated content of the article... [+1234 chars]',
        ], $over);
    }

    /**
     * Queues an articles response.
     *
     * @param list<array<string, mixed>> $articles
     * @param array<string, string> $headers
     */
    protected function queueArticles(array $articles, ?int $total = null, array $headers = []): void
    {
        \WpStub::$responses[] = \WpStub::response([
            'status' => 'ok',
            'totalResults' => $total ?? count($articles),
            'articles' => $articles,
        ], 200, $headers);
    }

    protected function queueError(string $code, int $http = 400, string $message = 'Error message.'): void
    {
        \WpStub::$responses[] = \WpStub::response(['status' => 'error', 'code' => $code, 'message' => $message], $http);
    }

    /**
     * @param list<array<string, string>> $sources
     */
    protected function queueSources(array $sources): void
    {
        \WpStub::$responses[] = \WpStub::response(['status' => 'ok', 'sources' => $sources]);
    }

    /**
     * @return list<array<string, string>>
     */
    protected function catalogue(): array
    {
        $out = [];
        foreach ([['de', 'de'], ['fr', 'fr'], ['en', 'us'], ['en', 'gb']] as $i => [$lang, $country]) {
            for ($n = 1; $n <= ($lang === 'de' ? 25 : 3); $n++) {
                $out[] = ['id' => "$lang-$country-$n", 'name' => "Source $lang $n", 'description' => '', 'url' => "https://$lang$n.example.com", 'category' => 'general', 'language' => $lang, 'country' => $country];
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    protected function runPull(string $slug = 'top', bool $manual = false, bool $dry = false, bool $scheduled = false): array
    {
        return $this->plugin->runner->run($slug, $manual, $dry, $scheduled);
    }

    /**
     * @return array<string, string> Query parameters of a recorded request.
     */
    protected function requestParams(int $i): array
    {
        $query = parse_url(\WpStub::$requests[$i]['url'], PHP_URL_QUERY);
        parse_str((string) $query, $params);
        /** @var array<string, string> $params */
        return $params;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function drafts(): array
    {
        return array_values(array_filter(\WpStub::$posts, static fn (array $p): bool => ($p['post_status'] ?? '') !== 'trash' && ($p['post_type'] ?? 'post') !== 'attachment'));
    }
}
