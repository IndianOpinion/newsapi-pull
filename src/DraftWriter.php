<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

use IndianOpinion\NewsApiPull\Exception\InsertException;

/**
 * Builds and inserts one draft (PRD 4.8). Nothing it writes is published.
 */
final class DraftWriter
{
    public const META_PREFIX = '_newsapi_pull_';

    public const META_KEYS = [
        'url', 'publisher', 'source_id', 'author', 'published', 'image', 'pull', 'fetched', 'key', 'flags',
    ];

    public const CREDIT_URL = 'https://newsapi.org';

    public const CREDIT_TEXT = 'News data from NewsAPI.org';

    public function __construct(
        private readonly Settings $settings,
        private readonly Logger $logger
    ) {
    }

    public static function metaKey(string $name): string
    {
        return self::META_PREFIX . $name;
    }

    /**
     * Inserts the draft and writes its meta. Returns the post id.
     *
     * @param array<string, mixed> $article Normalised article plus `published_ts` (int) and `flags` (list<string>).
     * @throws InsertException
     */
    public function write(array $article, PullDefinition $pull, string $keyHashHex, int $fetchedAt): int
    {
        $status = $pull->status();
        $args = [
            'post_type' => $pull->postType,
            'post_status' => $status,
            'post_title' => Article::cleanTitle((string) $article['title'], (string) $article['source_name']),
            'post_content' => $this->content($article, $pull),
            'post_excerpt' => $this->excerpt((string) $article['description']),
            'post_author' => $pull->author,
            'post_date' => $this->postDate((int) $article['published_ts']),
            'post_date_gmt' => '0000-00-00 00:00:00',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ];
        if ($pull->categories !== []) {
            $args['post_category'] = $pull->categories;
        }
        $tags = $pull->tags;
        if ($pull->sourceTag && $article['source_name'] !== '') {
            $tags[] = Article::plainText((string) $article['source_name']);
        }
        if ($tags !== []) {
            $args['tags_input'] = array_values(array_unique($tags));
        }
        $filtered = apply_filters('newsapi_pull_draft_args', $args, $article, $pull);
        if (is_array($filtered)) {
            $args = $filtered;
        }
        // The status rule is applied after every filter (case 84).
        $args['post_status'] = $status;
        unset($args['ID'], $args['import_id']);

        $enforcer = function (array $data) use ($status): array {
            if (!in_array($data['post_status'] ?? '', PullDefinition::ALLOWED_STATUSES, true)) {
                $this->logger->warning(sprintf('Another callback set status "%s" on an imported draft; it was set back to "%s".', (string) ($data['post_status'] ?? ''), $status));
                $data['post_status'] = $status;
            }
            return $data;
        };
        add_filter('wp_insert_post_data', $enforcer, PHP_INT_MAX, 1);
        try {
            $postId = wp_insert_post($args, true);
        } finally {
            remove_filter('wp_insert_post_data', $enforcer, PHP_INT_MAX);
        }
        if (is_wp_error($postId)) {
            throw new InsertException('Draft insert failed: ' . $postId->get_error_message());
        }
        if ($postId <= 0) {
            throw new InsertException('Draft insert failed: no post id returned.');
        }
        $after = get_post_status($postId);
        if (!in_array($after, PullDefinition::ALLOWED_STATUSES, true)) {
            $this->logger->warning(sprintf('Imported draft %d was changed to "%s" during insert; set back to "draft".', $postId, (string) $after));
            wp_update_post(['ID' => $postId, 'post_status' => 'draft']);
        }

        $flags = isset($article['flags']) && is_array($article['flags']) ? $article['flags'] : [];
        $meta = [
            'url' => esc_url_raw((string) $article['url'], ['http', 'https']),
            'publisher' => Article::plainText((string) $article['source_name']),
            'source_id' => sanitize_text_field((string) $article['source_id']),
            'author' => Article::plainText((string) $article['author']),
            'published' => sanitize_text_field((string) $article['published_at']),
            'image' => esc_url_raw((string) $article['image'], ['http', 'https']),
            'pull' => $pull->slug,
            'fetched' => gmdate('Y-m-d\TH:i:s\Z', $fetchedAt),
            'key' => $keyHashHex,
            'flags' => implode(',', array_map('strval', $flags)),
        ];
        foreach ($meta as $name => $value) {
            update_post_meta($postId, self::metaKey($name), $value);
        }
        $this->maybeSideload($postId, $article);
        return $postId;
    }

    /**
     * The draft body: description, editor's note, attribution, in that order.
     *
     * @param array<string, mixed> $article
     */
    public function content(array $article, PullDefinition $pull): string
    {
        $parts = [];
        $description = Article::plainText((string) $article['description']);
        if ($description !== '') {
            $parts[] = '<p>' . esc_html($description) . '</p>';
        }
        $parts[] = $this->note($article, $pull);
        $parts[] = $this->attribution($article);
        $html = implode("\n", $parts);
        $filtered = apply_filters('newsapi_pull_draft_content', $html, $article, $pull);
        if (is_string($filtered)) {
            $html = $filtered;
        }
        return wp_kses_post($html);
    }

    /**
     * @param array<string, mixed> $article
     */
    public function note(array $article, PullDefinition $pull): string
    {
        $default = sprintf(
            'Imported for editorial review by the pull "%s". This is a lead, not an article: check it, rewrite it and delete this note before publishing.',
            $pull->slug
        );
        $text = apply_filters('newsapi_pull_editor_note', $default, $article, $pull);
        $text = is_string($text) ? $text : $default;
        $html = '<div class="newsapi-pull-note"><p><strong>Editor\'s note:</strong> ' . esc_html($text) . '</p>';
        $snippet = Article::plainText((string) ($article['content'] ?? ''));
        if ($this->settings->includeSnippet() && $snippet !== '') {
            $html .= '<blockquote class="newsapi-pull-snippet"><p>' . esc_html($snippet) . '</p></blockquote>';
        }
        return $html . '</div>';
    }

    /**
     * @param array<string, mixed> $article
     */
    public function attribution(array $article): string
    {
        $rel = apply_filters('newsapi_pull_link_rel', 'nofollow noopener');
        $rel = is_string($rel) ? $rel : 'nofollow noopener';
        $publisher = Article::plainText((string) $article['source_name']);
        $publisher = $publisher !== '' ? $publisher : 'the original publisher';
        $href = esc_url((string) $article['url'], ['http', 'https']);
        $source = $href !== ''
            ? '<a href="' . $href . '" rel="' . esc_attr($rel) . '">' . esc_html($publisher) . '</a>'
            : esc_html($publisher);
        $line = 'Source: ' . $source;
        $author = Article::plainText((string) $article['author']);
        if ($author !== '') {
            $line .= ', by ' . esc_html($author);
        }
        $ts = (int) ($article['published_ts'] ?? 0);
        if ($ts > 0) {
            $line .= ', published ' . esc_html((string) wp_date('Y-m-d H:i T', $ts));
        }
        $html = '<div class="newsapi-pull-attribution"><p>' . $line . '.</p>';
        $credit = $this->creditLine();
        if ($credit !== '') {
            $html .= $credit;
        }
        $html .= '</div>';
        $filtered = apply_filters('newsapi_pull_attribution_html', $html, $article);
        $filtered = is_string($filtered) ? $filtered : $html;
        if ($credit !== '' && !str_contains($filtered, 'newsapi-pull-credit')) {
            $filtered .= $credit;
        }
        return $filtered;
    }

    /**
     * The fixed credit line naming the data provider with a link, or '' when
     * it is turned off (plan paid only).
     */
    public function creditLine(): string
    {
        if (!$this->settings->creditLine()) {
            return '';
        }
        $text = apply_filters('newsapi_pull_credit_text', self::CREDIT_TEXT);
        $text = is_string($text) && trim($text) !== '' ? trim($text) : self::CREDIT_TEXT;
        return '<p class="newsapi-pull-credit"><a href="' . esc_url(self::CREDIT_URL) . '" rel="nofollow noopener">'
            . esc_html($text) . '</a></p>';
    }

    public function excerpt(string $description): string
    {
        $max = apply_filters('newsapi_pull_excerpt_length', 300);
        return Article::excerpt($description, max(20, is_numeric($max) ? (int) $max : 300));
    }

    /** Site-timezone local time, so the draft shows the original date. */
    public function postDate(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
    }

    /**
     * Sideloads the image only when a site opts in. Any failure is non-fatal.
     *
     * @param array<string, mixed> $article
     */
    private function maybeSideload(int $postId, array $article): void
    {
        $image = esc_url_raw((string) $article['image'], ['http', 'https']);
        if ($image === '' || apply_filters('newsapi_pull_sideload_image', false, $article) !== true) {
            return;
        }
        try {
            if (!function_exists('media_sideload_image') && defined('ABSPATH')) {
                $includes = (string) constant('ABSPATH') . 'wp-admin/includes/';
                require_once $includes . 'media.php';
                require_once $includes . 'file.php';
                require_once $includes . 'image.php';
            }
            $caption = Article::plainText((string) $article['source_name']);
            $id = media_sideload_image($image, $postId, $caption, 'id');
            if (is_wp_error($id) || !is_int($id)) {
                $this->logger->warning(sprintf('Image sideload failed for draft %d; the draft is kept.', $postId));
                return;
            }
            wp_update_post(['ID' => $id, 'post_excerpt' => $caption]);
            set_post_thumbnail($postId, $id);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Image sideload failed for draft %d: %s', $postId, $e->getMessage()));
        }
    }
}
