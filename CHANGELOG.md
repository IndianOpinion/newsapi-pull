# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-14

### Added

- Pulls from the top-headlines and article-search endpoints into drafts (or pending items), on a WP-Cron schedule or from `wp newsapi-pull run --due`.
- Filter validation before any request: country (any assigned ISO code, documented list as the allowlist), category, up to 20 sources, language, keywords, search fields, domains, sort, page size, pages, interval and draft settings.
- Language on headlines applied through the cached source catalogue, and a post-fetch language check.
- Two de-duplication keys per URL (versioned canonical key and frozen plain key) in a table with a unique hash index. Rejected articles are recorded so they leave a mark.
- Attribution on every draft: publisher, author, original link, date, and a filterable credit line to NewsAPI.org, on by default.
- Daily request meter counted before each request, a reserve of 20 requests for manual runs and the catalogue, per-pull caps, and a network-level meter per key when network-activated.
- The free plan fetches only on `local` or `development` environments, with no override.
- Error handling for every documented API error code, back-off after rate limits and after five consecutive failures, key redaction everywhere.
- Admin page under Tools with settings, meter, pulls, run log, pull editor, source picker, export and import; a posts-list column and filter.
- WP-CLI commands: list, run, validate, quota, check, sources, log, export, import.
- Uninstall that removes the plugin's data and never touches drafts.

[Unreleased]: https://github.com/IndianOpinion/newsapi-pull/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/newsapi-pull/releases/tag/v0.1.0
