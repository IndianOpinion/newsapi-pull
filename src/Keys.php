<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * Applies the WordPress filters around the pure UrlKey functions
 * (PRD 4.6, cases 44 to 59).
 */
final class Keys
{
    /**
     * Keys for one URL.
     *
     * @return array{status: string, url: string, canonical: string, plain: string, canonical_hash: string, plain_hash: string, version: int, host: string}
     *         status is `ok`, `bad_url` or `wrapper_url`; the key fields are empty unless `ok`.
     */
    public function forUrl(string $url, string $context = 'article'): array
    {
        $empty = ['url' => '', 'canonical' => '', 'plain' => '', 'canonical_hash' => '', 'plain_hash' => '', 'version' => UrlKey::VERSION, 'host' => ''];
        $prepared = UrlKey::prepare($url);
        if (UrlKey::parse($prepared) === null) {
            return ['status' => 'bad_url'] + $empty;
        }
        $filtered = apply_filters('newsapi_pull_url', $prepared, $context);
        if (is_string($filtered) && trim($filtered) !== '') {
            $candidate = trim($filtered);
            if (UrlKey::parse($candidate) !== null) {
                $prepared = $candidate;
            }
        }
        $urlKey = new UrlKey($this->rules());
        $parts = UrlKey::parse($prepared);
        $host = $parts === null ? '' : $urlKey->host($parts['host']);
        if ($this->isWrapperHost($host)) {
            return ['status' => 'wrapper_url', 'url' => $prepared, 'host' => $host] + $empty;
        }
        $canonical = $urlKey->canonical($prepared, true);
        $plain = UrlKey::plain($prepared, true);
        if ($canonical === null || $plain === null) {
            return ['status' => 'bad_url'] + $empty;
        }
        $custom = apply_filters('newsapi_pull_canonical_key', $canonical, $prepared);
        if (is_string($custom) && $custom !== '') {
            $canonical = $custom;
        }
        return [
            'status' => 'ok',
            'url' => $prepared,
            'canonical' => $canonical,
            'plain' => $plain,
            'canonical_hash' => UrlKey::hash($canonical),
            'plain_hash' => UrlKey::hash($plain),
            'version' => UrlKey::VERSION,
            'host' => $host,
        ];
    }

    public function rules(): UrlKeyRules
    {
        return new UrlKeyRules(
            Vocabulary::stringList(apply_filters('newsapi_pull_mirror_labels', UrlKeyRules::MIRROR_LABELS)),
            Vocabulary::stringList(apply_filters('newsapi_pull_tracking_params', UrlKeyRules::TRACKING_PARAMS)),
            Vocabulary::stringList(apply_filters('newsapi_pull_id_params', UrlKeyRules::ID_PARAMS)),
            Vocabulary::stringList(apply_filters('newsapi_pull_script_extensions', UrlKeyRules::SCRIPT_EXTENSIONS)),
            static fn (string $host): bool => (bool) apply_filters('newsapi_pull_lowercase_path', true, $host)
        );
    }

    /**
     * The site's own hosts, normalised like article hosts.
     *
     * @return list<string>
     */
    public function ownHosts(): array
    {
        $urlKey = new UrlKey($this->rules());
        $hosts = [];
        foreach ([home_url('/'), site_url('/')] as $url) {
            $parts = UrlKey::parse((string) $url);
            if ($parts !== null) {
                $hosts[] = $urlKey->host($parts['host']);
            }
        }
        return array_values(array_unique($hosts));
    }

    private function isWrapperHost(string $host): bool
    {
        return self::hostIn($host, Vocabulary::stringList(apply_filters('newsapi_pull_wrapper_hosts', [])));
    }

    /**
     * Whether a host equals, or is a subdomain of, an entry in the list.
     *
     * @param list<string> $list
     */
    public static function hostIn(string $host, array $list): bool
    {
        foreach ($list as $entry) {
            $entry = ltrim($entry, '.');
            if ($entry !== '' && ($host === $entry || str_ends_with($host, '.' . $entry))) {
                return true;
            }
        }
        return false;
    }
}
