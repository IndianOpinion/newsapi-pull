<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * A validated request: the endpoint and its query parameters, without the key.
 */
final class Query
{
    public const ENDPOINT_PATHS = [
        'headlines' => '/v2/top-headlines',
        'everything' => '/v2/everything',
        'sources' => '/v2/top-headlines/sources',
    ];

    /**
     * @param array<string, string> $params
     */
    private function __construct(
        public readonly string $endpoint,
        public readonly array $params,
        public readonly ?PullDefinition $pull = null
    ) {
    }

    /**
     * Builds an article query for a valid pull.
     *
     * @param string|null $from ISO 8601 oldest date (everything only).
     * @param list<string>|null $languageSources Source ids resolved for a headlines language (case 12).
     */
    public static function forPull(PullDefinition $pull, int $page, ?string $from = null, ?array $languageSources = null): self
    {
        $p = [];
        if ($pull->endpoint === 'headlines') {
            if ($languageSources !== null) {
                $p['sources'] = implode(',', $languageSources);
            } elseif ($pull->sources !== []) {
                $p['sources'] = implode(',', $pull->sources);
            } else {
                if ($pull->country !== '') {
                    $p['country'] = $pull->country;
                }
                if ($pull->category !== '') {
                    $p['category'] = $pull->category;
                }
            }
            if ($pull->q !== '') {
                $p['q'] = $pull->q;
            }
        } else {
            if ($pull->q !== '') {
                $p['q'] = $pull->q;
            }
            if ($pull->searchIn !== []) {
                $p['searchIn'] = implode(',', $pull->searchIn);
            }
            if ($pull->sources !== []) {
                $p['sources'] = implode(',', $pull->sources);
            }
            if ($pull->domains !== []) {
                $p['domains'] = implode(',', $pull->domains);
            }
            if ($pull->excludeDomains !== []) {
                $p['excludeDomains'] = implode(',', $pull->excludeDomains);
            }
            if ($from !== null) {
                $p['from'] = $from;
            }
            if ($pull->language !== '') {
                $p['language'] = $pull->language;
            }
            $p['sortBy'] = $pull->sortBy;
        }
        $p['pageSize'] = (string) $pull->pageSize;
        $p['page'] = (string) max(1, $page);
        return new self($pull->endpoint, $p, $pull);
    }

    /**
     * The source catalogue query. Filters are applied locally, so none are sent.
     */
    public static function sources(): self
    {
        return new self('sources', []);
    }

    public function path(): string
    {
        return self::ENDPOINT_PATHS[$this->endpoint];
    }
}
