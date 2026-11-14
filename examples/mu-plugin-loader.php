<?php

/**
 * Must-use loader for newsapi-pull.
 *
 * Copy this file to wp-content/mu-plugins/newsapi-pull-loader.php and put
 * the plugin folder at wp-content/mu-plugins/newsapi-pull/. Must-use plugins
 * have no activation hook, so the de-duplication table is created on the
 * first run and the daily event on the first admin page load.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/newsapi-pull/newsapi-pull.php';
