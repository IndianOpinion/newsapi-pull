<?php

declare(strict_types=1);

// phpcs:ignoreFile

namespace {
    final class WpCliError extends \RuntimeException
    {
    }

    /**
     * Records WP-CLI output for assertions.
     */
    final class WP_CLI
    {
        /** @var list<string> */
        public static array $out = [];
        /** @var list<array<mixed>> */
        public static array $items = [];
        public static int $confirms = 0;

        public static function reset(): void
        {
            self::$out = [];
            self::$items = [];
            self::$confirms = 0;
        }

        public static function line(string $m = ''): void
        {
            self::$out[] = $m;
        }

        public static function success(string $m): void
        {
            self::$out[] = 'Success: ' . $m;
        }

        public static function warning(string $m): void
        {
            self::$out[] = 'Warning: ' . $m;
        }

        public static function error(string $m): never
        {
            self::$out[] = 'Error: ' . $m;
            throw new WpCliError($m);
        }

        /**
         * @param array<string, mixed> $assoc
         */
        public static function confirm(string $question, array $assoc = []): void
        {
            if (empty($assoc['yes'])) {
                self::$confirms++;
            }
        }

        public static function add_command(string $name, object $command): bool
        {
            return true;
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param array<mixed> $items
     * @param list<string> $fields
     */
    function format_items(string $format, array $items, array $fields): void
    {
        \WP_CLI::$items[] = $items;
        foreach ($items as $item) {
            \WP_CLI::$out[] = implode("\t", array_map('strval', array_values((array) $item)));
        }
    }
}
