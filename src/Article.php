<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * Normalises one raw entry of the `articles` array. Never throws: a field of
 * the wrong type becomes empty, and the title and URL carry validity flags.
 */
final class Article
{
    /**
     * @return array{source_id: string, source_name: string, author: string, title: string, title_valid: bool, description: string, url: string, url_valid: bool, image: string, published_at: string, content: string}
     */
    public static function normalise(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $source = isset($raw['source']) && is_array($raw['source']) ? $raw['source'] : [];
        return [
            'source_id' => self::s($source, 'id'),
            'source_name' => self::s($source, 'name'),
            'author' => self::s($raw, 'author'),
            'title' => self::s($raw, 'title'),
            'title_valid' => isset($raw['title']) && is_string($raw['title']) && trim($raw['title']) !== '',
            'description' => self::s($raw, 'description'),
            'url' => self::s($raw, 'url'),
            'url_valid' => isset($raw['url']) && is_string($raw['url']) && trim($raw['url']) !== '',
            'image' => self::s($raw, 'urlToImage'),
            'published_at' => self::s($raw, 'publishedAt'),
            'content' => self::s($raw, 'content'),
        ];
    }

    /**
     * Cleans a title: entities decoded once, tags and control characters
     * removed, whitespace collapsed, and a trailing " - Source" or
     * " | Source" suffix removed when it names the article's source.
     */
    public static function cleanTitle(string $title, string $sourceName): string
    {
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = strip_tags($title);
        $title = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $title);
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $sourceName = trim($sourceName);
        if ($sourceName !== '') {
            foreach ([' - ', ' | ', " \u{2013} ", " \u{2014} "] as $sep) {
                $suffix = $sep . $sourceName;
                if (mb_strlen($title) > mb_strlen($suffix) && mb_strtolower(mb_substr($title, -mb_strlen($suffix))) === mb_strtolower($suffix)) {
                    $title = trim(mb_substr($title, 0, -mb_strlen($suffix)));
                    break;
                }
            }
        }
        return $title;
    }

    /**
     * Plain text from an API field: entities decoded once, tags stripped,
     * whitespace collapsed.
     */
    public static function plainText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = (string) preg_replace('/[\p{Cc}]+/u', ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Trims text to at most $max characters on a word boundary.
     */
    public static function excerpt(string $text, int $max): string
    {
        $text = self::plainText($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max + 1);
        $space = mb_strrpos($cut, ' ');
        $cut = $space !== false && $space > 0 ? mb_substr($cut, 0, $space) : mb_substr($text, 0, $max);
        return rtrim($cut, " ,;:.-");
    }

    /**
     * @param array<mixed> $a
     */
    private static function s(array $a, string $k): string
    {
        return isset($a[$k]) && is_string($a[$k]) ? $a[$k] : '';
    }
}
