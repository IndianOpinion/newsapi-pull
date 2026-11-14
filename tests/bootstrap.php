<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/newsapi-pull-wp/');
}

require_once __DIR__ . '/stubs/WpStub.php';
require_once __DIR__ . '/stubs/functions.php';
require_once __DIR__ . '/stubs/wp-cli.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/functions.php';
