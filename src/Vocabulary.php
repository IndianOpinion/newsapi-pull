<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * Allowed values for pull fields, each list filterable.
 */
final class Vocabulary
{
    /**
     * Every assigned ISO 3166-1 alpha-2 code. A country filter must be one of these.
     */
    public const ISO_COUNTRIES = [
        'ad', 'ae', 'af', 'ag', 'ai', 'al', 'am', 'ao', 'aq', 'ar', 'as', 'at', 'au', 'aw', 'ax', 'az',
        'ba', 'bb', 'bd', 'be', 'bf', 'bg', 'bh', 'bi', 'bj', 'bl', 'bm', 'bn', 'bo', 'bq', 'br', 'bs',
        'bt', 'bv', 'bw', 'by', 'bz', 'ca', 'cc', 'cd', 'cf', 'cg', 'ch', 'ci', 'ck', 'cl', 'cm', 'cn',
        'co', 'cr', 'cu', 'cv', 'cw', 'cx', 'cy', 'cz', 'de', 'dj', 'dk', 'dm', 'do', 'dz', 'ec', 'ee',
        'eg', 'eh', 'er', 'es', 'et', 'fi', 'fj', 'fk', 'fm', 'fo', 'fr', 'ga', 'gb', 'gd', 'ge', 'gf',
        'gg', 'gh', 'gi', 'gl', 'gm', 'gn', 'gp', 'gq', 'gr', 'gs', 'gt', 'gu', 'gw', 'gy', 'hk', 'hm',
        'hn', 'hr', 'ht', 'hu', 'id', 'ie', 'il', 'im', 'in', 'io', 'iq', 'ir', 'is', 'it', 'je', 'jm',
        'jo', 'jp', 'ke', 'kg', 'kh', 'ki', 'km', 'kn', 'kp', 'kr', 'kw', 'ky', 'kz', 'la', 'lb', 'lc',
        'li', 'lk', 'lr', 'ls', 'lt', 'lu', 'lv', 'ly', 'ma', 'mc', 'md', 'me', 'mf', 'mg', 'mh', 'mk',
        'ml', 'mm', 'mn', 'mo', 'mp', 'mq', 'mr', 'ms', 'mt', 'mu', 'mv', 'mw', 'mx', 'my', 'mz', 'na',
        'nc', 'ne', 'nf', 'ng', 'ni', 'nl', 'no', 'np', 'nr', 'nu', 'nz', 'om', 'pa', 'pe', 'pf', 'pg',
        'ph', 'pk', 'pl', 'pm', 'pn', 'pr', 'ps', 'pt', 'pw', 'py', 'qa', 're', 'ro', 'rs', 'ru', 'rw',
        'sa', 'sb', 'sc', 'sd', 'se', 'sg', 'sh', 'si', 'sj', 'sk', 'sl', 'sm', 'sn', 'so', 'sr', 'ss',
        'st', 'sv', 'sx', 'sy', 'sz', 'tc', 'td', 'tf', 'tg', 'th', 'tj', 'tk', 'tl', 'tm', 'tn', 'to',
        'tr', 'tt', 'tv', 'tw', 'tz', 'ua', 'ug', 'um', 'us', 'uy', 'uz', 'va', 'vc', 've', 'vg', 'vi',
        'vn', 'vu', 'wf', 'ws', 'ye', 'yt', 'za', 'zm', 'zw',
    ];

    /**
     * The country list the public documentation gives for the sources endpoint
     * (read on 14 November 2026). It seeds the filterable allowlist.
     */
    public const DOCUMENTED_COUNTRIES = [
        'ae', 'ar', 'at', 'au', 'be', 'bg', 'br', 'ca', 'ch', 'cn', 'co', 'cu', 'cz', 'de', 'eg', 'fr',
        'gb', 'gr', 'hk', 'hu', 'id', 'ie', 'il', 'in', 'it', 'jp', 'kr', 'lt', 'lv', 'ma', 'mx', 'my',
        'ng', 'nl', 'no', 'nz', 'ph', 'pl', 'pt', 'ro', 'rs', 'ru', 'sa', 'se', 'sg', 'si', 'sk', 'th',
        'tr', 'tw', 'ua', 'us', 've', 'za',
    ];

    public const LANGUAGES = ['ar', 'de', 'en', 'es', 'fr', 'he', 'it', 'nl', 'no', 'pt', 'ru', 'sv', 'ud', 'zh'];

    public const CATEGORIES = ['business', 'entertainment', 'general', 'health', 'science', 'sports', 'technology'];

    public const ENDPOINTS = ['headlines', 'everything'];

    public const SEARCH_IN = ['title', 'description', 'content'];

    public const SORT_BY = ['publishedAt', 'relevancy', 'popularity'];

    /**
     * The allowlist of countries offered in the picker. Codes outside it are
     * still accepted when they are assigned ISO codes, with a warning.
     *
     * @return list<string>
     */
    public static function countries(): array
    {
        return self::stringList(apply_filters('newsapi_pull_countries', self::DOCUMENTED_COUNTRIES));
    }

    /**
     * @return list<string>
     */
    public static function languages(): array
    {
        return self::stringList(apply_filters('newsapi_pull_languages', self::LANGUAGES));
    }

    /**
     * @return list<string>
     */
    public static function categories(): array
    {
        return self::stringList(apply_filters('newsapi_pull_categories', self::CATEGORIES));
    }

    public static function isIsoCountry(string $code): bool
    {
        return in_array($code, self::ISO_COUNTRIES, true);
    }

    /**
     * Turns a filter's return into a clean list of lower-case strings.
     *
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = strtolower(trim($item));
            }
        }
        return array_values(array_unique($out));
    }
}
