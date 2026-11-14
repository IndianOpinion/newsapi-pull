<?php

/**
 * Plugin Name:       newsapi-pull
 * Plugin URI:        https://github.com/IndianOpinion/newsapi-pull
 * Description:       Pulls headlines from NewsAPI.org into drafts, with filters, URL-hash de-duplication, source attribution and a daily quota meter. Never publishes.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       newsapi-pull
 * Network:           false
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('NEWSAPI_PULL_FILE')) {
    define('NEWSAPI_PULL_FILE', __FILE__);
}

require_once __DIR__ . '/bootstrap.php';

register_activation_hook(__FILE__, [\IndianOpinion\NewsApiPull\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\IndianOpinion\NewsApiPull\Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    \IndianOpinion\NewsApiPull\Plugin::instance()->register();
});
