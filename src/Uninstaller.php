<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * Removes everything the plugin stored, on every site. Posts and post meta
 * are the site's content and are never touched.
 */
final class Uninstaller
{
    public const OPTIONS = [
        Settings::OPTION,
        PullRepository::OPTION,
        Meter::OPTION,
        Lock::OPTION,
        RunLog::OPTION,
        State::OPTION,
        Catalogue::FALLBACK_OPTION,
        SeenStore::DB_VERSION_OPTION,
    ];

    public static function run(): void
    {
        Plugin::eachSite(true, static function (): void {
            self::site();
        });
        if (function_exists('is_multisite') && is_multisite()) {
            delete_site_option(Meter::OPTION);
        }
    }

    public static function site(): void
    {
        $clock = new SystemClock();
        $pulls = new PullRepository();
        (new Scheduler($pulls, $clock))->clearAll();
        (new SeenStore($clock))->drop();
        delete_transient(Catalogue::TRANSIENT);
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
    }
}
