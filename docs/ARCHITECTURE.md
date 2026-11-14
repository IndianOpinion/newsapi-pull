# newsapi-pull: architecture and decisions

This document describes how the plugin is built and records every decision
taken while building it from [PRD.md](PRD.md): the six rulings on the PRD's
open questions, what the public documentation said when it was re-read, and
the choices the PRD left to the builder.

## Modules

All classes live under `IndianOpinion\NewsApiPull` in `src/`. Nothing needs
Composer at runtime: `bootstrap.php` registers a PSR-4 autoloader.

| Class | Role | WordPress? |
|---|---|---|
| `UrlKey`, `UrlKeyRules` | The canonical and plain keys and the hash. Pure functions | No |
| `Keys` | Wraps `UrlKey` with the URL, wrapper-host, rule and canonical-key filters; knows the site's own hosts | Filters only |
| `PullDefinition` | Immutable pull, built from an array, `validate()` and `warnings()` | Filters, post types, users |
| `Vocabulary` | Allowed countries, languages, categories, endpoints, search fields, sorts | Filters |
| `Query` | A validated request: endpoint and parameters, never the key | No |
| `Client` | The HTTP client. One method per endpoint; typed exceptions | HTTP API |
| `ArticlesResponse`, `SourcesResponse` | Typed responses | No |
| `Settings` | The settings option, constants, plan, environment rule | Options |
| `Meter` | Daily request meter per key, per site or per network | Options |
| `Lock` | One run at a time per site, atomic option add | Options |
| `State` | Site-wide key error and rate-limit back-off | Options |
| `PullRepository` | Pulls and their run state, one option | Options |
| `SeenStore` | The de-duplication table | `$wpdb` |
| `Catalogue` | Source catalogue: transient plus last-good fallback | Transients, options |
| `Article` | Normalises one API article; title, text and excerpt cleaning | No |
| `DraftWriter` | Builds the body, inserts the draft, enforces the status, writes meta, optional sideload | Posts |
| `Runner` | The run: pre-checks, lock, paging, article checks, drafts, one state write | Everything above |
| `Scheduler` | One cron event per active pull, daily maintenance, self-heal | Cron |
| `RunLog`, `Logger`, `Redactor` | Ring buffer of summaries, redacted error-log lines | Options, `error_log` |
| `Plugin` | Builds the services, registers hooks, activation, deactivation | Hooks |
| `Uninstaller` | Removes the plugin's data on every site, never posts | Options, `$wpdb` |
| `Admin\AdminPage`, `Admin\PostsColumn` | Tools page, notices, posts-list column and filter | Admin |
| `Cli\Command` | `wp newsapi-pull` | WP-CLI |

`src/functions.php` holds the four stable global functions. None throws.

## Data flow of one run

```
WP-Cron event (slug) / Run now / wp newsapi-pull run
  -> Runner::preCheck: kill switch, plan, environment, key, key error,
     pull exists and valid, not disabled, not paused, no back-off
  -> Lock::acquire (else `locked`)
  -> too_soon (scheduled only), Meter::refusal (else `quota` / `pull_quota`)
  -> SeenStore::ensure (creates the table if this site has none)
  -> language? Catalogue::get (may fetch once, counted) -> sources for headlines
  -> for each page (1 on developer, up to max pages on paid):
       Meter check (pages after the first) -> Client (Meter::count before send)
       -> for each article in API order:
            removed? bad_url? wrapper_url? own_host?  (counted, not recorded)
            seen in this run or in the table?          (counted as duplicate)
            no_title, source_denied, language_mismatch, too_old, accept filter
                                                       (counted and recorded)
            DraftWriter::write -> SeenStore::record(imported) -> draft_created
       -> stop on: all seen, cap reached, short page, totalResults, max pages
  -> Runner::finish: pull state, run log, run_finished action (once)
  -> Lock::release (in finally)
```

## Stored data

| What | Where | Autoload |
|---|---|---|
| Settings | option `newsapi_pull_settings` | no |
| Pulls and their state | option `newsapi_pull_pulls` | no |
| Meter | option `newsapi_pull_meter` (site option when network-activated) | no |
| Lock | option `newsapi_pull_lock` (expiry time) | no |
| Key error, rate-limit back-off | option `newsapi_pull_state` | no |
| Run log | option `newsapi_pull_log`, last 200 | no |
| Catalogue | transient `newsapi_pull_catalogue` and option `newsapi_pull_catalogue_fallback` | no |
| Table version | option `newsapi_pull_db_version` | no |
| Seen keys | table `{prefix}newsapi_pull_seen` | |
| Draft meta | `_newsapi_pull_url`, `_publisher`, `_source_id`, `_author`, `_published`, `_image`, `_pull`, `_fetched`, `_key`, `_flags` | |

The table: `id`, `key_hash BINARY(16)` with a unique index, `key_kind`
(0 canonical, 1 plain), `key_version`, `post_id` (0 for a rejection),
`reason`, `pull_slug`, `created_at` (UTC) with an index for pruning. No URL
text is stored.

## Rulings on the open questions

Each ruling below was given by the owner on 14 November 2026.

### R1. Countries

**Ruling:** accept any two-letter ISO code; the documented list seeds a
filterable allowlist.

**Built:** a country must be an assigned ISO 3166-1 alpha-2 code (the full
list ships in `Vocabulary::ISO_COUNTRIES`); anything else, such as `xx`, is a
validation error. The allowlist `newsapi_pull_countries` defaults to the 54
countries the sources endpoint documents. A valid code outside it is saved,
with a warning that the API may return no articles; such a run is an empty
run, not an error.

**Why:** the headlines page currently lists only `us` while its own example
uses `de`, and the sources page lists 54 countries. Refusing codes the API may
still accept would block operators for no gain; accepting garbage would spend
quota on requests that cannot work.

### R2. Free-tier paging

**Ruling:** page 1 only on the free tier; paging is opt-in and only for paid
plans, and the environment guard in PRD 4.2 stays absolute (no override).

**Built:** on plan `developer`, `max_pages` above 1 is a validation error on
save, and the runner fetches one page whatever is stored. On `paid` the pull's
max pages (1 to 5, default 1) applies. If the API answers a later page with
`maximumResultsReached` (a code seen from the API for plans that cap search
results; it is not on the errors page read on 14 November), paging stops
cleanly and the run keeps its drafts. The environment rule has no override:
the filter can only turn true into false, and there is no flag on WP-CLI or
the Run now button.

### R3. Multisite quota

**Ruling:** one quota per API key held at network level when
network-activated, per site otherwise.

**Built:** the meter is keyed by a fingerprint of the key (the first 16 hex
characters of a SHA-256 of a prefixed key, never the key). When the plugin is
network-activated, the meter lives in one site option, so every site using
the same key adds to one count. Activated per site, each site has its own
meter. The run lock stays per site, so the meter
does not rely on it: every request is checked and counted in one step
(`Meter::claim`) just before it is sent, under a MySQL named lock
(`GET_LOCK`), and each meter read drops the process's cached copy of the
option first. Two sites, or a run and an admin catalogue fetch, therefore
cannot both take the last request, and a long run cannot write back a stale
count over requests another process made meanwhile. If the named lock cannot
be taken within 5 seconds the write goes ahead without it (fail open on
bookkeeping); the API's quota error remains the hard stop (lesson 10). The
settings page says so on a multisite network without network activation.

### R4. Credit wording

**Ruling:** a fixed attribution line naming the API provider with a link, on
by default, filterable text. The provider's name is allowed in this repo.

**Built:** `<p class="newsapi-pull-credit"><a href="https://newsapi.org"
rel="nofollow noopener">News data from NewsAPI.org</a></p>` at the end of the
attribution block. The text is filterable with `newsapi_pull_credit_text`; the
link is fixed. On plan `developer` it cannot be turned off; on `paid` the
setting can turn it off, with a reminder to check your own terms. If
`newsapi_pull_attribution_html` removes it while it is on, it is re-appended.

**Verified:** the terms page (last updated 30 April 2020) gives no exact
credit wording. It forbids misrepresenting the source of material and
removing labels of its origin. The pricing page names no attribution
requirement.

### R5. Reserve

**Ruling:** a number of requests (default 20), not a percentage.

**Built:** the `reserve` setting is an integer, default 20, range 0 to 10,000.
It is clamped to the limit minus one, so a small limit still lets one
scheduled request through. Scheduled runs stop when used plus reserve reaches
the limit; manual runs and the catalogue may use the reserve; nothing runs at
the limit. With the developer defaults, scheduled runs stop at 80 of 100,
which leaves ample room for one hourly pull (24 a day).

This replaces the "10 percent" of PRD case 26 and the "reserve percent" of
PRD 6.1.

### R6. Remaining quota

**Ruling:** prefer a remaining-quota header when the API sends one; otherwise
count locally.

**Built:** after every response the client reads the first numeric value of
`X-RateLimit-Remaining`, `RateLimit-Remaining` or `X-Api-Quota-Remaining`
(filterable with `newsapi_pull_remaining_headers`). When one is present, the
meter raises its own count to `limit - remaining`, stores the value, and the
snapshot reports `source: header`. A remaining value of 0 stops further
requests that day.

**Verified:** none of the documentation pages read on 14 November documents
such a header, so in practice the meter is local.

## Documentation re-read on 14 November 2026

Pages read: errors, top-headlines, sources, everything, pricing, terms and
authentication on newsapi.org. Differences from the PRD and how each is
handled:

1. The errors page lists the ten codes the PRD names, and status classes 400,
   401, 429 and 500, but does not map each code to a status. The client
   therefore decides by the `code` field. HTTP 429 with no code is treated as
   `rateLimited`; `apiKeyExhausted` with HTTP 429 stays a quota error, not a
   rate limit.
2. The headlines page lists only `us` as a country (ruling R1).
3. The sources endpoint path is `/v2/top-headlines/sources`.
4. The developer plan: 100 requests a day, articles delayed 24 hours, search
   up to a month old, development and testing in a development environment
   only, never staging or production. No plan-level paging cap is stated
   (ruling R2).
5. The key may go in the query string, the `X-Api-Key` header or the
   `Authorization` header. The plugin uses `X-Api-Key` only, and strips any
   `X-Api-Key` or `Authorization` header a filter adds before setting its own.
6. Page size: the headlines endpoint defaults to 20 and the search endpoint
   to 100, both with a maximum of 100. The plugin always sends `pageSize`
   (default 50) so both behave the same.

## Builder decisions

The PRD left these to the builder.

1. **Key errors pause every pull through one record.** A key error
   (`apiKeyMissing`, `apiKeyInvalid`, `apiKeyDisabled`) is stored once, with
   the key fingerprint. Every pull refuses with `key_error` while the stored
   fingerprint matches the current key. Changing the key clears it with no
   other step. This is simpler and safer than toggling each pull's pause flag,
   which an operator would then have to undo one by one.
2. **Manual runs ignore pause and the failure back-off**, not the rate-limit
   back-off, the key error, the environment rule or the meter. An operator
   testing a paused pull (the example pull starts paused) needs Run now to
   work.
3. **Quiet refusals.** `locked`, `too_soon`, `paused`, `pull_disabled` and
   `backoff` refusals are not written to the run log, because cron repeats
   them every tick. Their state is visible on the pull list.
4. **Consecutive-failure back-off** is enforced in the pre-check: after five
   `api_error`, `network` or `bad_response` runs, a scheduled run is refused
   until four intervals have passed since the last run. No rescheduling is
   needed, and one success resets the count.
5. **Hash.** MD5, raw 16 bytes, for compact equality lookup only. The
   canonical key string starts with `v1|`, so a change of version gives new
   hashes; the plain key carries no prefix and never changes.
6. **Plain key and user information.** The plain key drops `user:pass@` too,
   so neither key carries credentials (case 48).
7. **Script endpoints** are recognised on the path before a trailing
   `index.php` is removed, so `/news/index.php?id=1` keeps all its
   non-tracking parameters.
8. **AMP endings.** `story.amp.html` and `story-amp.html` collapse to
   `story.html`; `story.amp` to `story`; `/amp/1` at the end is dropped as a
   pair before single `amp` segments are dropped.
9. **The placeholder host list** defaults to `removed.com`, the host the API
   uses for withdrawn articles.
10. **Title suffix.** Besides ` - ` and ` | `, an en dash or em dash separator
    before the source name is also removed (written as escapes in the code).
11. **Status enforcement** is in two places: a `wp_insert_post_data` callback
    at the highest priority, active only during our insert, which logs a
    warning when it has to correct the status; and a check after the insert,
    which sets the post back to `draft` with a warning.
12. **Post date.** The draft's `post_date` is the original publication time in
    the site time zone; `post_date_gmt` is sent as zero so the date stays
    floating while the post is a draft.
13. **Meta names** start with an underscore (`_newsapi_pull_*`) so they stay
    out of the custom-fields box, where an editor could change them by
    accident.
14. **The catalogue** is requested once, without filters, and filtered
    locally, so one request serves every language and country.
15. **Catalogue fetches obey the run rules.** The kill switch, plan,
    environment rule, key and meter all apply; a catalogue request may use the
    reserve.
16. **Dry runs** send real requests (counted), run every check, and write no
    draft, record, state or run-log entry. WP-CLI asks for confirmation
    unless `--yes`.
17. **Import** validates every pull as on save, drops `api_key`, `apiKey` and
    `key` fields, and saves imported pulls paused so an editor reviews them
    before they spend quota.
18. **Table creation** happens on activation (every site when network-wide)
    and lazily before any table use, so must-use installs and sites added
    later work. After `dbDelta` the table is checked with `SHOW TABLES`
    before the version is recorded.
19. **Database errors.** After the first failure, `SeenStore` refuses every
    further call in the request without a query, and the run reports
    `db_error` once.
20. **The window** (`from`) is sent as `Y-m-d\TH:i:s` in UTC, as the search
    endpoint documents. The look-back is at least one hour whatever a filter
    returns.
21. **Notices** link to the settings page, where every fix is made.
22. **No redirects.** The client never follows a redirect, whatever
    `newsapi_pull_request_args` says: the key travels in a header, and a
    redirect would carry it to whatever host the redirect names. Streaming to
    a file is turned off for the same reason the URL is fixed.
23. **Run state is written field by field.** At the end of a run only the
    run's own state fields are written, after reading the pulls option again,
    so a pause, edit or delete made by an editor while the run was waiting on
    the API is not undone.
24. **composer.lock** is not committed: the plugin has no runtime
    dependencies, and CI resolves the dev tools per PHP version.

## Testing

PHPUnit 10 against a stub layer in `tests/stubs/`: an in-memory WordPress
(options, transients, hooks, posts, meta, cron, users, nonces, multisite
switching), a fake `$wpdb` that understands exactly the store's queries, and a
WP-CLI recorder. HTTP responses are queued in `WpStub::$responses`; no test
reaches the network. Each test name carries the acceptance-test number from
PRD section 12 (T1 to T98). The plain-key fixture
`tests/fixtures/plain-keys.tsv` is frozen with the first release and must not
change.

PHPStan runs at level 8 against `php-stubs/wordpress-stubs` and
`php-stubs/wp-cli-stubs`. phpcs runs PSR-12.
