<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Admin;

use IndianOpinion\NewsApiPull\DraftWriter;
use IndianOpinion\NewsApiPull\PullRepository;

/**
 * A column and a filter in the posts list for drafts made by the plugin.
 */
final class PostsColumn
{
    public const COLUMN = 'newsapi_pull';

    public const QUERY_VAR = 'newsapi_pull_filter';

    public function register(): void
    {
        add_filter('manage_posts_columns', [$this, 'columns']);
        add_action('manage_posts_custom_column', [$this, 'cell'], 10, 2);
        add_action('restrict_manage_posts', [$this, 'dropdown']);
        add_action('pre_get_posts', [$this, 'filterQuery']);
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $columns[self::COLUMN] = 'NewsAPI source';
        return $columns;
    }

    public function cell(string $column, int $postId): void
    {
        if ($column !== self::COLUMN) {
            return;
        }
        $pull = (string) get_post_meta($postId, DraftWriter::metaKey('pull'), true);
        if ($pull === '') {
            return;
        }
        $publisher = (string) get_post_meta($postId, DraftWriter::metaKey('publisher'), true);
        $url = (string) get_post_meta($postId, DraftWriter::metaKey('url'), true);
        $name = $publisher !== '' ? $publisher : 'source';
        if ($url !== '') {
            printf('<a href="%s" rel="nofollow noopener" target="_blank">%s</a>', esc_url($url, ['http', 'https']), esc_html($name));
        } else {
            echo esc_html($name);
        }
        printf('<br><small>%s</small>', esc_html($pull));
    }

    public function dropdown(): void
    {
        $pulls = (new PullRepository())->all();
        if ($pulls === []) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current = isset($_GET[self::QUERY_VAR]) && is_string($_GET[self::QUERY_VAR]) ? sanitize_key($_GET[self::QUERY_VAR]) : '';
        printf('<select name="%s"><option value="">All NewsAPI pulls</option>', esc_attr(self::QUERY_VAR));
        foreach (array_keys($pulls) as $slug) {
            printf('<option value="%1$s" %2$s>%1$s</option>', esc_attr($slug), selected($current, $slug, false));
        }
        echo '</select>';
    }

    public function filterQuery(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $slug = isset($_GET[self::QUERY_VAR]) && is_string($_GET[self::QUERY_VAR]) ? sanitize_key($_GET[self::QUERY_VAR]) : '';
        if ($slug === '') {
            return;
        }
        $query->set('meta_key', DraftWriter::metaKey('pull'));
        $query->set('meta_value', $slug);
    }
}
