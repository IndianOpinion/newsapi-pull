<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

use IndianOpinion\NewsApiPull\Exception\NewsApiPullException;

/**
 * The cached source catalogue (PRD 4.9): a transient for the fresh copy and a
 * non-expiring option holding the last good copy, used whatever its age when
 * a fetch fails.
 */
final class Catalogue
{
    public const TRANSIENT = 'newsapi_pull_catalogue';

    public const FALLBACK_OPTION = 'newsapi_pull_catalogue_fallback';

    /** @var array{sources: list<array<string, string>>, fetched_at: int, stale: bool}|null */
    private ?array $loaded = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly Logger $logger
    ) {
    }

    /**
     * The catalogue, fetching it when the cached copy is older than the TTL.
     *
     * @param (callable(): list<array<string, string>>)|null $fetch Fetches and counts one sources request; throws on failure.
     *        Null means "use the cache only".
     * @return array{sources: list<array<string, string>>, fetched_at: int, stale: bool}|null Null when no copy exists.
     */
    public function get(?callable $fetch, bool $refresh = false): ?array
    {
        if ($this->loaded !== null && !$refresh) {
            return $this->loaded;
        }
        $fresh = get_transient(self::TRANSIENT);
        if (
            !$refresh && is_array($fresh) && isset($fresh['sources'], $fresh['fetched_at'])
            && $this->clock->now() - (int) $fresh['fetched_at'] < $this->settings->catalogueTtl()
        ) {
            return $this->loaded = ['sources' => self::clean($fresh['sources']), 'fetched_at' => (int) $fresh['fetched_at'], 'stale' => false];
        }
        if ($fetch !== null) {
            try {
                $sources = $fetch();
                $copy = ['sources' => $sources, 'fetched_at' => $this->clock->now()];
                set_transient(self::TRANSIENT, $copy, $this->settings->catalogueTtl());
                update_option(self::FALLBACK_OPTION, $copy, false);
                return $this->loaded = ['sources' => $sources, 'fetched_at' => $copy['fetched_at'], 'stale' => false];
            } catch (NewsApiPullException $e) {
                $this->logger->warning('Source catalogue fetch failed: ' . $e->getMessage());
            }
        }
        $fallback = get_option(self::FALLBACK_OPTION, null);
        if (is_array($fallback) && isset($fallback['sources'], $fallback['fetched_at'])) {
            $fetchedAt = (int) $fallback['fetched_at'];
            return $this->loaded = [
                'sources' => self::clean($fallback['sources']),
                'fetched_at' => $fetchedAt,
                'stale' => $this->clock->now() - $fetchedAt >= $this->settings->catalogueTtl(),
            ];
        }
        return null;
    }

    /**
     * The cached copy without fetching.
     *
     * @return array{sources: list<array<string, string>>, fetched_at: int, stale: bool}|null
     */
    public function cached(): ?array
    {
        return $this->get(null);
    }

    /**
     * @return list<string>|null
     */
    public function cachedIds(): ?array
    {
        $copy = $this->cached();
        return $copy === null ? null : array_column($copy['sources'], 'id');
    }

    /**
     * Sources in catalogue order matching the filters.
     *
     * @param list<array<string, string>> $sources
     * @return list<array<string, string>>
     */
    public static function filter(array $sources, string $language = '', string $country = '', string $category = ''): array
    {
        return array_values(array_filter($sources, static function (array $s) use ($language, $country, $category): bool {
            return ($language === '' || ($s['language'] ?? '') === $language)
                && ($country === '' || ($s['country'] ?? '') === $country)
                && ($category === '' || ($s['category'] ?? '') === $category);
        }));
    }

    /**
     * The language of a source id, or null when it is not in the catalogue.
     *
     * @param list<array<string, string>> $sources
     */
    public static function languageOf(array $sources, string $id): ?string
    {
        foreach ($sources as $s) {
            if (($s['id'] ?? '') === $id) {
                return $s['language'] ?? null;
            }
        }
        return null;
    }

    public function delete(): void
    {
        delete_transient(self::TRANSIENT);
        delete_option(self::FALLBACK_OPTION);
        $this->loaded = null;
    }

    /**
     * @return list<array<string, string>>
     */
    private static function clean(mixed $sources): array
    {
        $out = [];
        if (is_array($sources)) {
            foreach ($sources as $s) {
                if (is_array($s) && isset($s['id']) && is_string($s['id'])) {
                    $row = [];
                    foreach (['id', 'name', 'description', 'url', 'category', 'language', 'country'] as $k) {
                        $row[$k] = isset($s[$k]) && is_string($s[$k]) ? $s[$k] : '';
                    }
                    $out[] = $row;
                }
            }
        }
        return $out;
    }
}
