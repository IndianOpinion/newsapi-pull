=== newsapi-pull ===
Contributors: indianopinion
Tags: news, drafts, import, deduplication, quota
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Pulls headlines from NewsAPI.org into drafts, with filters, URL-hash de-duplication, source attribution and a daily quota meter. Never publishes.

== Description ==

newsapi-pull fetches headlines from NewsAPI.org on a schedule, keeps only articles it has not seen before, and writes each as a draft that names its publisher and links to the original. It counts every request against a daily meter, stops before the quota runs out, and refuses to run a free-tier key on a production site.

Written from scratch; not derived from any third-party plugin. It is independent of and not affiliated with NewsAPI.org, and uses its official HTTP interface with your own API key.

= Free tier and drafts =

* The free tier (plan "developer") fetches only when the WordPress environment type is local or development. On staging or production it never runs, and no setting, filter, constant, WP-CLI flag or button changes that. Only plan "paid" fetches elsewhere.
* Every article becomes a draft or a pending item. The plugin never publishes, schedules or posts anything.
* A draft is a lead for an editor, not an article to publish as is.

= Features =

* Top headlines and article search, with country, category, language, sources, domains and keywords, validated before any request.
* Two de-duplication keys per URL: a canonical key that collapses mobile, AMP and tracking variants, and a frozen plain key.
* Every rejected article leaves a mark, so the next run does not look at it again.
* Attribution on every draft: publisher, author, original link, date and a credit to NewsAPI.org.
* Daily request meter with a reserve for manual runs, per-pull caps and a kill switch.
* WP-CLI: list, run, run --due, validate, quota, check, sources, log, export, import.

== Installation ==

1. Upload the plugin folder to wp-content/plugins/ and activate it.
2. Add `define('NEWSAPI_PULL_API_KEY', 'your-key');` and `define('NEWSAPI_PULL_PLAN', 'developer');` to wp-config.php, or use Tools, NewsAPI Pull.
3. Add a pull, review it, and resume it or click Run now.

== Frequently Asked Questions ==

= Why does nothing happen on my live site? =

The free plan is for development and testing only. On staging or production it never fetches. Declare plan "paid" if you hold a paid plan.

= Can it publish automatically? =

No. Drafts and pending items only.

= What does uninstall remove? =

Uninstalling removes the plugin's settings, pulls, quota meter, run log, source catalogue cache and its de-duplication table. It never deletes, trashes or modifies the drafts or posts the plugin created, nor their meta: they are your site's content.

== Changelog ==

= 0.1.0 =
* First release.
