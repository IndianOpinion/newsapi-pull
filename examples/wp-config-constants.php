<?php

/**
 * Optional constants for wp-config.php. Copy the lines you need above the
 * "That's all, stop editing!" line. Each one overrides its setting.
 */

declare(strict_types=1);

// The API key. Keeps the key out of the database; the settings page shows only its last four characters.
define('NEWSAPI_PULL_API_KEY', 'your-key-here');

// The plan: 'developer' (free tier, local and development environments only) or 'paid'.
define('NEWSAPI_PULL_PLAN', 'developer');

// The kill switch. True stops every run before any request.
define('NEWSAPI_PULL_DISABLED', false);

// WordPress's own environment type decides whether the developer plan may fetch.
define('WP_ENVIRONMENT_TYPE', 'development');
