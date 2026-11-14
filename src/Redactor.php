<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * Replaces the API key with a marker in any text that may be shown or logged.
 */
final class Redactor
{
    public const MARK = '[redacted]';

    public static function redact(string $text, string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) < 4) {
            return $text;
        }
        $forms = array_unique([$key, rawurlencode($key), urlencode($key)]);
        return str_replace($forms, self::MARK, $text);
    }
}
