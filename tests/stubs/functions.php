<?php

declare(strict_types=1);

// phpcs:ignoreFile

/*
 * Minimal WordPress function stubs backed by WpStub. Only what the plugin uses.
 */

const ARRAY_A = 'ARRAY_A';
const DAY_IN_SECONDS = 86400;

// Hooks ------------------------------------------------------------------

function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    WpStub::$filters[$hook][$priority][] = [$cb, $args];
    return true;
}

function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $cb, $priority, $args);
}

function remove_filter(string $hook, callable $cb, int $priority = 10): bool
{
    foreach (WpStub::$filters[$hook][$priority] ?? [] as $i => [$fn]) {
        if ($fn === $cb) {
            unset(WpStub::$filters[$hook][$priority][$i]);
            return true;
        }
    }
    return false;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    $byPriority = WpStub::$filters[$hook] ?? [];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as [$cb, $n]) {
            $value = $cb(...array_slice(array_merge([$value], $args), 0, max(1, $n)));
        }
    }
    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    WpStub::$fired[$hook][] = $args;
    $byPriority = WpStub::$filters[$hook] ?? [];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as [$cb, $n]) {
            $cb(...array_slice($args, 0, $n));
        }
    }
}

function did_action(string $hook): int
{
    return count(WpStub::$fired[$hook] ?? []);
}

// Options and transients -------------------------------------------------

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WpStub::$options[WpStub::$blog] ?? []) ? WpStub::$options[WpStub::$blog][$name] : $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    if (in_array($name, WpStub::$failWrites, true)) {
        return false;
    }
    $exists = array_key_exists($name, WpStub::$options[WpStub::$blog] ?? []);
    if ($exists && WpStub::$options[WpStub::$blog][$name] === $value) {
        return false;
    }
    WpStub::$options[WpStub::$blog][$name] = $value;
    if (!$exists || $autoload !== null) {
        WpStub::$autoload[WpStub::$blog][$name] = $autoload === null ? true : (bool) $autoload;
    }
    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = true): bool
{
    if (array_key_exists($name, WpStub::$options[WpStub::$blog] ?? []) || in_array($name, WpStub::$failWrites, true)) {
        return false;
    }
    WpStub::$options[WpStub::$blog][$name] = $value;
    WpStub::$autoload[WpStub::$blog][$name] = (bool) $autoload;
    return true;
}

function delete_option(string $name): bool
{
    $had = array_key_exists($name, WpStub::$options[WpStub::$blog] ?? []);
    unset(WpStub::$options[WpStub::$blog][$name]);
    return $had;
}

function get_site_option(string $name, mixed $default = false): mixed
{
    if (!WpStub::$multisite) {
        return get_option($name, $default);
    }
    return array_key_exists($name, WpStub::$siteOptions) ? WpStub::$siteOptions[$name] : $default;
}

function update_site_option(string $name, mixed $value): bool
{
    if (!WpStub::$multisite) {
        return update_option($name, $value);
    }
    if (in_array($name, WpStub::$failWrites, true)) {
        return false;
    }
    WpStub::$siteOptions[$name] = $value;
    return true;
}

function delete_site_option(string $name): bool
{
    if (!WpStub::$multisite) {
        return delete_option($name);
    }
    unset(WpStub::$siteOptions[$name]);
    return true;
}

function get_transient(string $name): mixed
{
    return WpStub::$transients[WpStub::$blog][$name] ?? false;
}

function set_transient(string $name, mixed $value, int $ttl = 0): bool
{
    WpStub::$transients[WpStub::$blog][$name] = $value;
    return true;
}

function delete_transient(string $name): bool
{
    unset(WpStub::$transients[WpStub::$blog][$name]);
    return true;
}

// Environment and sites --------------------------------------------------

function wp_get_environment_type(): string
{
    return WpStub::$env;
}

function is_multisite(): bool
{
    return WpStub::$multisite;
}

function get_current_blog_id(): int
{
    return WpStub::$blog;
}

function switch_to_blog(int $id): bool
{
    WpStub::$blogStack[] = WpStub::$blog;
    WpStub::$blog = $id;
    $GLOBALS['wpdb']->prefix = $id === 1 ? 'wp_' : 'wp_' . $id . '_';
    return true;
}

function restore_current_blog(): bool
{
    $id = array_pop(WpStub::$blogStack) ?? 1;
    WpStub::$blog = $id;
    $GLOBALS['wpdb']->prefix = $id === 1 ? 'wp_' : 'wp_' . $id . '_';
    return true;
}

/**
 * @return list<int>
 */
function get_sites(array $args = []): array
{
    return WpStub::$sites;
}

function is_admin(): bool
{
    return WpStub::$admin;
}

function home_url(string $path = ''): string
{
    return rtrim(WpStub::$home, '/') . '/' . ltrim($path, '/');
}

function site_url(string $path = ''): string
{
    return home_url($path);
}

function admin_url(string $path = ''): string
{
    return home_url('wp-admin/' . ltrim($path, '/'));
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}

function add_query_arg(array $args, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone(WpStub::$tz);
}

function wp_date(string $format, ?int $ts = null): string
{
    return (new DateTimeImmutable('@' . ($ts ?? time())))->setTimezone(wp_timezone())->format($format);
}

// Users, capabilities, nonces --------------------------------------------

function current_user_can(string $cap): bool
{
    return in_array($cap, WpStub::$caps, true);
}

function user_can(int $user, string $cap): bool
{
    return in_array($cap, WpStub::$userCaps[$user] ?? [], true);
}

function get_current_user_id(): int
{
    return WpStub::$userId;
}

function wp_create_nonce(string $action): string
{
    return 'nonce-' . $action;
}

function wp_verify_nonce(string $nonce, string $action): int|false
{
    return $nonce === 'nonce-' . $action ? 1 : false;
}

function wp_nonce_field(string $action): void
{
    echo '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce($action) . '">';
}

// HTTP ---------------------------------------------------------------------

function wp_remote_request(string $url, array $args = []): array|WP_Error
{
    WpStub::$requests[] = ['url' => $url, 'args' => $args];
    $next = array_shift(WpStub::$responses);
    if ($next === null) {
        return WpStub::response(['status' => 'ok', 'totalResults' => 0, 'articles' => []]);
    }
    return is_callable($next) ? $next($url, $args) : $next;
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code(array|WP_Error $r): int|string
{
    return is_array($r) ? (int) ($r['response']['code'] ?? 0) : '';
}

function wp_remote_retrieve_body(array|WP_Error $r): string
{
    return is_array($r) ? (string) ($r['body'] ?? '') : '';
}

function wp_remote_retrieve_header(array|WP_Error $r, string $name): array|string
{
    return is_array($r) ? ($r['headers'][strtolower($name)] ?? '') : '';
}

// Posts ------------------------------------------------------------------

function wp_insert_post(array $postarr, bool $wp_error = false): int|WP_Error
{
    if (WpStub::$failInserts > 0) {
        WpStub::$failInserts--;
        return new WP_Error('db_insert_error', 'Could not insert post into the database.');
    }
    $data = apply_filters('wp_insert_post_data', $postarr, $postarr);
    $id = WpStub::$nextPostId++;
    if (in_array($data['post_status'] ?? 'draft', ['draft', 'pending'], true) && ($data['post_date_gmt'] ?? '') === '') {
        $data['post_date_gmt'] = '0000-00-00 00:00:00';
    }
    WpStub::$posts[$id] = array_merge(['ID' => $id], $data);
    do_action('wp_insert_post', $id, WpStub::$posts[$id], false);
    return $id;
}

function wp_update_post(array $postarr): int
{
    $id = (int) $postarr['ID'];
    WpStub::$posts[$id] = array_merge(WpStub::$posts[$id] ?? ['ID' => $id], $postarr);
    return $id;
}

function get_post_status(int $id): string|false
{
    return WpStub::$posts[$id]['post_status'] ?? false;
}

function wp_trash_post(int $id): bool
{
    if (!isset(WpStub::$posts[$id])) {
        return false;
    }
    WpStub::$posts[$id]['post_status'] = 'trash';
    return true;
}

function update_post_meta(int $id, string $key, mixed $value): bool
{
    WpStub::$meta[$id][$key] = $value;
    return true;
}

function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    return WpStub::$meta[$id][$key] ?? '';
}

function set_post_thumbnail(int $post, int $thumb): bool
{
    WpStub::$meta[$post]['_thumbnail_id'] = $thumb;
    return true;
}

function media_sideload_image(string $file, int $post, ?string $desc = null, string $return = 'html'): int|string|WP_Error
{
    if (WpStub::$sideload !== null) {
        return (WpStub::$sideload)($file, $post, (string) $desc, $return);
    }
    return new WP_Error('http_404', 'Not found');
}

function post_type_exists(string $type): bool
{
    return in_array($type, ['post', 'page', 'attachment'], true);
}

function post_type_supports(string $type, string $feature): bool
{
    return in_array($type, ['post', 'page'], true);
}

/**
 * @return array<string, string>
 */
function get_post_types(array $args = []): array
{
    return ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment'];
}

/**
 * @return list<object>
 */
function get_categories(array $args = []): array
{
    return [(object) ['term_id' => 1, 'name' => 'Uncategorised']];
}

// Cron -------------------------------------------------------------------

function wp_schedule_event(int $ts, string $recurrence, string $hook, array $args = []): bool
{
    WpStub::$cron[$hook . '|' . json_encode($args)] = [$ts, $recurrence, $hook, $args];
    return true;
}

function wp_next_scheduled(string $hook, array $args = []): int|false
{
    return isset(WpStub::$cron[$hook . '|' . json_encode($args)]) ? WpStub::$cron[$hook . '|' . json_encode($args)][0] : false;
}

function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    $key = $hook . '|' . json_encode($args);
    $had = isset(WpStub::$cron[$key]) ? 1 : 0;
    unset(WpStub::$cron[$key]);
    return $had;
}

function wp_unschedule_hook(string $hook): int
{
    $n = 0;
    foreach (WpStub::$cron as $key => $event) {
        if ($event[2] === $hook) {
            unset(WpStub::$cron[$key]);
            $n++;
        }
    }
    return $n;
}

// Database ---------------------------------------------------------------

function dbDelta(string $sql): array
{
    if (preg_match('/CREATE TABLE (\S+)/', $sql, $m)) {
        $GLOBALS['wpdb']->create($m[1]);
    }
    return [];
}

// Formatting and escaping ------------------------------------------------

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url_raw(string $url, ?array $protocols = null): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $protocols ??= ['http', 'https', 'mailto', 'ftp'];
    if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $m) && !in_array(strtolower($m[1]), $protocols, true)) {
        return '';
    }
    return str_replace([' ', '"', "'", '<', '>'], ['%20', '%22', '%27', '%3C', '%3E'], $url);
}

function esc_url(string $url, ?array $protocols = null): string
{
    return str_replace('&', '&#038;', esc_url_raw($url, $protocols));
}

function wp_kses_post(string $html): string
{
    $html = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
    $html = (string) preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|\S+)#i', '', $html);
    return (string) preg_replace('#href\s*=\s*"\s*javascript:[^"]*"#i', 'href=""', $html);
}

function wp_strip_all_tags(string $text): string
{
    return trim(strip_tags($text));
}

function sanitize_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function sanitize_text_field(string $text): string
{
    return trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
}

function sanitize_title(string $title): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
}

function wp_json_encode(mixed $data, int $flags = 0): string|false
{
    return json_encode($data, $flags);
}

function wp_unslash(mixed $value): mixed
{
    return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value);
}

function checked(mixed $a, mixed $b = true, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' checked=\'checked\'' : '';
}

function selected(mixed $a, mixed $b = true, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' selected=\'selected\'' : '';
}

function disabled(mixed $a, mixed $b = true, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' disabled=\'disabled\'' : '';
}

function submit_button(string $text = 'Save', string $type = 'primary', string $name = 'submit', bool $wrap = true): void
{
    echo '<button type="submit" class="button button-' . esc_attr($type) . '">' . esc_html($text) . '</button>';
}

function add_management_page(string $title, string $menu, string $cap, string $slug, callable $cb): string
{
    return 'tools_page_' . $slug;
}

function wp_die(string $message = ''): never
{
    throw new WpDieException($message);
}
