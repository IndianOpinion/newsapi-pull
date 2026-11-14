<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * One saved fetch definition (PRD 4.3). Immutable. Unknown fields are dropped.
 */
final class PullDefinition
{
    public const MAX_SOURCES = 20;

    public const MAX_QUERY = 500;

    public const ALLOWED_STATUSES = ['draft', 'pending'];

    /**
     * @param list<string> $sources
     * @param list<string> $searchIn
     * @param list<string> $domains
     * @param list<string> $excludeDomains
     * @param list<int> $categories
     * @param list<string> $tags
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $endpoint,
        public readonly string $country,
        public readonly string $category,
        public readonly array $sources,
        public readonly string $language,
        public readonly string $q,
        public readonly array $searchIn,
        public readonly array $domains,
        public readonly array $excludeDomains,
        public readonly string $sortBy,
        public readonly int $pageSize,
        public readonly int $maxPages,
        public readonly int $interval,
        public readonly bool $paused,
        public readonly string $postType,
        public readonly int $author,
        public readonly array $categories,
        public readonly array $tags,
        private readonly string $rawStatus,
        public readonly bool $sourceTag,
        public readonly ?int $maxAgeHours,
        public readonly ?int $dailyCap,
        /** @var list<string> Domain entries that failed host validation, kept for the error message. */
        private readonly array $badDomains
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $bad = [];
        $domains = self::hosts($data['domains'] ?? [], $bad);
        $exclude = self::hosts($data['exclude_domains'] ?? [], $bad);
        return new self(
            self::str($data, 'slug'),
            self::str($data, 'endpoint'),
            strtolower(self::str($data, 'country')),
            strtolower(self::str($data, 'category')),
            self::list($data['sources'] ?? [], true),
            strtolower(self::str($data, 'language')),
            self::str($data, 'q'),
            self::list($data['search_in'] ?? [], false),
            $domains,
            $exclude,
            self::str($data, 'sort_by') !== '' ? self::str($data, 'sort_by') : 'publishedAt',
            self::int($data, 'page_size', 50),
            self::int($data, 'max_pages', 1),
            self::int($data, 'interval', 60),
            !empty($data['paused']),
            self::str($data, 'post_type') !== '' ? self::str($data, 'post_type') : 'post',
            self::int($data, 'author', 0),
            array_values(array_filter(array_map('intval', is_array($data['categories'] ?? null) ? $data['categories'] : []), static fn (int $id): bool => $id > 0)),
            self::list($data['tags'] ?? [], false),
            self::str($data, 'status') !== '' ? strtolower(self::str($data, 'status')) : 'draft',
            !empty($data['source_tag']),
            isset($data['max_age_hours']) && is_numeric($data['max_age_hours']) && (int) $data['max_age_hours'] > 0 ? (int) $data['max_age_hours'] : null,
            isset($data['daily_cap']) && is_numeric($data['daily_cap']) && (int) $data['daily_cap'] > 0 ? (int) $data['daily_cap'] : null,
            $bad
        );
    }

    /**
     * Known fields only, ready to store or export.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'endpoint' => $this->endpoint,
            'country' => $this->country,
            'category' => $this->category,
            'sources' => $this->sources,
            'language' => $this->language,
            'q' => $this->q,
            'search_in' => $this->searchIn,
            'domains' => $this->domains,
            'exclude_domains' => $this->excludeDomains,
            'sort_by' => $this->sortBy,
            'page_size' => $this->pageSize,
            'max_pages' => $this->maxPages,
            'interval' => $this->interval,
            'paused' => $this->paused,
            'post_type' => $this->postType,
            'author' => $this->author,
            'categories' => $this->categories,
            'tags' => $this->tags,
            'status' => $this->status(),
            'source_tag' => $this->sourceTag,
            'max_age_hours' => $this->maxAgeHours,
            'daily_cap' => $this->dailyCap,
        ];
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $data = $this->toArray();
        $data['status'] = $this->rawStatus;
        return self::fromArray(array_merge($data, $changes));
    }

    /** The status a draft is written with: `pending` if asked, else `draft`. */
    public function status(): string
    {
        return $this->rawStatus === 'pending' ? 'pending' : 'draft';
    }

    /**
     * Field-level validation. Empty array means valid.
     *
     * @param string $plan The declared plan, used for plan-dependent rules.
     * @return array<string, string> Error messages keyed by field.
     */
    public function validate(string $plan = ''): array
    {
        $e = [];
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->slug) || strlen($this->slug) > 64) {
            $e['slug'] = 'The slug must be lower-case letters, digits and single hyphens, at most 64 characters.';
        }
        if (!in_array($this->endpoint, Vocabulary::ENDPOINTS, true)) {
            $e['endpoint'] = 'The endpoint must be "headlines" or "everything".';
            return $e;
        }
        $headlines = $this->endpoint === 'headlines';
        if ($this->country !== '') {
            if (!$headlines) {
                $e['country'] = 'Country applies only to the headlines endpoint.';
            } elseif (!preg_match('/^[a-z]{2}$/', $this->country) || !Vocabulary::isIsoCountry($this->country)) {
                $e['country'] = sprintf('"%s" is not a two-letter ISO 3166-1 country code.', $this->country);
            }
        }
        if ($this->category !== '') {
            if (!$headlines) {
                $e['category'] = 'Category applies only to the headlines endpoint.';
            } elseif (!in_array($this->category, Vocabulary::categories(), true)) {
                $e['category'] = sprintf('"%s" is not a known category.', $this->category);
            }
        }
        if (count($this->sources) > self::MAX_SOURCES) {
            $e['sources'] = sprintf('%d sources given; at most %d are allowed.', count($this->sources), self::MAX_SOURCES);
        } else {
            foreach ($this->sources as $id) {
                if (!preg_match('/^[a-z0-9][a-z0-9._\-]{0,99}$/', $id)) {
                    $e['sources'] = sprintf('"%s" is not a valid source id.', $id);
                    break;
                }
            }
        }
        if ($headlines && $this->sources !== [] && ($this->country !== '' || $this->category !== '')) {
            $e['sources'] = 'On the headlines endpoint, sources cannot be combined with country or category.';
        }
        if ($this->language !== '' && !in_array($this->language, Vocabulary::languages(), true)) {
            $e['language'] = sprintf('"%s" is not a supported language code.', $this->language);
        }
        if (mb_strlen($this->q, 'UTF-8') > self::MAX_QUERY) {
            $e['q'] = sprintf('Keywords are %d characters; at most %d are allowed.', mb_strlen($this->q, 'UTF-8'), self::MAX_QUERY);
        } elseif (!$headlines && $this->q === '' && $this->sources === [] && $this->domains === []) {
            $e['q'] = 'The everything endpoint needs keywords, sources or domains.';
        }
        foreach ($this->searchIn as $field) {
            if (!in_array($field, Vocabulary::SEARCH_IN, true)) {
                $e['search_in'] = sprintf('"%s" is not one of title, description, content.', $field);
            }
        }
        if ($headlines && $this->searchIn !== []) {
            $e['search_in'] = 'Search in applies only to the everything endpoint.';
        }
        if ($this->badDomains !== []) {
            $e['domains'] = sprintf('"%s" is not a valid host name.', $this->badDomains[0]);
        } elseif ($headlines && ($this->domains !== [] || $this->excludeDomains !== [])) {
            $e['domains'] = 'Domains apply only to the everything endpoint.';
        }
        if (!in_array($this->sortBy, Vocabulary::SORT_BY, true)) {
            $e['sort_by'] = sprintf('"%s" is not one of publishedAt, relevancy, popularity.', $this->sortBy);
        } elseif ($headlines && $this->sortBy !== 'publishedAt') {
            $e['sort_by'] = 'Sort applies only to the everything endpoint.';
        }
        if ($this->pageSize < 1 || $this->pageSize > 100) {
            $e['page_size'] = 'Page size must be between 1 and 100.';
        }
        if ($this->maxPages < 1 || $this->maxPages > 5) {
            $e['max_pages'] = 'Max pages per run must be between 1 and 5.';
        } elseif ($plan === 'developer' && $this->maxPages > 1) {
            $e['max_pages'] = 'The developer plan fetches page 1 only; paging needs plan paid.';
        }
        if ($this->interval < 15) {
            $e['interval'] = 'The interval must be at least 15 minutes.';
        }
        if (!in_array($this->rawStatus, self::ALLOWED_STATUSES, true)) {
            $e['status'] = 'Drafts are written as draft or pending only.';
        }
        if (
            !function_exists('post_type_exists') || !post_type_exists($this->postType)
            || !post_type_supports($this->postType, 'title') || !post_type_supports($this->postType, 'editor')
        ) {
            $e['post_type'] = sprintf('"%s" is not a post type with a title and an editor.', $this->postType);
        }
        if ($this->author > 0 && !user_can($this->author, 'edit_posts')) {
            $e['author'] = 'The author must be a user who can edit posts.';
        }
        return $e;
    }

    /**
     * Advice that does not block a save.
     *
     * @param list<string>|null $catalogueIds Source ids in the cached catalogue, null when none is cached.
     * @return list<string>
     */
    public function warnings(?array $catalogueIds = null): array
    {
        $w = [];
        if ($this->country !== '' && Vocabulary::isIsoCountry($this->country) && !in_array($this->country, Vocabulary::countries(), true)) {
            $w[] = sprintf('Country "%s" is not in the documented list; the API may return no articles for it.', $this->country);
        }
        if ($catalogueIds !== null) {
            foreach ($this->sources as $id) {
                if (!in_array($id, $catalogueIds, true)) {
                    $w[] = sprintf('Source "%s" is not in the cached source catalogue.', $id);
                }
            }
        }
        return $w;
    }

    /**
     * @param array<mixed> $data
     */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<mixed> $data
     */
    private static function int(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? null;
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value, bool $lower): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $out[] = $lower ? strtolower($item) : $item;
        }
        return array_values(array_unique($out));
    }

    /**
     * Normalises domain entries: trimmed, lower-cased, scheme and path removed.
     *
     * @param list<string> $bad Receives entries that are not host names.
     * @return list<string>
     */
    private static function hosts(mixed $value, array &$bad): array
    {
        $out = [];
        foreach (self::list($value, true) as $entry) {
            $host = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $entry);
            $host = (string) preg_replace('#[/?\#].*$#', '', $host);
            $host = rtrim($host, '.');
            if (!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?$/', $host)) {
                $bad[] = $entry;
                continue;
            }
            $out[] = $host;
        }
        return array_values(array_unique($out));
    }
}
