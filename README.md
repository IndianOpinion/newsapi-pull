# newsapi-pull

newsapi-pull is a WordPress plugin that pulls headlines from NewsAPI.org into drafts, with country, language and source filters, URL-hash de-duplication, source attribution and a daily quota meter. It exists because the quick-script way of wiring a news API into WordPress creates five drafts of one story, burns the free quota by mid-morning, and strips the source.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party service it integrates with, including NewsAPI.org. It uses the official NewsAPI.org HTTP interface with your own API key.

Measured result that shaped it: on a comparable ingest queue, 43 of the 50 oldest candidates were duplicates that had left no mark, and the oldest end of the queue stopped moving. Here every rejected article leaves a mark.

## Free tier and drafts

- The free tier (plan `developer`) fetches only when the WordPress environment type is `local` or `development`. On `staging` or `production` (the WordPress default when nothing is set) a `developer` plan never runs, and no setting, filter, constant, WP-CLI flag or button changes that. Only plan `paid` fetches elsewhere. The NewsAPI.org pricing page says the free plan is for development and testing in a development environment only.
- Every article becomes a draft (or a pending item, if you choose). The plugin never publishes, schedules or posts anything, and no setting or filter can make it do so: the status is forced back after every filter and checked again after the insert.
- A draft is a lead for an editor, not an article to publish as is. You must hold the rights to republish anything you publish. This is information for the operator, not legal advice.

## Install

Requirements: WordPress 6.4 or later, PHP 8.1 or later. The `intl` extension is optional (it lets internationalised host names meet their ASCII form).

### Without Composer

1. Download the release zip, or `git clone https://github.com/IndianOpinion/newsapi-pull.git` into `wp-content/plugins/`.
2. Activate **newsapi-pull** in Plugins. Nothing runs yet.

The plugin has no runtime dependencies; `bootstrap.php` registers its own autoloader.

### With Composer

```sh
composer require indianopinion/newsapi-pull
```

With `composer/installers` the package lands in `wp-content/plugins/newsapi-pull/` (its type is `wordpress-plugin`).

### As a must-use plugin

Put the folder at `wp-content/mu-plugins/newsapi-pull/` and copy [`examples/mu-plugin-loader.php`](examples/mu-plugin-loader.php) to `wp-content/mu-plugins/newsapi-pull-loader.php`. Its one working line is:

```php
require_once __DIR__ . '/newsapi-pull/newsapi-pull.php';
```

Must-use plugins have no activation hook: the de-duplication table is created on the first run, and the daily maintenance event on the first admin page load.

## Quick start

1. Put your key and plan in `wp-config.php` (or use the settings page):

   ```php
   define('NEWSAPI_PULL_API_KEY', 'your-key');
   define('NEWSAPI_PULL_PLAN', 'developer'); // or 'paid'
   define('WP_ENVIRONMENT_TYPE', 'development'); // developer plan only fetches on local or development
   ```

2. Open **Tools, NewsAPI Pull**. Check that "Fetching is allowed".
3. Click **Add the example pull (paused)**, or **Add a pull**. Review it, then **Resume** it, or click **Run now**.
4. Open **Posts, Drafts**. Each draft has the description, an editor's note, and an attribution block naming the publisher, linking the original, and crediting NewsAPI.org.

From the command line:

```sh
wp newsapi-pull validate example-headlines
wp newsapi-pull run example-headlines
wp newsapi-pull quota
```

## Configuration

Settings live under Tools, NewsAPI Pull. Constants in `wp-config.php` win over settings.

| Setting | Default | Notes |
|---|---|---|
| Plan | none, you must choose | `developer` or `paid`. Nothing runs until chosen. Constant `NEWSAPI_PULL_PLAN`. |
| API key | empty | Constant `NEWSAPI_PULL_API_KEY` preferred. Shown only as its last four characters. |
| Daily request limit | 100 on `developer`, none on `paid` | Counted per UTC day. |
| Reserve | 20 requests | Held back for manual runs and the source catalogue. Scheduled runs stop when used plus reserve reaches the limit. |
| Credit line | on | "News data from NewsAPI.org" with a link. Can be turned off only on `paid`; check your own terms first. |
| Content snippet in note | off | Quotes the API's truncated `content` field inside the editor's note, never in the body. |
| Kill switch | off | Constant `NEWSAPI_PULL_DISABLED` wins over the setting. |
| Debug log | off | One line per run and per rejection to the PHP error log, key redacted. |
| Catalogue cache | 24 hours, minimum 6 | |
| Seen-table retention | 180 days, minimum 30 | Pruned daily in batches of 1,000. |

Per pull:

| Field | Default | Notes |
|---|---|---|
| Endpoint | | `headlines` (top headlines) or `everything` (article search) |
| Country | empty | Any assigned two-letter ISO 3166-1 code, headlines only. Codes outside the documented list are accepted with a warning. |
| Category | empty | `business`, `entertainment`, `general`, `health`, `science`, `sports`, `technology`. Headlines only. |
| Sources | empty | At most 20 source ids. Not combined with country or category on headlines. |
| Language | empty | `ar de en es fr he it nl no pt ru sv ud zh`. On headlines it is applied by choosing catalogue sources in that language. |
| Keywords | empty | At most 500 characters. Required on `everything` unless sources or domains are set. |
| Search in, domains, exclude domains, sort | | `everything` only. Sort: `publishedAt`, `relevancy`, `popularity`. |
| Page size | 50 | 1 to 100 |
| Max pages per run | 1 | 1 to 5, and 1 only on `developer` |
| Interval | 60 minutes | At least 15 |
| Max article age | 72 hours, 96 on `developer` | The free tier delays articles by 24 hours. |
| Daily request cap | none | Per pull, on top of the site limit. |
| Post type, author, categories, tags | `post`, you | Optional tag with the source name. |
| Status | `draft` | `draft` or `pending`. Nothing else is accepted. |

A fresh install runs nothing: there are no default pulls.

## How it works

1. **Pre-checks**, in order: kill switch, plan chosen, environment rule, key present (and not rejected by the API), pull valid, pull not paused, no back-off, quota meter allows. The first failure ends the run with its reason and no request is sent.
2. **Lock.** One run at a time per site, held in an option written with an atomic add. It expires after 10 minutes.
3. **Request.** HTTPS to `newsapi.org` only, key in the `X-Api-Key` header (never in a URL or log), TLS verification always on. Every request is counted on the meter before it is sent.
4. **Articles**, in API order: removed placeholders, bad URLs and wrapper URLs are rejected; the URL becomes two keys (below); an article already seen is skipped; then the title, deny lists, language, age and your own accept filter are checked. Every rejected article with a usable URL is recorded, so the next run does not look at it again.
5. **Draft.** Title cleaned, body built from the description, an editor's note and the attribution block, all API text escaped and the body passed through `wp_kses_post`. Status forced to `draft` or `pending`. Meta written. The key is recorded only after the draft exists, so a failed insert is retried next run.
6. **Summary.** Pull state, the run log and the `newsapi_pull_run_finished` action, once per run.

### De-duplication keys

Each article URL gives two keys, stored as 16-byte hashes in `{prefix}newsapi_pull_seen` (no URL text in the table):

- **Canonical key** (versioned): scheme ignored, default ports dropped, host lower-cased and converted to ASCII, delivery labels (`www`, `m`, `amp`, `mobile` and others) stripped, path normalised (dot segments, repeated and trailing slashes, `index.html`, percent-encoding, case), AMP path forms collapsed, tracking parameters dropped, and on slug paths only id-bearing parameters kept. Language subdomains are kept: `hindi.example.com` is a different article.
- **Plain key** (frozen forever): the URL lower-cased with the scheme, one `www.`, the fragment and one trailing slash removed. It keeps rows matching when the canonical rules change.

Try it without WordPress: `php examples/check-keys.php "https://m.example.com/news/story/amp/?utm_source=x"`.

## Hooks

Filters: `newsapi_pull_enabled`, `newsapi_pull_environment_allowed` (may only turn true into false), `newsapi_pull_countries`, `newsapi_pull_languages`, `newsapi_pull_categories`, `newsapi_pull_request_args` (the key header, TLS and URL are re-applied after it), `newsapi_pull_timeout` (5 to 60), `newsapi_pull_user_agent`, `newsapi_pull_lock_ttl`, `newsapi_pull_url`, `newsapi_pull_wrapper_hosts`, `newsapi_pull_placeholder_hosts`, `newsapi_pull_mirror_labels`, `newsapi_pull_tracking_params`, `newsapi_pull_id_params`, `newsapi_pull_script_extensions`, `newsapi_pull_lowercase_path`, `newsapi_pull_canonical_key`, `newsapi_pull_accept_article`, `newsapi_pull_denied_sources`, `newsapi_pull_denied_hosts`, `newsapi_pull_draft_args` (the status rule is applied after it), `newsapi_pull_draft_content`, `newsapi_pull_editor_note`, `newsapi_pull_attribution_html` (the credit line is re-appended if removed while on), `newsapi_pull_credit_text`, `newsapi_pull_link_rel`, `newsapi_pull_sideload_image` (default false), `newsapi_pull_excerpt_length`, `newsapi_pull_max_drafts_per_run` (default 50), `newsapi_pull_window_overlap`, `newsapi_pull_lookback`, `newsapi_pull_catalogue_ttl`, `newsapi_pull_retention_days`, `newsapi_pull_rate_limit_backoff`, `newsapi_pull_remaining_headers`, `newsapi_pull_log_size`.

Actions: `newsapi_pull_before_request` (endpoint, query without the key, pull slug), `newsapi_pull_draft_created` (post id, article, pull), `newsapi_pull_article_rejected` (reason, article, pull slug), `newsapi_pull_run_finished` (summary), `newsapi_pull_quota_exhausted` (`meter` or `api`).

Functions (never throw): `newsapi_pull_run( $slug, $manual = false )`, `newsapi_pull_is_seen( $url )`, `newsapi_pull_keys( $url )`, `newsapi_pull_quota()`.

See [`examples/site-filters.php`](examples/site-filters.php) for a working set.

## WP-CLI

| Command | What it does |
|---|---|
| `wp newsapi-pull list` | Pulls with status, interval, last run and outcome |
| `wp newsapi-pull run <slug> [--dry-run] [--yes]` | Runs one pull now. A dry run sends real requests (they count) and writes nothing |
| `wp newsapi-pull run --due` | Runs every due pull, one after another. For sites with WP-Cron disabled |
| `wp newsapi-pull validate <slug>` | Validation only, no request |
| `wp newsapi-pull quota [--format=table\|json]` | The meter |
| `wp newsapi-pull check <url>` | Both keys, hashes, version, and whether each is stored |
| `wp newsapi-pull sources [--country=] [--language=] [--category=] [--refresh]` | The source catalogue |
| `wp newsapi-pull log [--lines=<n>] [--pull=<slug>]` | The run log |
| `wp newsapi-pull export` / `import <file>` | Pulls as JSON, never the key. Imported pulls start paused |

With WP-Cron disabled, call it from the system cron, for example every 15 minutes:

```
*/15 * * * * www-data wp --path=/var/www/site newsapi-pull run --due --quiet
```

## Observability

The settings page shows the plan and environment, the meter (used of limit, reserve, remaining, reset time), each pull's last run, outcome, drafts created, rejections by reason, next run and any disabled or back-off state, and the last 50 run summaries. Admin notices appear for: plan not chosen, environment block, key error, quota exhausted, rate-limit back-off, stale catalogue and a disabled pull.

## Limitations

- The plugin cannot see your NewsAPI.org account. It cannot verify your plan; the meter is local, corrected by a remaining-quota response header only if the API ever sends one. The API's own quota error is the final word.
- NewsAPI.org does not document when its daily counter resets. The meter uses UTC midnight.
- The `content` field the API returns is truncated by the API. The plugin never fetches the article page to get more.
- The image is stored as a URL in meta. It is not downloaded unless a site turns on `newsapi_pull_sideload_image`.
- On a multisite network with the plugin activated per site, sites sharing one key share one quota that no site can see. Network-activate the plugin to keep one meter per key for the whole network.
- Uninstall removes the plugin's options, pulls, meter, log and table, and never touches the drafts or their meta.

## FAQ

**Why does nothing happen on my live site with the free key?** The free plan is for development only. On `staging` or `production` it never fetches. Declare plan `paid` if you hold a paid plan.

**Can a filter or a flag make the free plan run on production?** No. The environment filter can only make the rule stricter, and there is no override flag.

**Can I make it publish automatically?** No. Drafts and pending items only, enforced after every filter and after the insert.

**Why was an article skipped as `seen` when I deleted its draft?** The key stays in the table so the article is not imported again. It is pruned after the retention period, or check it with `wp newsapi-pull check <url>`.

**What is the key in my logs?** It is never there: every message passes through a redactor that replaces it with `[redacted]`.

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md). `composer install`, then `composer check` runs phpcs, PHPStan (level 8) and PHPUnit. Design notes and decisions are in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md); the requirements are in [docs/PRD.md](docs/PRD.md).

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See [LICENSE](LICENSE). NewsAPI.org is a third-party service with its own terms; this plugin does not grant you any right to the articles it lists.
