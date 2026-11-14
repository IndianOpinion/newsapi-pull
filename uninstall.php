<?php

/**
 * Uninstall: removes the plugin's options, pulls, meter, log, lock, catalogue
 * and de-duplication table on every site. It never deletes, trashes or
 * modifies drafts or posts the plugin created, nor their meta.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

\IndianOpinion\NewsApiPull\Uninstaller::run();
