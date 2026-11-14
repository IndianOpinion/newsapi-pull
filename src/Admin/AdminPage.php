<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Admin;

use IndianOpinion\NewsApiPull\Catalogue;
use IndianOpinion\NewsApiPull\Plugin;
use IndianOpinion\NewsApiPull\PullDefinition;
use IndianOpinion\NewsApiPull\Scheduler;
use IndianOpinion\NewsApiPull\Settings;
use IndianOpinion\NewsApiPull\Vocabulary;

/**
 * Tools, NewsAPI Pull: settings, quota meter, pulls, run log, pull editor and
 * source picker. Every action checks `manage_options` and a nonce.
 */
final class AdminPage
{
    public const SLUG = 'newsapi-pull';

    public const ACTIONS = [
        'save_settings', 'save_pull', 'run_now', 'toggle_pause', 'delete_pull',
        'export', 'import', 'add_example', 'refresh_catalogue',
    ];

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_notices', [$this, 'notices']);
        add_action('admin_init', [$this, 'adminInit']);
        foreach (self::ACTIONS as $action) {
            add_action('admin_post_newsapi_pull_' . $action, function () use ($action): void {
                $this->dispatch($action);
            });
        }
    }

    public function menu(): void
    {
        add_management_page('NewsAPI Pull', 'NewsAPI Pull', 'manage_options', self::SLUG, [$this, 'render']);
    }

    /** Self-heal: restore missing events for active pulls. */
    public function adminInit(): void
    {
        if (current_user_can('manage_options')) {
            $this->plugin->scheduler->selfHeal();
        }
    }

    /**
     * @param array<string, string> $args
     */
    public static function pageUrl(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('tools.php'));
    }

    public static function nonceAction(string $action): string
    {
        return 'newsapi_pull_' . $action;
    }

    /**
     * Handles one admin action. Refuses without the capability or a valid
     * nonce, and changes nothing in that case.
     *
     * @param array<string, mixed> $post Unslashed request values.
     * @return array{ok: bool, message: string, errors?: array<string, string>, download?: string, slug?: string}
     */
    public function handle(string $action, array $post): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return ['ok' => false, 'message' => 'Unknown action.'];
        }
        if (!current_user_can('manage_options')) {
            return ['ok' => false, 'message' => 'You are not allowed to do that.'];
        }
        $nonce = isset($post['_wpnonce']) && is_string($post['_wpnonce']) ? $post['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::nonceAction($action))) {
            return ['ok' => false, 'message' => 'The form expired. Reload the page and try again.'];
        }
        $slug = isset($post['slug']) && is_string($post['slug']) ? sanitize_key($post['slug']) : '';
        switch ($action) {
            case 'save_settings':
                $values = $this->plugin->settings->sanitise($post);
                $this->plugin->settings->save($values);
                return ['ok' => true, 'message' => 'Settings saved.'];
            case 'save_pull':
                return $this->savePull($post);
            case 'run_now':
                $summary = $this->plugin->runner->run($slug, true);
                $ok = $summary['outcome'] === 'ok';
                return ['ok' => $ok, 'message' => sprintf(
                    'Run of "%s": %s%s. %d requests, %d received, %d drafts created. %s',
                    $slug,
                    (string) $summary['outcome'],
                    $summary['reason'] !== '' ? ' (' . (string) $summary['reason'] . ')' : '',
                    (int) $summary['requests'],
                    (int) $summary['received'],
                    (int) $summary['created'],
                    (string) $summary['message']
                )];
            case 'toggle_pause':
                $pull = $this->plugin->pulls->get($slug);
                if ($pull === null) {
                    return ['ok' => false, 'message' => 'No such pull.'];
                }
                $pull = $pull->with(['paused' => !$pull->paused]);
                $this->plugin->pulls->save($pull, false);
                $this->plugin->scheduler->sync($pull);
                return ['ok' => true, 'message' => $pull->paused ? 'Pull paused.' : 'Pull resumed.'];
            case 'delete_pull':
                if ($this->plugin->pulls->get($slug) === null) {
                    return ['ok' => false, 'message' => 'No such pull.'];
                }
                $this->plugin->scheduler->unschedule($slug);
                $this->plugin->pulls->delete($slug);
                return ['ok' => true, 'message' => 'Pull deleted. Its drafts are untouched.'];
            case 'export':
                return ['ok' => true, 'message' => 'Exported.', 'download' => $this->export()];
            case 'import':
                $json = isset($post['json']) && is_string($post['json']) ? $post['json'] : '';
                return $this->import($json);
            case 'add_example':
                return $this->addExample();
            case 'refresh_catalogue':
                $fetch = $this->plugin->catalogueFetcher();
                if ($fetch === null) {
                    return ['ok' => false, 'message' => 'The catalogue cannot be fetched now (check the plan, environment, key and kill switch).'];
                }
                $copy = $this->plugin->catalogue->get($fetch, true);
                return $copy === null || $copy['stale']
                    ? ['ok' => false, 'message' => 'The catalogue fetch failed; see the log.']
                    : ['ok' => true, 'message' => sprintf('Catalogue loaded: %d sources.', count($copy['sources']))];
            default:
                return ['ok' => false, 'message' => 'Unknown action.'];
        }
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string, errors?: array<string, string>, slug?: string}
     */
    public function savePull(array $post): array
    {
        $original = isset($post['original_slug']) && is_string($post['original_slug']) ? sanitize_key($post['original_slug']) : '';
        $data = [];
        foreach (
            [
            'slug', 'endpoint', 'country', 'category', 'language', 'q', 'sort_by', 'post_type', 'status',
            'sources', 'domains', 'exclude_domains', 'tags',
            ] as $field
        ) {
            $data[$field] = isset($post[$field]) && is_string($post[$field]) ? sanitize_text_field($post[$field]) : '';
        }
        if (isset($post['sources']) && is_array($post['sources'])) {
            $data['sources'] = array_map(static fn ($s): string => sanitize_text_field((string) $s), $post['sources']);
        }
        $data['q'] = isset($post['q']) && is_string($post['q']) ? trim(wp_strip_all_tags($post['q'])) : '';
        $data['search_in'] = isset($post['search_in']) && is_array($post['search_in']) ? array_map('strval', $post['search_in']) : [];
        $data['categories'] = isset($post['categories']) && is_array($post['categories']) ? array_map('intval', $post['categories']) : [];
        foreach (['page_size', 'max_pages', 'interval', 'author', 'max_age_hours', 'daily_cap'] as $field) {
            $data[$field] = isset($post[$field]) && is_numeric($post[$field]) ? (int) $post[$field] : null;
        }
        $data['page_size'] ??= 50;
        $data['max_pages'] ??= 1;
        $data['interval'] ??= 60;
        if (empty($data['author'])) {
            $data['author'] = get_current_user_id();
        }
        $data['paused'] = !empty($post['paused']);
        $data['source_tag'] = !empty($post['source_tag']);
        $pull = PullDefinition::fromArray($data);
        $errors = $pull->validate($this->plugin->settings->plan());
        if ($original === '' && $pull->slug !== '' && $this->plugin->pulls->get($pull->slug) !== null) {
            $errors['slug'] = 'A pull with this slug already exists.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'message' => 'The pull was not saved. Fix the fields marked below.', 'errors' => $errors, 'slug' => $pull->slug];
        }
        if ($original !== '' && $original !== $pull->slug) {
            $this->plugin->scheduler->unschedule($original);
            $this->plugin->pulls->delete($original);
        }
        $this->plugin->pulls->save($pull);
        $this->plugin->scheduler->sync($pull);
        $warnings = $pull->warnings($this->plugin->catalogue->cachedIds());
        return ['ok' => true, 'message' => 'Pull saved.' . ($warnings !== [] ? ' Note: ' . implode(' ', $warnings) : ''), 'slug' => $pull->slug];
    }

    /** Pulls as JSON. Never the key. */
    public function export(): string
    {
        $out = [];
        foreach ($this->plugin->pulls->all() as $pull) {
            $out[] = $pull->toArray();
        }
        return (string) wp_json_encode(['newsapi_pull_export' => 1, 'pulls' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Imports pulls, validating each as on save. Key fields are ignored.
     *
     * @return array{ok: bool, message: string, errors?: array<string, string>}
     */
    public function import(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'The file is not JSON.'];
        }
        $rows = isset($data['pulls']) && is_array($data['pulls']) ? $data['pulls'] : $data;
        $imported = 0;
        $errors = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            unset($row['api_key'], $row['apiKey'], $row['key']);
            if (empty($row['author']) || !is_numeric($row['author'])) {
                $row['author'] = get_current_user_id();
            }
            $pull = PullDefinition::fromArray($row);
            $e = $pull->validate($this->plugin->settings->plan());
            if ($e !== []) {
                $errors[(string) ($pull->slug !== '' ? $pull->slug : $i)] = (string) reset($e);
                continue;
            }
            $pull = $pull->with(['paused' => true]);
            $this->plugin->pulls->save($pull);
            $this->plugin->scheduler->sync($pull);
            $imported++;
        }
        return [
            'ok' => $errors === [],
            'message' => sprintf('%d pulls imported, paused for review.%s', $imported, $errors !== [] ? sprintf(' %d refused.', count($errors)) : ''),
            'errors' => $errors,
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function addExample(): array
    {
        if ($this->plugin->pulls->get('example-headlines') !== null) {
            return ['ok' => false, 'message' => 'The example pull already exists.'];
        }
        $pull = PullDefinition::fromArray([
            'slug' => 'example-headlines',
            'endpoint' => 'headlines',
            'category' => 'general',
            'country' => 'us',
            'page_size' => 20,
            'interval' => 60,
            'paused' => true,
            'author' => get_current_user_id(),
        ]);
        $this->plugin->pulls->save($pull);
        return ['ok' => true, 'message' => 'Example pull added, paused. Review it and resume it when ready.'];
    }

    /**
     * admin-post entry point: unslash, handle, remember the message, redirect.
     */
    public function dispatch(string $action): void
    {
        $post = wp_unslash($_POST);
        $post = is_array($post) ? $post : [];
        $result = $this->handle($action, $post);
        if (isset($result['download']) && $result['ok']) {
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename=newsapi-pull-export.json');
            echo $result['download']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }
        set_transient('newsapi_pull_flash_' . get_current_user_id(), $result, 120);
        $args = [];
        if ($action === 'save_pull' && !$result['ok']) {
            $args = ['view' => 'edit', 'pull' => $result['slug'] ?? ''];
            set_transient('newsapi_pull_form_' . get_current_user_id(), $post, 600);
        }
        wp_safe_redirect(self::pageUrl($args));
        exit;
    }

    /**
     * Admin notices that link to the fix.
     */
    public function notices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        foreach ($this->noticeList() as [$type, $text]) {
            printf(
                '<div class="notice notice-%1$s"><p><strong>NewsAPI Pull:</strong> %2$s <a href="%3$s">Open the settings</a>.</p></div>',
                esc_attr($type),
                esc_html($text),
                esc_url(self::pageUrl())
            );
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function noticeList(): array
    {
        $p = $this->plugin;
        $out = [];
        $plan = $p->settings->plan();
        if ($plan === '') {
            $out[] = ['warning', 'Choose your plan (developer or paid). No pull runs until you do.'];
        } elseif (!$p->settings->environmentAllowed()) {
            $out[] = ['error', sprintf(
                'The developer (free) plan is for development and testing only. This site\'s environment type is "%s", so nothing is fetched. A paid plan is needed here.',
                function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production'
            )];
        }
        $keyError = $p->state->keyError();
        if ($keyError !== '') {
            $out[] = ['error', sprintf('The API rejected the key (%s). Every pull is paused until the key is changed.', $keyError)];
        }
        $meter = $p->meter->snapshot();
        if ($meter['exhausted']) {
            $out[] = ['warning', sprintf('The daily quota is used up (%s). Pulls resume after %s.', $meter['exhausted_source'] === 'api' ? 'the API said so' : 'local meter', $meter['reset'])];
        }
        if ($p->state->backoffActive()) {
            $out[] = ['warning', 'The API rate-limited this site; every pull waits for the back-off to end.'];
        }
        $copy = $p->catalogue->cached();
        if ($copy !== null && $copy['stale']) {
            $out[] = ['warning', sprintf('The source catalogue is stale (fetched %s UTC).', gmdate('Y-m-d H:i', $copy['fetched_at']))];
        }
        foreach ($p->pulls->all() as $slug => $pull) {
            $state = $p->pulls->state($slug);
            if ($state['disabled_reason'] !== '') {
                $out[] = ['error', sprintf('Pull "%s" is disabled: %s Edit and save it to re-enable it.', $slug, (string) $state['disabled_message'])];
            }
        }
        return $out;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('You are not allowed to see this page.'));
        }
        $view = isset($_GET['view']) && is_string($_GET['view']) ? sanitize_key($_GET['view']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<div class="wrap"><h1>NewsAPI Pull</h1>';
        $this->renderFlash();
        if ($view === 'edit') {
            $slug = isset($_GET['pull']) && is_string($_GET['pull']) ? sanitize_key($_GET['pull']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $this->renderEditor($slug);
        } else {
            $this->renderMain();
        }
        echo '</div>';
    }

    private function renderFlash(): void
    {
        $key = 'newsapi_pull_flash_' . get_current_user_id();
        $flash = get_transient($key);
        if (!is_array($flash)) {
            return;
        }
        delete_transient($key);
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', !empty($flash['ok']) ? 'success' : 'error', esc_html((string) ($flash['message'] ?? '')));
        if (!empty($flash['errors']) && is_array($flash['errors'])) {
            echo '<div class="notice notice-error"><ul>';
            foreach ($flash['errors'] as $field => $msg) {
                printf('<li><strong>%s</strong>: %s</li>', esc_html((string) $field), esc_html((string) $msg));
            }
            echo '</ul></div>';
        }
    }

    public function renderMain(): void
    {
        $p = $this->plugin;
        $s = $p->settings->all();
        $plan = $p->settings->plan();
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';

        echo '<p>Pulls headlines into drafts. Nothing is ever published by this plugin: every item is a lead for an editor, not an article to publish as is. The free (developer) plan is for development and testing only. You must hold the rights to republish anything you publish. This is information, not legal advice.</p>';

        echo '<h2>Plan and environment</h2>';
        printf(
            '<p>Plan: <strong>%s</strong>. Environment type: <strong>%s</strong>. Fetching is <strong>%s</strong>.</p>',
            esc_html($plan !== '' ? $plan : 'not chosen'),
            esc_html($env),
            $p->settings->environmentAllowed() && !$p->settings->killSwitch() ? 'allowed' : 'blocked'
        );

        $m = $p->meter->snapshot();
        echo '<h2>Quota meter</h2>';
        printf(
            '<p>Used today: <strong>%d</strong> of %s. Reserve for manual runs and the catalogue: %d. Remaining: %s. Resets at %s (UTC midnight). Scope: %s%s.</p>',
            $m['used'],
            esc_html($m['limit'] === null ? 'no local limit' : (string) $m['limit']),
            $m['reserve'],
            esc_html($m['remaining'] === null ? 'not limited locally' : (string) $m['remaining']),
            esc_html($m['reset']),
            esc_html($m['scope']),
            $m['source'] === 'header' ? esc_html(', corrected from the API header') : ''
        );
        if (function_exists('is_multisite') && is_multisite() && !$p->meter->isNetwork()) {
            echo '<p>Sites that share one API key share one quota that this meter cannot see across sites. Network-activate the plugin to keep one meter per key for the whole network.</p>';
        }

        $this->renderSettingsForm($s, $plan);
        $this->renderPulls();
        $this->renderTools();
        $this->renderLog();
    }

    /**
     * @param array<string, mixed> $s
     */
    private function renderSettingsForm(array $s, string $plan): void
    {
        $p = $this->plugin;
        echo '<h2>Settings</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="newsapi_pull_save_settings">';
        wp_nonce_field(self::nonceAction('save_settings'));
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">Plan</th><td>';
        if ($p->settings->planFromConstant()) {
            printf('<p>%s (set by NEWSAPI_PULL_PLAN)</p>', esc_html($plan));
        } else {
            foreach (Settings::PLANS as $value) {
                printf('<label><input type="radio" name="plan" value="%1$s" %2$s> %1$s</label><br>', esc_attr($value), checked($plan, $value, false));
            }
        }
        echo '<p class="description">developer is the free tier: it fetches only when the environment type is local or development. paid fetches anywhere; you are responsible for holding a paid plan, which the plugin cannot verify.</p></td></tr>';

        $hint = $p->settings->keyHint();
        echo '<tr><th scope="row"><label for="newsapi-pull-key">API key</label></th><td>';
        if ($p->settings->keyFromConstant()) {
            printf('<p>Set by NEWSAPI_PULL_API_KEY, ending in <code>%s</code>.</p>', esc_html($hint));
        } else {
            echo '<input type="password" id="newsapi-pull-key" name="api_key" value="" autocomplete="off" class="regular-text">';
            if ($hint !== '') {
                printf('<p class="description">A key ending in <code>%s</code> is stored. Leave blank to keep it.</p>', esc_html($hint));
            }
        }
        echo '</td></tr>';

        printf(
            '<tr><th scope="row"><label for="newsapi-pull-limit">Daily request limit</label></th><td><input type="number" min="1" id="newsapi-pull-limit" name="daily_limit" value="%s" class="small-text"><p class="description">Empty means 100 on developer and no local limit on paid.</p></td></tr>',
            esc_attr($s['daily_limit'] === null ? '' : (string) $s['daily_limit'])
        );
        printf(
            '<tr><th scope="row"><label for="newsapi-pull-reserve">Reserve</label></th><td><input type="number" min="0" id="newsapi-pull-reserve" name="reserve" value="%d" class="small-text"><p class="description">Requests held back for manual runs and the source catalogue. Scheduled runs stop when used plus reserve reaches the limit.</p></td></tr>',
            (int) $s['reserve']
        );
        printf(
            '<tr><th scope="row">Credit line</th><td><label><input type="checkbox" name="credit_line" value="1" %s %s> Add "News data from NewsAPI.org" with a link to every draft</label><p class="description">Can be turned off only on plan paid. Check your own terms first.</p></td></tr>',
            checked($p->settings->creditLine(), true, false),
            disabled($plan !== 'paid', true, false)
        );
        printf(
            '<tr><th scope="row">Content snippet</th><td><label><input type="checkbox" name="include_snippet" value="1" %s> Quote the API\'s truncated content field inside the editor\'s note</label></td></tr>',
            checked($s['include_snippet'], true, false)
        );
        printf(
            '<tr><th scope="row">Kill switch</th><td><label><input type="checkbox" name="kill_switch" value="1" %s> Stop every run</label>%s</td></tr>',
            checked($s['kill_switch'], true, false),
            $p->settings->killSwitch() && !$s['kill_switch'] ? '<p class="description">NEWSAPI_PULL_DISABLED is set; runs are stopped.</p>' : ''
        );
        printf(
            '<tr><th scope="row">Debug log</th><td><label><input type="checkbox" name="debug_log" value="1" %s> Write one line per run and per rejection to the PHP error log</label></td></tr>',
            checked($s['debug_log'], true, false)
        );
        printf(
            '<tr><th scope="row"><label for="newsapi-pull-ttl">Catalogue cache (hours)</label></th><td><input type="number" min="6" id="newsapi-pull-ttl" name="catalogue_ttl_hours" value="%d" class="small-text"></td></tr>',
            (int) $s['catalogue_ttl_hours']
        );
        printf(
            '<tr><th scope="row"><label for="newsapi-pull-retention">Seen-table retention (days)</label></th><td><input type="number" min="30" id="newsapi-pull-retention" name="retention_days" value="%d" class="small-text"></td></tr>',
            (int) $s['retention_days']
        );
        echo '</tbody></table>';
        submit_button('Save settings');
        echo '</form>';
    }

    private function renderPulls(): void
    {
        $p = $this->plugin;
        echo '<h2>Pulls</h2>';
        printf('<p><a class="button" href="%s">Add a pull</a></p>', esc_url(self::pageUrl(['view' => 'edit'])));
        $pulls = $p->pulls->all();
        if ($pulls === []) {
            echo '<p>No pulls yet. A fresh install runs nothing.</p>';
            $this->actionButton('add_example', '', 'Add the example pull (paused)');
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Pull</th><th>Endpoint</th><th>Interval</th><th>Last run</th><th>Outcome</th><th>Drafts</th><th>Rejections</th><th>Next run</th><th>State</th><th>Actions</th></tr></thead><tbody>';
        foreach ($pulls as $slug => $pull) {
            $state = $p->pulls->state($slug);
            $summary = is_array($state['last_summary']) ? $state['last_summary'] : [];
            $rejections = [];
            foreach ((array) ($summary['rejections'] ?? []) as $reason => $n) {
                $rejections[] = $reason . ': ' . (int) $n;
            }
            $next = wp_next_scheduled(Scheduler::HOOK, [$slug]);
            $flags = [];
            if ($pull->paused) {
                $flags[] = 'paused';
            }
            if ($state['disabled_reason'] !== '') {
                $flags[] = 'disabled: ' . (string) $state['disabled_message'];
            }
            if ((int) $state['failures'] >= 5) {
                $flags[] = sprintf('backing off after %d failures', (int) $state['failures']);
            }
            if ((int) $state['left_out'] > 0) {
                $flags[] = sprintf('%d language sources left out', (int) $state['left_out']);
            }
            echo '<tr>';
            printf(
                '<td><strong>%s</strong></td><td>%s</td><td>%d min</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>',
                esc_html($slug),
                esc_html($pull->endpoint),
                $pull->interval,
                esc_html((int) $state['last_run'] > 0 ? gmdate('Y-m-d H:i', (int) $state['last_run']) . ' UTC' : 'never'),
                esc_html(trim((string) $state['last_outcome'] . ' ' . (string) $state['last_reason'])),
                (int) ($summary['created'] ?? 0),
                esc_html(implode(', ', $rejections)),
                esc_html(is_int($next) ? gmdate('Y-m-d H:i', $next) . ' UTC' : 'none'),
                esc_html(implode('; ', $flags))
            );
            printf('<a href="%s">Edit</a> ', esc_url(self::pageUrl(['view' => 'edit', 'pull' => $slug])));
            $this->actionButton('run_now', $slug, 'Run now');
            $this->actionButton('toggle_pause', $slug, $pull->paused ? 'Resume' : 'Pause');
            $this->actionButton('delete_pull', $slug, 'Delete');
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function actionButton(string $action, string $slug, string $label): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        printf('<input type="hidden" name="action" value="%s">', esc_attr('newsapi_pull_' . $action));
        printf('<input type="hidden" name="slug" value="%s">', esc_attr($slug));
        wp_nonce_field(self::nonceAction($action));
        printf('<button type="submit" class="button button-small">%s</button></form> ', esc_html($label));
    }

    private function renderTools(): void
    {
        echo '<h2>Export and import</h2>';
        $this->actionButton('export', '', 'Export pulls as JSON');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="newsapi_pull_import">';
        wp_nonce_field(self::nonceAction('import'));
        echo '<p><label for="newsapi-pull-import">Paste exported JSON. Key fields are ignored; imported pulls start paused.</label></p>';
        echo '<textarea id="newsapi-pull-import" name="json" rows="4" class="large-text code"></textarea>';
        submit_button('Import', 'secondary');
        echo '</form>';
    }

    private function renderLog(): void
    {
        echo '<h2>Run log</h2>';
        $entries = array_reverse($this->plugin->log->all(null, 50));
        if ($entries === []) {
            echo '<p>No runs yet.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Finished (UTC)</th><th>Pull</th><th>Outcome</th><th>Requests</th><th>Received</th><th>Created</th><th>Rejections</th><th>Message</th></tr></thead><tbody>';
        foreach ($entries as $e) {
            $rej = [];
            foreach ((array) ($e['rejections'] ?? []) as $r => $n) {
                $rej[] = $r . ': ' . (int) $n;
            }
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td></tr>',
                esc_html((string) ($e['finished'] ?? '')),
                esc_html((string) ($e['pull'] ?? '')),
                esc_html(trim((string) ($e['outcome'] ?? '') . ' ' . (string) ($e['reason'] ?? ''))),
                (int) ($e['requests'] ?? 0),
                (int) ($e['received'] ?? 0),
                (int) ($e['created'] ?? 0),
                esc_html(implode(', ', $rej)),
                esc_html($this->plugin->logger->redact((string) ($e['message'] ?? '')))
            );
        }
        echo '</tbody></table>';
    }

    public function renderEditor(string $slug): void
    {
        $p = $this->plugin;
        $pull = $slug !== '' ? $p->pulls->get($slug) : null;
        $values = $pull !== null ? $pull->toArray() : PullDefinition::fromArray(['endpoint' => 'headlines', 'paused' => true])->toArray();
        $formKey = 'newsapi_pull_form_' . get_current_user_id();
        $saved = get_transient($formKey);
        if (is_array($saved)) {
            delete_transient($formKey);
            $values = array_merge($values, array_intersect_key($saved, $values));
        }
        $str = static function (mixed $v): string {
            return is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v;
        };
        printf('<h2>%s</h2>', esc_html($pull !== null ? 'Edit pull "' . $slug . '"' : 'Add a pull'));
        printf('<p><a href="%s">Back to the pull list</a></p>', esc_url(self::pageUrl()));
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="newsapi_pull_save_pull">';
        printf('<input type="hidden" name="original_slug" value="%s">', esc_attr($pull !== null ? $slug : ''));
        wp_nonce_field(self::nonceAction('save_pull'));
        echo '<table class="form-table" role="presentation"><tbody>';
        $text = static function (string $name, string $label, string $value, string $help = ''): void {
            printf(
                '<tr><th scope="row"><label for="np-%1$s">%2$s</label></th><td><input type="text" id="np-%1$s" name="%1$s" value="%3$s" class="regular-text">%4$s</td></tr>',
                esc_attr($name),
                esc_html($label),
                esc_attr($value),
                $help !== '' ? '<p class="description">' . esc_html($help) . '</p>' : ''
            );
        };
        $select = static function (string $name, string $label, string $value, array $options, string $help = ''): void {
            printf('<tr><th scope="row"><label for="np-%1$s">%2$s</label></th><td><select id="np-%1$s" name="%1$s">', esc_attr($name), esc_html($label));
            foreach ($options as $opt) {
                printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr((string) $opt), selected($value, (string) $opt, false), esc_html($opt === '' ? '(none)' : (string) $opt));
            }
            echo '</select>' . ($help !== '' ? '<p class="description">' . esc_html($help) . '</p>' : '') . '</td></tr>';
        };
        $text('slug', 'Slug', $str($values['slug']), 'Lower-case letters, digits and hyphens.');
        $select('endpoint', 'Endpoint', $str($values['endpoint']), Vocabulary::ENDPOINTS, 'headlines: top headlines. everything: full article search.');
        $text('country', 'Country (headlines)', $str($values['country']), 'Two-letter ISO code. Documented: ' . implode(' ', Vocabulary::countries()));
        $select('category', 'Category (headlines)', $str($values['category']), array_merge([''], Vocabulary::categories()));
        $text('sources', 'Sources', $str($values['sources']), 'Source ids, comma-separated, at most 20. Not combined with country or category on headlines.');
        $select('language', 'Language', $str($values['language']), array_merge([''], Vocabulary::languages()), 'On headlines the language is applied by choosing sources in that language from the catalogue.');
        $text('q', 'Keywords', $str($values['q']), 'At most 500 characters. Required on everything unless sources or domains are set.');
        echo '<tr><th scope="row">Search in (everything)</th><td>';
        foreach (Vocabulary::SEARCH_IN as $field) {
            printf('<label><input type="checkbox" name="search_in[]" value="%1$s" %2$s> %1$s</label> ', esc_attr($field), checked(in_array($field, (array) $values['search_in'], true), true, false));
        }
        echo '</td></tr>';
        $text('domains', 'Domains (everything)', $str($values['domains']));
        $text('exclude_domains', 'Exclude domains (everything)', $str($values['exclude_domains']));
        $select('sort_by', 'Sort (everything)', $str($values['sort_by']), Vocabulary::SORT_BY);
        $text('page_size', 'Page size', $str($values['page_size']), '1 to 100.');
        $text('max_pages', 'Max pages per run', $str($values['max_pages']), '1 to 5. The developer plan fetches page 1 only.');
        $text('interval', 'Interval (minutes)', $str($values['interval']), 'At least 15.');
        $text('max_age_hours', 'Max article age (hours)', $str($values['max_age_hours'] ?? ''), 'Empty: 72, or 96 on developer.');
        $text('daily_cap', 'Daily request cap for this pull', $str($values['daily_cap'] ?? ''), 'Empty: no per-pull cap.');
        $select('post_type', 'Post type', $str($values['post_type']), array_values(array_filter(
            array_map('strval', get_post_types(['show_ui' => true])),
            static fn (string $t): bool => post_type_supports($t, 'title') && post_type_supports($t, 'editor')
        )));
        $select('status', 'Status', $str($values['status']), PullDefinition::ALLOWED_STATUSES, 'Drafts only, or pending review. Nothing is published.');
        $text('author', 'Author user id', $str($values['author']), 'Empty: you.');
        echo '<tr><th scope="row">Categories</th><td>';
        foreach (get_categories(['hide_empty' => false]) as $term) {
            printf('<label><input type="checkbox" name="categories[]" value="%1$d" %2$s> %3$s</label> ', (int) $term->term_id, checked(in_array((int) $term->term_id, (array) $values['categories'], true), true, false), esc_html($term->name));
        }
        echo '</td></tr>';
        $text('tags', 'Tags', $str($values['tags']), 'Comma-separated.');
        printf(
            '<tr><th scope="row">Options</th><td><label><input type="checkbox" name="source_tag" value="1" %s> Tag each draft with its source name</label><br><label><input type="checkbox" name="paused" value="1" %s> Paused</label></td></tr>',
            checked(!empty($values['source_tag']), true, false),
            checked(!empty($values['paused']), true, false)
        );
        echo '</tbody></table>';
        submit_button('Save pull');
        echo '</form>';
        $this->renderPicker((array) $values['sources']);
    }

    /**
     * The source picker: lists the cached catalogue with filters.
     *
     * @param array<mixed> $selected
     */
    private function renderPicker(array $selected): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $country = isset($_GET['f_country']) && is_string($_GET['f_country']) ? sanitize_key($_GET['f_country']) : '';
        $language = isset($_GET['f_language']) && is_string($_GET['f_language']) ? sanitize_key($_GET['f_language']) : '';
        $category = isset($_GET['f_category']) && is_string($_GET['f_category']) ? sanitize_key($_GET['f_category']) : '';
        // phpcs:enable
        echo '<h2>Source picker</h2>';
        $copy = $this->plugin->catalogue->cached();
        $this->actionButton('refresh_catalogue', '', $copy === null ? 'Load the source catalogue (uses one request)' : 'Refresh the catalogue (uses one request)');
        if ($copy === null) {
            echo '<p>No catalogue cached yet.</p>';
            return;
        }
        printf(
            '<p>%d sources, fetched %s UTC%s. Copy the ids you want into the Sources field (at most %d).</p>',
            count($copy['sources']),
            esc_html(gmdate('Y-m-d H:i', $copy['fetched_at'])),
            $copy['stale'] ? ', stale' : '',
            PullDefinition::MAX_SOURCES
        );
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><input type="hidden" name="view" value="edit">';
        foreach (['f_country' => $country, 'f_language' => $language, 'f_category' => $category] as $name => $value) {
            printf('<input type="text" name="%s" value="%s" placeholder="%s" class="small-text"> ', esc_attr($name), esc_attr($value), esc_attr(substr($name, 2)));
        }
        submit_button('Filter', 'secondary', '', false);
        echo '</form><table class="widefat striped"><thead><tr><th>Id</th><th>Name</th><th>Country</th><th>Language</th><th>Category</th></tr></thead><tbody>';
        foreach (Catalogue::filter($copy['sources'], $language, $country, $category) as $s) {
            printf(
                '<tr><td><code>%s</code>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html($s['id']),
                in_array($s['id'], $selected, true) ? ' (selected)' : '',
                esc_html($s['name']),
                esc_html($s['country']),
                esc_html($s['language']),
                esc_html($s['category'])
            );
        }
        echo '</tbody></table>';
    }
}
