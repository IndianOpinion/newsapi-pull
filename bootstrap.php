<?php

/**
 * Loads the plugin classes without Composer. Safe to include more than once.
 */

declare(strict_types=1);

if (!defined('NEWSAPI_PULL_DIR')) {
    define('NEWSAPI_PULL_DIR', __DIR__);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'IndianOpinion\\NewsApiPull\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = __DIR__ . '/src/' . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });

    require_once __DIR__ . '/src/functions.php';
}
