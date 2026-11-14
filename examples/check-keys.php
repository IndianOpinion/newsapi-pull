<?php

/**
 * Prints the canonical and plain de-duplication keys for each URL given.
 * Runs without WordPress:
 *
 *     php examples/check-keys.php "https://m.example.com/news/story/amp/?utm_source=x"
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use IndianOpinion\NewsApiPull\UrlKey;

$urls = array_slice($argv, 1);
if ($urls === []) {
    fwrite(STDERR, "Usage: php examples/check-keys.php <url> [<url> ...]\n");
    exit(1);
}

$keys = new UrlKey();
foreach ($urls as $url) {
    $canonical = $keys->canonical($url);
    $plain = UrlKey::plain($url);
    echo $url, PHP_EOL;
    if ($canonical === null || $plain === null) {
        echo "  no key: not an absolute http(s) URL with a host", PHP_EOL;
        continue;
    }
    printf("  canonical  %s  %s%s", $canonical, bin2hex(UrlKey::hash($canonical)), PHP_EOL);
    printf("  plain      %s  %s%s", $plain, bin2hex(UrlKey::hash($plain)), PHP_EOL);
}
