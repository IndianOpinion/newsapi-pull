# newsapi-pull: product requirements

Status: draft for review. Audience: the builder, the code reviewer and anyone
deciding whether to install the plugin. This document describes behaviour,
not code. Where it says "must", an acceptance test in section 12 checks it.

The plugin talks to one third-party service, NewsAPI.org, only through its
official, documented HTTP interface and only with the site operator's own
API key. Every fact about that interface below comes from the public
NewsAPI.org documentation (endpoints, errors, pricing and terms pages). The
builder re-reads those pages before building and records any difference in
`docs/ARCHITECTURE.md`.

## 1. Problem

An editor who wants a steady list of current headlines to work from has two
bad options in WordPress. Copying links by hand is slow and misses stories.
Wiring a news API in with a quick script goes wrong in four ways that each
cost real time:

- The same article arrives again and again, under a mobile host, an AMP
  path, a tracking query or a trailing slash, and the editor sees five
  drafts of one story.
- Items that were skipped as duplicates leave no record, so the next run
  looks at them again, forever. Measured in production on a comparable
  ingest queue: 43 of the 50 oldest candidates were duplicates that had left
  no mark, and the oldest end of the queue stopped moving.
- The free tier has a small daily request quota. A script that does not count
  its own requests burns the quota by mid-morning, and every later call fails
  until the next day.
- The terms of the free tier allow development use only, and the terms of the
  service forbid hiding the origin of material. A careless importer runs the
  free key on a live site and strips the source.

newsapi-pull fetches headlines on a schedule, keeps only articles it has not
seen before, and writes each as a draft that names its publisher and links to
the original. It counts every request against a daily meter, stops before
the quota runs out, and refuses to run a free-tier key on a production site.
Nothing it writes is ever published by the plugin.

## 2. Goals and non-goals

Goals:

1. Pull articles from the two NewsAPI.org article endpoints (top headlines,
   and the full article search) into WordPress drafts on a schedule.
2. Filter by country, category, language, named sources, domains and
   keywords, as the API allows, and validate every filter before a request is
   spent on it.
3. Never create two drafts for one article: a URL-hash de-duplication table
   with two keys per URL (a canonical key and a plain key, section 4.6).
4. Attribute every draft: publisher name, original URL, author when given,
   and a credit to NewsAPI.org as the data provider.
5. Meter every request against a daily quota, show the meter in the admin,
   and stop cleanly at a configurable cap below the plan limit.
6. Respect the free tier's development-only rule: with the plan set to the
   free tier, the plugin fetches only on a development or local environment.
7. Everything lands as a draft. An editor decides what is published.

Non-goals:

- Publishing, scheduling for publication, or auto-posting to social media.
- Rewriting, summarising, translating or expanding article text with a
  language model, or any other text generation.
- Fetching or scraping the article page, full-text extraction, or bypassing
  the API's truncation of the `content` field.
- Downloading or sideloading the article image into the media library by
  default (4.8, case 64).
- Search-engine autoblogging, retail product feeds, or any data source other
  than NewsAPI.org.
- Integration with, or adapters for, any third-party importer plugin.
- Clustering, ranking, relevance gating or any editorial decision beyond the
  filters the operator sets.
- Managing the operator's NewsAPI.org account, plan or billing.

## 3. Users and setting

- **Who installs it:** a site administrator on a self-hosted WordPress site
  who holds their own NewsAPI.org API key.
- **Who uses the output:** editors and authors, who open the drafts in the
  normal post list, rewrite or discard them, and publish by hand.
- **Where it runs:** inside WordPress, as a normal plugin (also loadable as a
  must-use plugin through a one-line loader). Scheduled work runs from
  WP-Cron, or from a real system cron calling WP-CLI. Admin screens live
  under Tools.
- **Plans:** the operator declares the NewsAPI.org plan in the settings:
  `developer` (the free tier) or `paid`. The declaration drives the quota
  default and the environment rule (4.2).

## 4. Behaviour

### 4.1 Definitions

- **Pull**: a saved fetch definition: one endpoint, its filters, a schedule,
  and what to do with the drafts it makes. A site may have many pulls.
- **Run**: one execution of one pull: one or more HTTP requests, then draft
  creation.
- **Request**: one HTTP call to NewsAPI.org, whatever its result. Requests are
  what the quota counts.
- **Article**: one entry in the `articles` array of a response.
- **Seen**: an article URL whose key is in the de-duplication table because
  a draft was created from it, or because it was deliberately rejected and
  marked (4.7).
- **Quota day**: a UTC calendar day. NewsAPI.org does not document when its
  daily counter resets, so the meter uses UTC midnight and treats the API's
  own quota error as the final word (4.4).

### 4.2 Plan and environment rule

1. The settings hold a plan value, `developer` or `paid`. There is no
   default: until the operator picks one, no pull runs (reason
   `plan_missing`) and the admin shows a
   notice asking for the choice.
2. With plan `developer`, a run proceeds only when the WordPress environment
   type (`wp_get_environment_type()`) is `local` or `development`. On
   `staging` or `production` (the WordPress default when nothing is set) the
   run is refused before any request, with reason `plan_environment`, and the
   admin shows a persistent notice that the free tier is for development and
   testing only and that a paid plan is needed elsewhere.
3. The environment check is not filterable to "allow". A filter may only make
   it stricter. The only way to fetch on a staging or production site is to
   declare plan `paid`.
4. Declaring `paid` shows a one-line reminder in the settings that the
   operator is responsible for holding a paid plan. The plugin cannot verify
   the plan and does not try.
5. The manual "Run now" button and the WP-CLI `run` command obey the same
   rule. There is no override flag.

### 4.3 Pulls and filter validation

Each pull has these fields. Validation happens on save and again before every
run; an invalid pull is never sent.

6. **Endpoint**: `headlines` (the top-headlines endpoint) or `everything`
   (the full article search endpoint).
7. **Country**: a two-letter lower-case ISO 3166-1 code, or empty. Valid
   only for `headlines`. The allowed list is a filterable constant that ships
   with the country list the public documentation gives for the sources
   endpoint. The documentation for the headlines endpoint currently lists
   fewer countries; a country that returns no articles is reported as an
   empty run (case 28), not an error.
8. **Category**: one of `business`, `entertainment`, `general`, `health`,
   `science`, `sports`, `technology`, or empty. Valid only for `headlines`.
9. **Sources**: a list of NewsAPI.org source ids, at most 20 (the documented
   limit for the search endpoint; the same limit is applied to headlines).
   More than 20 is a validation error with the count in the message.
10. On `headlines`, sources cannot be combined with country or category. A
    pull with both is a validation error, not a silent drop of one filter.
11. **Language**: one of the ISO 639-1 codes the documentation lists (`ar`,
    `de`, `en`, `es`, `fr`, `he`, `it`, `nl`, `no`, `pt`, `ru`, `sv`, `ud`,
    `zh`), or empty. Sent as a parameter on `everything`.
12. On `headlines`, the API takes no language parameter. A language set on a
    headlines pull is applied by source: the plugin resolves the source list
    for that language (and the country, if set) from the cached source
    catalogue (4.9), and the run sends those source ids instead of the
    country. If more than 20 sources match, the first 20 by catalogue order
    are used and the admin shows how many were left out. If none match, the
    pull is invalid with reason `no_sources_for_language`.
13. A language filter is also checked after the fetch: an article whose
    source in the catalogue has a different language is rejected with reason
    `language_mismatch` (4.7). Articles from sources missing from the
    catalogue are kept (fail open: an unknown source is not evidence of the
    wrong language).
14. **Keywords** (`q`): free text, at most 500 characters, sent URL-encoded.
    The plugin does not parse the API's query syntax; it only trims and
    checks length. Required on `everything` unless sources or domains are
    set (the search endpoint needs at least one of them).
15. **Search in**: any of `title`, `description`, `content`. `everything`
    only.
16. **Domains** and **exclude domains**: comma-separated host names,
    `everything` only. Each entry is trimmed, lower-cased, stripped of any
    scheme and path, and checked as a host name. Invalid entries are a
    validation error naming the entry.
17. **Sort**: `publishedAt` (default), `relevancy` or `popularity`.
    `everything` only.
18. **Window**: on `everything`, the oldest article date is the later of the
    pull's last successful run minus a filterable overlap (default 6 hours)
    and a look-back limit (default 2 days). The newest is left open. On the
    `developer` plan the look-back is capped at 29 days, because the free
    tier searches articles up to a month old.
19. **Page size**: 1 to 100, default 50. **Max pages per run**: 1 to 5,
    default 1.
20. **Schedule**: an interval in minutes, at least 15, default 60. A pull can
    be paused.
21. **Draft settings**: post type (default `post`, any type that supports
    title and editor), author (a user who can edit posts; default the user
    who saved the pull), categories and tags to add, and an optional status
    override restricted to `draft` or `pending`. `publish`, `future` and
    `private` are refused on save and coerced to `draft` at write time.
22. Every pull has a unique slug (lower-case letters, digits and hyphens), used
    in logs, meta and WP-CLI.
23. Unknown fields in a stored pull are ignored and dropped on next save.

### 4.4 Quota meter

24. Every request is counted, before it is sent, against the quota day:
    article requests, source catalogue requests, and requests that later fail.
    A request that fails before reaching the network (DNS error, timeout
    before connect) is still counted, because the plugin cannot know whether
    NewsAPI.org saw it.
25. The daily limit is a setting. Default: 100 for `developer` (the free
    tier's documented daily request allowance), empty (no local limit) for
    `paid`. The operator may lower it or, for `paid`, set any number.
26. A reserve (default 10 percent of the limit, rounded up, at least 1) is
    held back for manual runs and the source catalogue. Scheduled runs stop
    when used plus reserve reaches the limit; manual runs and the catalogue
    may use the reserve. Nothing runs at the limit.
27. A run that needs more than one request checks the meter before each one
    and stops early when the meter says stop. Drafts from earlier pages of
    the same run are kept.
28. When the API answers with the quota-exhausted error (`apiKeyExhausted`)
    or the rate-limit error (`rateLimited`, HTTP 429), the meter is set to
    full for the rest of the quota day, whatever its own count says, and the
    reason is shown. On `rateLimited` the plugin additionally pauses every
    pull for a back-off (default 15 minutes) and does not retry within the
    run.
29. The meter, the limit, the reserve, the reset time (next UTC midnight) and
    the count per pull are shown on the settings page and returned by
    `wp newsapi-pull quota`.
30. The meter is stored per quota day. Days older than 30 are pruned.
31. A meter write failure does not block a run (fail open on bookkeeping),
    but it is logged; the API's own quota error remains the hard stop.
32. A per-pull daily cap (default empty) may further limit one pull.

### 4.5 Run sequence

33. A run takes a lock per site, not per pull, so two runs never overlap and
    the meter is never raced. The lock expires after 10 minutes (filterable)
    so a crashed run cannot block the site for good. A run that finds the lock
    held exits with reason `locked`, sends no request and is not an error.
34. Order of checks before the first request: kill switch, plan chosen,
    environment rule (4.2), API key present, pull valid (4.3), pull not
    paused, back-off not active, meter allows a request (4.4). The first
    check that fails ends the run with its reason; no request is sent.
35. The request goes to the documented HTTPS endpoint with the key in the
    `X-Api-Key` header, never in the query string, so the key never lands in
    a URL, a proxy log or the plugin's own log. TLS verification is on and
    cannot be turned off by setting.
36. A `User-Agent` names the plugin and its version and the site's home URL
    (filterable).
37. The request timeout is 15 seconds (filterable, 5 to 60).
38. The response must be JSON with `status` equal to `ok` and an `articles`
    array. Anything else is an error (4.10).
39. Articles are processed in the order the API returns them. For each
    article: normalise (4.6), check (4.7), and either create a draft (4.8) or
    reject with a reason.
40. Early stop: if every article on a page is already seen, the run does not
    fetch the next page.
41. Paging also stops at the max pages, when the API's `totalResults` is
    reached, or when a page returns fewer articles than the page size.
42. After the run, the pull stores: time, outcome, requests used, articles
    received, drafts created, rejections by reason. The last successful run
    time moves forward only when the run ended without an error, so a failed
    window is fetched again next time (case 18).
43. A run creates at most a filterable number of drafts (default 50), whatever
    the page size, so a misconfigured pull cannot flood the drafts list.

### 4.6 URL keys and de-duplication

Each article URL produces two keys, and a lookup matches if either key's hash
is already stored, whatever kind the stored row is. The hash is a fixed
length digest for compact equality lookup, not for security.

Before keys are computed:

44. The URL is trimmed and HTML entities are decoded exactly once.
45. A value that is not an absolute `http` or `https` URL with a host yields
    no key. The article is rejected with reason `bad_url` and nothing is
    stored for it (there is no key to store).
46. A public filter receives the URL first, so a redirect-unwrapping tool can
    return the publisher URL. A non-string or empty return means the original
    URL is used.
47. Aggregator redirect hosts (a filterable list, empty by default) are
    treated like case 45 when the filter in 46 returns them unchanged:
    rejected with reason `wrapper_url`, nothing stored.
48. User information in the URL is dropped and never stored.

The canonical key:

49. The scheme is not part of the key; `http` and `https` meet. Default ports
    are dropped, others kept.
50. The host is lower-cased, a trailing dot removed, and an internationalised
    host converted to its ASCII form when the `intl` extension is present.
51. Leading delivery labels are stripped one at a time while at least two
    labels remain: `www`, `www1` to `www3`, `m`, `mobile`, `amp`, `wap`,
    `touch`, `lite` (filterable). A bare two-label host is never reduced. An
    IP host is kept as is.
52. Language or regional subdomains (`hindi.example.com`, `en.example.com`)
    are not merged with the parent host. An edition in another language is a
    different article.
53. The path: empty becomes `/`; dot segments resolved; percent-encoding
    normalised (hex upper-cased, unreserved characters decoded, reserved ones
    kept); raw non-ASCII and its UTF-8 percent-encoded form meet; repeated
    slashes collapsed; trailing slash removed except at the root; a trailing
    `index.html`, `index.htm` or `index.php` removed; the path lower-cased
    (filterable per host for case-sensitive sites).
54. AMP path forms collapse to the article path: a leading, inner or trailing
    `amp` segment, a trailing `amp/1` pair, an `.amp` suffix, and `.amp.html`
    or `-amp.html` endings. A segment that only contains the letters "amp"
    (`/camp/`, `/amplifier`) is never touched.
55. The query: names compared lower-cased; tracking parameters dropped (every
    `utm_` name plus a filterable list including `fbclid`, `gclid`, `msclkid`,
    `igshid`, `mc_cid`, `mc_eid`, `_gl`, `ref`, `ref_src`, `source`, `src`,
    `from`, `cmpid`, `intcmp`, `ocid`, `smid`, `share`, `rss`, `feed`,
    `amp`); empty values dropped; array parameters dropped; on a slug path
    only id-bearing parameters survive (filterable list including `p`, `id`,
    `story`, `storyid`, `article`, `articleid`, `newsid`, `nid`, `sid`,
    `aid`, `pid`, `contentid`, `postid`); on a script endpoint (the root path
    or a path ending in `.php`, `.asp`, `.aspx`, `.jsp`, `.cfm`, `.cgi`,
    `.do`) every non-tracking parameter survives; survivors sorted by name;
    values keep their case; a repeated name keeps the last value; no `?` when
    nothing survives.
56. The fragment is dropped.
57. A final filter receives the canonical key and the URL and may return a
    different key; an empty return keeps the computed key.
58. The canonical key is deterministic across PHP versions, locales and
    requests, and carries a key version number. Changing any default rule
    above raises the version.

The plain key:

59. The URL trimmed, entities decoded once, scheme removed, one leading
    `www.` removed, fragment removed, one trailing slash removed from the
    path, the whole string lower-cased, query kept as written. It is frozen at
    release and never changes, so rows keep matching after canonical rule
    changes.

Matching:

60. A lookup computes both keys and matches if either hash is present. A
    match rejects the article with reason `seen`, and the run records a
    `duplicate` counter for the pull.
61. Within one run, an article whose keys match an article earlier in the
    same response or run is rejected as `seen` too (the API can return one
    article twice).
62. The site's own host (from the home and site URLs) is never recorded and an
    article pointing at it is rejected with reason `own_host`.
63. Lookups and records go through one de-duplication table with a unique
    index on the key hash. A concurrent insert of the same hash is not an
    error: the loser treats the article as seen, and if it had already
    created a draft, that draft is moved to the trash with a note in the log
    (this can only happen if the lock in 33 failed).

### 4.7 Article checks and rejections

Each rejection is counted per pull and per reason, and logged at debug level.
Every rejected article whose URL yields a key is recorded in the table with
its reason, so the next run does not look at it again (a rejected article
leaves a mark). Rejections that yield no key (`bad_url`, `wrapper_url`) are
counted only.

64. **Removed placeholder**: NewsAPI.org returns placeholder entries for
    articles withdrawn from its index, with the title `[Removed]` (and a
    placeholder URL). An article whose title is exactly `[Removed]`, or whose
    URL host is in a filterable placeholder host list, is rejected with
    reason `removed` and not recorded, because the placeholder URL is shared
    by unrelated entries.
65. **No title**: an empty or whitespace-only title is rejected with reason
    `no_title`.
66. **Source excluded**: a filterable deny list of source ids and of hosts
    (default empty); a match is rejected with reason `source_denied`.
67. **Language mismatch**: case 13.
68. **Too old**: an article whose `publishedAt` is older than the pull's
    maximum age (default 72 hours; on `developer` the free tier's documented
    24 hour delay means the default is raised to 96 hours) is rejected with
    reason `too_old`. A missing or unparseable `publishedAt` is not a
    rejection: the draft gets the fetch time and a flag in meta.
69. **Future date**: a `publishedAt` more than 1 hour in the future is
    clamped to the fetch time and flagged; not a rejection.
70. A public filter (`newsapi_pull_accept_article`) gets the normalised
    article and the pull and may return a rejection reason string; any
    non-empty string rejects with that reason (sanitised to a slug).

### 4.8 Draft creation

71. The draft title is the article title, decoded once, stripped of tags and
    control characters, whitespace collapsed. When the title ends with
    ` - <source name>` or ` | <source name>` matching the article's source
    name, that suffix is removed (it is restated in the attribution).
72. The draft body has three parts, in order: the article description as
    one paragraph (if present), an editor's note block, and the attribution
    block (74). The body never contains the API's truncated `content` field
    by default, because it is a fragment of copyrighted text cut mid-sentence
    with a character count marker; a setting can add it to the editor's note
    as a quoted reference, never to the main body.
73. The editor's note block says the item was imported for editorial review,
    names the pull, and is wrapped so it is easy to delete. Its text is
    filterable.
74. The attribution block holds: the publisher name (the article's
    `source.name`), the author when present, a link to the original article
    URL with `rel="nofollow noopener"` (filterable), the original publication
    date, and a credit line "News data from NewsAPI.org" linking to
    `https://newsapi.org`. The credit line is on by default. It can be turned
    off only on plan `paid`, and the setting says the operator must check
    their own terms first.
75. All text from the API is escaped for HTML before it goes into the body;
    no markup from the API is trusted. The final body passes through
    `wp_kses_post`.
76. The draft is inserted with status `draft` (or `pending` if the pull says
    so). The status is enforced at insert and checked again after insert; if
    any other code changed it to a published state during insert, the plugin
    sets it back to `draft` and logs a warning.
77. The post date is the article's `publishedAt` converted to the site
    timezone (or the fetch time, case 68), stored as the draft's date with
    the GMT date left unset, so publishing later still takes the publish
    time unless an editor sets one.
78. The excerpt is the description, trimmed to a filterable length (default
    300 characters) on a word boundary.
79. Categories, tags and author come from the pull (21). A tag with the
    source name is added when the pull says so (default off).
80. Post meta written on every draft (public names chosen by the builder,
    prefixed): original URL, publisher name, publisher source id (may be
    empty), author, published date as given, image URL as given, pull slug,
    fetch time, canonical key hash, and flags (date clamped, date missing).
81. The image URL is stored as meta only. It is not downloaded, sideloaded or
    hot-linked in the body by default. A filter lets a site sideload it,
    and the plugin then uses only the WordPress media functions, keeps the
    publisher name as the image caption, and treats any sideload failure as
    non-fatal for the draft.
82. The key is recorded in the table only after the draft exists (seen means
    imported). If the insert fails, nothing is recorded and the article is
    tried again next run.
83. Action `newsapi_pull_draft_created` fires with the post id, the
    normalised article and the pull, after meta and the record are written.
84. A filter `newsapi_pull_draft_args` may change the insert arguments, but
    the status rule in 76 is applied after it.

### 4.9 Source catalogue

85. The source catalogue (id, name, description, url, category, language,
    country for every source) is fetched from the documented sources
    endpoint and cached for 24 hours (filterable, at least 6 hours).
86. It is fetched only when needed: a headlines pull with a language (12), a
    language check (13), or the operator opening the source picker. Each
    fetch counts against the meter (24).
87. If the catalogue fetch fails, the previous cached copy is used if there
    is one, whatever its age, and the admin shows its age. Without any copy, a
    run that needs it ends with reason `catalogue_unavailable`; the language
    check in 13 then fails open.
88. The source picker in the pull editor lists the catalogue with filters by
    country, language and category, and enforces the limit of 20.
89. On save, a source id not in the catalogue gives a warning, not an error
    (the catalogue may lag); on run, the API's `sourceDoesNotExist` error
    disables that pull with the message shown (4.10).

### 4.10 Errors and failure modes

Default stance: fail closed on anything that would spend quota or create
content wrongly; fail open on bookkeeping that only loses a count.

90. HTTP error or `status: error` with a known code:
    - `apiKeyMissing`, `apiKeyInvalid`, `apiKeyDisabled`: every pull is
      paused, an admin notice names the code, nothing retries until the key
      is changed. Reason `key_error`.
    - `apiKeyExhausted`: case 28. Pulls resume on the next quota day.
    - `rateLimited` or HTTP 429: case 28, with back-off.
    - `parameterInvalid`, `parametersMissing`, `sourcesTooMany`,
      `sourceDoesNotExist`: this pull is disabled (not paused) with the
      API's message shown; other pulls carry on. Reason `pull_invalid`. The
      operator must edit and save it to re-enable.
    - `unexpectedError` or HTTP 5xx: the run ends with reason `api_error`;
      the next scheduled run tries again. After 5 consecutive failures the
      pull backs off to 4 times its interval until one run succeeds.
91. Any other code is treated like `api_error`, logged with the code and
    message (key redacted) so a new code can be added.
92. Network failure or timeout: reason `network`, retried at the next
    scheduled run. Counted against the meter (24).
93. A response that is not JSON, or JSON without the documented fields:
    reason `bad_response`; the first 200 bytes go to the debug log, never
    to the screen.
94. An article missing `url` or `title`, or with a non-string where a string
    is expected, is rejected singly (45, 65); the rest of the page is
    processed.
95. Database error on the de-duplication table: the run stops creating
    drafts for the rest of this run (fail closed, because without the table
    every article looks new), logs once, and reports `db_error`. No further
    queries are issued against the table in that request.
96. Draft insert error: that article is skipped and not recorded (82); the
    run continues; three insert errors in one run end the run with
    `insert_error`.
97. A run that hits PHP's time limit leaves its drafts and records intact,
    because each article is recorded right after its draft. The lock expires
    (33).
98. Every message shown to an admin, logged, or returned by WP-CLI passes
    through a redactor that replaces the API key with `[redacted]`.

### 4.11 Scheduling

99. Each active pull has its own WP-Cron event at its interval. Saving,
    pausing or deleting a pull reschedules or clears its event. An
    unscheduled active pull found on admin load is rescheduled (self-heal).
100. Scheduled runs never stack: if WP-Cron fires late and finds that the
     pull ran less than half an interval ago, it skips with reason
     `too_soon`.
101. Sites that disable WP-Cron call `wp newsapi-pull run --due` from system
     cron; it runs every pull that is due, one after another, under the lock.

### 4.12 Kill switch and multisite

102. A kill switch in the settings and a constant in `wp-config.php` stop every
     run before any request. The constant wins over the setting.
103. On multisite, each site has its own settings, pulls, meter and table.
     Network activation is supported. Sites sharing one API key share one
     NewsAPI.org quota that the plugin cannot see across sites; the meter
     page says so, and a network-level option may hold one shared meter
     (open question 3).

### 4.13 Uninstall and deactivation

104. Deactivation clears every scheduled event and the lock. Nothing else.
105. Uninstall removes the plugin's options, pulls, meter, log and the
     de-duplication table. It never deletes, trashes or modifies drafts or
     posts created by the plugin, nor their meta (they are the site's
     content). This is stated on the uninstall confirmation text in
     `readme.txt`.

## 5. Public interface

Names below are the public contract. The PHP namespace is
`IndianOpinion\NewsApiPull`; hooks, options and global functions use the
prefix `newsapi_pull_`. The builder chooses the internal structure.

Global functions (stable, never throw):

- `newsapi_pull_run( string $pull_slug, bool $manual = false ): array`:
  runs one pull; returns the run summary (outcome, reason, requests,
  received, created, rejections by reason, post ids).
- `newsapi_pull_is_seen( string $url ): bool`
- `newsapi_pull_keys( string $url ): array`: canonical key, plain key, their
  hashes and the key version; empty array for a non-URL.
- `newsapi_pull_quota(): array`: used, limit, reserve, remaining, reset time
  (UTC, ISO 8601), per-pull counts.

Classes the builder must expose (others are internal):

- `Client`: one method per endpoint (`headlines`, `everything`, `sources`),
  taking a validated query value object and returning a typed response or
  throwing a typed exception (`ApiException` with the code, `QuotaException`,
  `NetworkException`, `ResponseException`). HTTP goes through the WordPress
  HTTP API only.
- `PullDefinition`: immutable, built from an array, with `validate(): array`
  returning error messages keyed by field.
- `UrlKey`: pure functions for the two keys; no WordPress dependency, so it
  is unit tested on its own.

Filters:

- `newsapi_pull_enabled` (bool): master switch after the setting.
- `newsapi_pull_environment_allowed` (bool, string $env, string $plan): may
  only turn true into false (case 3).
- `newsapi_pull_countries` (string[]), `newsapi_pull_languages` (string[]),
  `newsapi_pull_categories` (string[]): allowed values.
- `newsapi_pull_request_args` (array, string $endpoint, PullDefinition):
  HTTP args; the key header, TLS verification and URL are re-applied after.
- `newsapi_pull_timeout` (int).
- `newsapi_pull_user_agent` (string).
- `newsapi_pull_lock_ttl` (int seconds).
- `newsapi_pull_url` (string $url, string $context): pre-processing (46).
- `newsapi_pull_wrapper_hosts`, `newsapi_pull_placeholder_hosts`,
  `newsapi_pull_mirror_labels`, `newsapi_pull_tracking_params`,
  `newsapi_pull_id_params`, `newsapi_pull_script_extensions` (string[]).
- `newsapi_pull_lowercase_path` (bool, string $host).
- `newsapi_pull_canonical_key` (string $key, string $url).
- `newsapi_pull_accept_article` (string $reason, array $article,
  PullDefinition): empty string accepts.
- `newsapi_pull_denied_sources` (string[]), `newsapi_pull_denied_hosts`
  (string[]).
- `newsapi_pull_draft_args` (array, array $article, PullDefinition).
- `newsapi_pull_draft_content` (string $html, array $article,
  PullDefinition): runs before `wp_kses_post`.
- `newsapi_pull_editor_note` (string).
- `newsapi_pull_attribution_html` (string, array $article): the credit line
  in 74 is re-appended if a filter removes it while the credit setting is on.
- `newsapi_pull_link_rel` (string).
- `newsapi_pull_sideload_image` (bool, array $article): default false.
- `newsapi_pull_max_drafts_per_run` (int).
- `newsapi_pull_window_overlap` (int seconds), `newsapi_pull_lookback`
  (int seconds).
- `newsapi_pull_catalogue_ttl` (int seconds).

Actions:

- `newsapi_pull_before_request` (string $endpoint, array $query, string
  $pull_slug): the query without the key.
- `newsapi_pull_draft_created` (int $post_id, array $article,
  PullDefinition).
- `newsapi_pull_article_rejected` (string $reason, array $article, string
  $pull_slug).
- `newsapi_pull_run_finished` (array $summary).
- `newsapi_pull_quota_exhausted` (string $source): `meter` or `api`.

WP-CLI (`wp newsapi-pull ...`):

- `list`: pulls with status, interval, last run, last outcome.
- `run <slug> [--dry-run]` and `run --due`. `--dry-run` sends the requests
  (they count against the meter, and the command says so before asking for
  confirmation unless `--yes`), shows what would be created and rejected,
  and writes nothing.
- `validate <slug>`: validation only, no request.
- `quota [--format=table|json]`.
- `check <url>`: both keys, hashes, version, and whether each is present.
- `sources [--country=] [--language=] [--category=] [--refresh]`.
- `log [--lines=<n>] [--pull=<slug>]`.
- `export` and `import <file>`: pulls as JSON (never the key).

Constants (optional, in `wp-config.php`):

- `NEWSAPI_PULL_API_KEY`: the key. When set, the settings field is
  read-only and shows only the last four characters.
- `NEWSAPI_PULL_DISABLED`: true is the kill switch (102).
- `NEWSAPI_PULL_PLAN`: `developer` or `paid`; overrides the setting.

Admin screens (capability `manage_options` for settings and pulls; anyone who
can `edit_posts` sees the drafts as normal posts):

- Tools, NewsAPI Pull: settings (plan, key, daily limit, reserve, credit
  line, kill switch, debug log), the quota meter, the pull list with Run now,
  Pause, Edit, Delete, and the run log.
- A pull editor with field-level validation messages and the source picker.
- A column and a filter in the posts list showing the publisher and pull for
  drafts made by the plugin.

## 6. Data

### 6.1 What is stored

- **Settings**: one option array, not autoloaded: plan, API key (only when no
  constant), daily limit, reserve percent, credit line on or off, include
  content snippet, kill switch, debug logging, catalogue TTL.
- **Pulls**: one option array, not autoloaded, keyed by slug, holding the
  fields in 4.3 plus last run, last successful run, consecutive failures,
  disabled reason, back-off until.
- **Meter**: one option array, not autoloaded, keyed by UTC date: total
  requests, per-pull requests, exhausted flag and source.
- **Catalogue**: a transient plus a non-expiring fallback option holding the
  last good copy and its fetch time.
- **Lock**: an option written with an atomic add, holding expiry time.
- **Run log**: a ring buffer option holding the last 200 run summaries
  (filterable), not autoloaded.
- **De-duplication table**: `{prefix}newsapi_pull_seen`. Per row: key hash
  (16-byte binary, unique index), key kind (canonical or plain), key version,
  post id (0 for a rejection), reason (`imported` or a rejection slug), pull
  slug, created time (UTC). No URL text is stored in the table; the URL is
  in post meta of the draft.
- **Post meta** on each draft (80).

### 6.2 Size

Two rows per article seen, about 60 bytes each with index. A pull that sees
1,000 articles a day adds roughly 120 KB a day before retention.

### 6.3 Retention

Rows older than a retention period (default 180 days, filterable, minimum
30) are pruned daily in batches of 1,000 by a daily event. Rows that point
at an existing post are pruned on the same rule; the post meta remains, so
`check` can still find the draft. The meter keeps 30 days (30), the run log
200 entries.

### 6.4 Uninstall

Case 105. The table is dropped, options and transients deleted, scheduled
events cleared. Posts and post meta are never touched.

## 7. Configuration and sensible defaults

| Setting | Default | Notes |
|---|---|---|
| Plan | none (must choose) | `developer` or `paid` (4.2) |
| API key | empty | constant preferred |
| Daily request limit | 100 on `developer`, empty on `paid` | 25 |
| Reserve | 10 percent, at least 1 | 26 |
| Credit line | on | off allowed only on `paid` (74) |
| Content snippet in note | off | 72 |
| Kill switch | off | 102 |
| Debug log | off | section 11 |
| Pull interval | 60 minutes, minimum 15 | 20 |
| Page size | 50, range 1 to 100 | 19 |
| Max pages per run | 1, range 1 to 5 | 19 |
| Max drafts per run | 50 | 43 |
| Max article age | 72 hours (96 on `developer`) | 68 |
| Search look-back | 2 days, 29 days cap on `developer` | 18 |
| Window overlap | 6 hours | 18 |
| Request timeout | 15 seconds | 37 |
| Lock TTL | 10 minutes | 33 |
| Catalogue TTL | 24 hours, minimum 6 | 85 |
| Seen-table retention | 180 days, minimum 30 | 6.3 |
| Draft status | `draft` | `pending` allowed, nothing else |

A fresh install with only a key and a plan runs nothing: there are no default
pulls. The settings page offers one example pull (headlines, category
`general`, paused) that the operator must review and enable.

The defaults are chosen so a `developer` key with one hourly pull uses 24 of
100 requests a day, leaving room for manual runs and the catalogue.

## 8. Security and privacy

- The API key is stored in a constant or in a non-autoloaded option. It is
  never printed in full on any screen, never sent to the browser, never put
  in a URL, and redacted from every log line and error message (98). The
  settings field is a password input that keeps the stored value when left
  blank.
- Requests go only to the NewsAPI.org API host over HTTPS with certificate
  verification. The host is a constant, not a setting, so a stored option
  cannot redirect the key elsewhere.
- Every admin action (save settings, save pull, run now, pause, delete,
  export, import) checks `manage_options` and a nonce. Run now is a POST.
- Every value from the API is untrusted: escaped before output, validated as
  a URL before it goes into an `href` (`esc_url` with `http` and `https` only),
  and the whole body filtered with `wp_kses_post`. A `javascript:` or `data:`
  URL in any field is dropped.
- No personal data about site visitors is collected or sent. The only data
  sent to NewsAPI.org is the query and the key. The article author name is
  public data from the publisher and is stored only in draft meta and the
  attribution block.
- Import of pull JSON validates every field as on save and ignores any key
  field.
- No `eval`, no shell, no file writes outside the media library (and only
  when sideloading is enabled).
- The plugin states in its README and settings page that the free tier is
  for development and testing only, that the operator must hold rights to
  republish anything they publish, and that a draft is a lead for an editor,
  not an article to publish as is. These are information for the operator,
  not legal advice.

## 9. Performance budget

- No work on front-end requests. The plugin loads its admin code only in the
  admin and its run code only from cron, WP-CLI or a manual run.
- One run: at most max-pages requests plus at most one catalogue request.
  Per article, one indexed lookup (both hashes in one query), one insert of
  the post, at most ten meta writes and one two-row insert.
- A run of 100 articles completes in under 5 seconds of PHP time on a
  typical host, excluding network time.
- Meter, pull state and run log are written once per run, not per article.
- No autoloaded options. The table lookup uses the unique index only.

## 10. Compatibility

- PHP 8.1, 8.2 and 8.3. `declare(strict_types=1)` in every file.
- WordPress 6.4 or later, single site and multisite.
- Runs as a normal plugin or as a must-use plugin through a loader file; the
  README documents the loader.
- `intl` is optional (case 50); without it internationalised hosts are
  lower-cased only.
- Works with any page cache: it adds nothing to front-end output.
- Works with object caches: the lock uses an atomic option add, not a
  transient, because some object caches make transients unreliable.
- Works with WP-Cron disabled when the system cron calls `run --due`.
- No Composer at runtime; no third-party PHP libraries.

## 11. Observability

- The settings page shows: plan and environment (with a red notice when the
  rule in 4.2 blocks runs), the quota meter as used of limit with reserve and
  reset time, and per pull: last run, outcome, drafts created, rejections by
  reason, next scheduled run, and any disabled or back-off state.
- The run log keeps the last 200 summaries and is shown on the page and by
  `wp newsapi-pull log`.
- With debug logging on, one line per run and one line per rejection go to
  the PHP error log, prefixed with the plugin slug, key redacted.
- Admin notices for: plan not chosen, environment block, key error, quota
  exhausted today, catalogue stale, a disabled pull. Each notice links to the
  fix.
- The `newsapi_pull_run_finished` action lets a site send summaries to its
  own monitoring.

## 12. Acceptance tests

Unit tests run without WordPress, against stubs in `tests/stubs/`. HTTP is
mocked; no test reaches NewsAPI.org. Test numbers map to cases in section 4.

Plan and environment:

- T1 (1): no plan chosen; a run sends no request and returns
  `plan_missing`.
- T2 (2): plan `developer`, environment `production`; no request,
  reason `plan_environment`, notice registered. Same for `staging`.
- T3 (2): plan `developer`, environment `local` and `development`; the run
  proceeds.
- T4 (3): a filter returning true on `production` with `developer` does not
  allow the run; a filter returning false on `local` blocks it.
- T5 (4): plan `paid` on `production` runs.
- T6 (5): `run` from WP-CLI and Run now obey T2.

Filter validation:

- T7 (6, 22, 23): a pull with an unknown endpoint, a bad slug or unknown
  fields; first two are errors, unknown fields dropped on save.
- T8 (7): country `IN` is normalised to `in`; `xx` is an error; country on
  `everything` is an error.
- T9 (8): category `politics` is an error; category on `everything` is an
  error.
- T10 (9): 21 sources is an error naming 21.
- T11 (10): headlines with sources and country is an error; with sources and
  category is an error.
- T12 (11): language `hi` is an error; `en` is accepted on `everything`.
- T13 (12): headlines with language `de` and no sources sends the `sources`
  parameter built from catalogue sources in German, no `country`, at most 20,
  and reports the number left out; with no matching sources the pull is
  invalid with `no_sources_for_language`.
- T14 (13): an article from a catalogue source in French on an `en` pull is
  rejected `language_mismatch`; one from a source absent from the catalogue
  is kept.
- T15 (14): `q` of 501 characters is an error; `everything` with no `q`,
  sources or domains is an error.
- T16 (15, 17): invalid `searchIn` and `sortBy` values are errors.
- T17 (16): `https://Example.com/path` becomes `example.com`;
  `not a host` is an error naming the entry.
- T18 (18): the `from` parameter equals last success minus overlap, bounded
  by look-back; on `developer` a 40-day look-back is capped at 29 days; after
  a failed run the window does not move.
- T19 (19, 20): page size 0 and 101, max pages 6, interval 10 are errors.
- T20 (21): status `publish` is refused on save; a stored `publish` is
  written as `draft`.

Quota meter:

- T21 (24): a request that times out is counted; a catalogue request is
  counted.
- T22 (25): default limit 100 on `developer`, none on `paid`.
- T23 (26): with limit 100 and reserve 10, a scheduled run at 90 used sends
  nothing (`quota`); a manual run at 90 sends; anything at 100 sends nothing.
- T24 (27): a 3-page run with 1 request left sends 1 and keeps its drafts.
- T25 (28): an `apiKeyExhausted` response sets the meter full for the day;
  a 429 also sets a back-off and no retry happens in the run.
- T26 (29): `newsapi_pull_quota()` returns used, limit, reserve, remaining
  and the next UTC midnight.
- T27 (30): meter days older than 30 are pruned.
- T28 (31): a failing meter write does not stop the run and is logged.
- T29 (32): a per-pull cap of 2 stops that pull at 2 while another runs.

Run sequence:

- T30 (33): a second run while the lock is held returns `locked` and sends
  nothing; an expired lock is taken over.
- T31 (34): each pre-check in order produces its own reason with zero
  requests.
- T32 (35): the key is in the `X-Api-Key` header and absent from the URL;
  `sslverify` is true even when a filter sets it false.
- T33 (36, 37): user agent and timeout are applied; a timeout of 120 is
  clamped to 60.
- T34 (38): `status: ok` without `articles` is `bad_response`.
- T35 (39): articles are processed in API order.
- T36 (40): a page of all-seen articles stops paging.
- T37 (41): paging stops at `totalResults`, at a short page, and at max
  pages.
- T38 (42): the run summary is stored with counts by reason.
- T39 (43): 80 new articles with a cap of 50 create 50 drafts; the other 30
  are not recorded and arrive next run.

URL keys:

- T40 (44, 45): `&amp;` decoded once; `ftp://x`, `example.com/a` and
  `https:///a` give no key and reason `bad_url`.
- T41 (46): a filter returning the publisher URL changes both keys; a filter
  returning null is ignored.
- T42 (47): a wrapper host returned unchanged is rejected `wrapper_url`
  with no row stored.
- T43 (48): `https://u:p@example.com/a` keys as `example.com/a`.
- T44 (49, 50): `http` and `https`, `:443`, upper-case host and trailing dot
  meet; `:8080` differs; a Unicode host meets its punycode form with `intl`.
- T45 (51, 52): `www.`, `m.`, `amp.` and `www.m.` forms meet; `m.com` stays;
  an IP host is kept; `hindi.example.com` does not meet `example.com`.
- T46 (53): `/a//b/`, `/a/./b`, `/A/B/index.html` and `/a/b` meet;
  `/a%2Fb` and `/a/b` differ; `%7E` and `~` meet; raw and encoded UTF-8
  paths meet; the case filter keeps `/A` distinct for one host.
- T47 (54): each AMP form meets the article path; `/camp/` and
  `/amplifier` are unchanged.
- T48 (55): tracking parameters dropped; `?b=2&a=1` meets `?a=1&b=2` on a
  script endpoint; `/story?page=2` keys as `/story`; `/news?id=42` and
  `/news?id=43` differ; `/?p=1` and `/?p=2` differ; `?id=1&id=2` keeps 2.
- T49 (56, 57, 58): fragments dropped; the canonical filter replaces the key;
  an empty return keeps it; the same input gives the same key under two
  locales; the version is present.
- T50 (59): the plain key for a fixed list of 20 URLs matches a frozen fixture
  file exactly.
- T51 (60): a row stored under a canonical key of an older version still
  matches through the plain key.
- T52 (61): the same URL twice in one response creates one draft.
- T53 (62): an article on the site's own host is rejected `own_host` and not
  recorded.
- T54 (63): a duplicate-key insert error is treated as seen, and a draft made
  in that race is trashed and logged.

Article checks:

- T55 (64): title `[Removed]` and a placeholder host are rejected `removed`
  and not recorded.
- T56 (65): an empty title is rejected `no_title` and recorded.
- T57 (66): a denied source id and a denied host are rejected
  `source_denied`.
- T58 (67): see T14; the rejection is recorded so the next run skips it.
- T59 (68): a 100-hour-old article on `paid` is `too_old`; a 90-hour-old one
  on `developer` is kept; a missing date gives the fetch time and the flag.
- T60 (69): a date 2 hours ahead is clamped and flagged.
- T61 (70): the accept filter returning `off topic` rejects with
  `off-topic`.

Drafts:

- T62 (71): `Title - Publisher` with source name `Publisher` becomes `Title`;
  `Title - Other` is unchanged; tags and control characters are removed.
- T63 (72): the body holds the description, the note and the attribution in
  that order; the `content` field is absent; with the snippet setting on it
  appears only inside the note.
- T64 (73): the note filter changes the note text.
- T65 (74): the attribution holds the publisher, author, link with the rel
  value, date and the credit line; on `developer` the credit cannot be turned
  off; on `paid` it can.
- T66 (75): `<script>` in the description and `javascript:` in the URL are
  neutralised; the href is never a `javascript:` URL.
- T67 (76): a `wp_insert_post_data` callback that forces `publish` ends with
  a `draft` and a warning in the log.
- T68 (77): the post date is the publish time in the site timezone and the
  GMT date is unset.
- T69 (78): the excerpt is trimmed to 300 characters on a word boundary.
- T70 (79): pull categories, tags and author are applied.
- T71 (80): every listed meta key is written.
- T72 (81): the image is not downloaded by default; with the filter on, a
  sideload failure still leaves the draft.
- T73 (82): a failed insert records nothing; the next run creates the draft.
- T74 (83, 84): the created action fires once with the post id; a draft-args
  filter setting `publish` is overridden.

Catalogue:

- T75 (85, 86): the catalogue is fetched once in 24 hours and counted; a
  pull with no language does not fetch it.
- T76 (87): a failed fetch uses the stale copy and reports its age; no copy
  gives `catalogue_unavailable` for a language headlines pull.
- T77 (88, 89): the picker refuses a 21st source; an unknown id on save is a
  warning; `sourceDoesNotExist` on run disables the pull.

Errors:

- T78 (90): each documented error code produces the stated pause, disable,
  full meter or retry, and the reason.
- T79 (90): after 5 consecutive `api_error` runs the pull backs off to 4
  times its interval; one success resets it.
- T80 (91): an unknown code is `api_error` and logged with code and message.
- T81 (92): a network error is `network` and counted.
- T82 (93): an HTML error page is `bad_response`; at most 200 bytes reach the
  debug log.
- T83 (94): one article with a numeric title is rejected; the others become
  drafts.
- T84 (95): a database error on the table stops draft creation for the run,
  logs once, and issues no further table query.
- T85 (96): three insert errors end the run with `insert_error`.
- T86 (97): an exception thrown after the 3rd of 5 drafts leaves 3 drafts and
  3 records.
- T87 (98): a key placed in an API error message, a log line and a WP-CLI
  error is shown as `[redacted]`.

Scheduling and lifecycle:

- T88 (99): saving a pull schedules it; pausing clears it; a missing event is
  restored on admin load.
- T89 (100): a run due again within half an interval is `too_soon`.
- T90 (101): `run --due` runs only due pulls, one at a time.
- T91 (102): the constant stops runs even when the setting is off.
- T92 (103): on multisite, two sites keep separate meters and tables.
- T93 (104): deactivation clears events and the lock.
- T94 (105): uninstall drops the table and options and leaves every draft and
  its meta in place.

Security:

- T95 (section 8): each admin action without the capability or with a bad nonce is
  refused and changes nothing.
- T96 (section 8): the stored key is never present in rendered admin HTML; the field
  shows the last four characters only.
- T97 (section 8): import of a pull JSON containing a key field ignores it.

## 13. Open questions

To be answered by the builder in `docs/ARCHITECTURE.md`.

1. Does the headlines endpoint still accept countries beyond those its page
   lists? The current documentation shows a short list while the sources
   endpoint lists many. Decide whether the country list should default to
   the short list, and record what was verified and when.
2. The free tier may limit how many results a search can page through. The
   public documentation pages read for this PRD do not state it. If the API
   returns a documented error code for it, map it to `pull_invalid` or to a
   clean stop of paging, and add it to 90.
3. On multisite with one shared key, should there be an optional network
   meter shared by every site (one quota, one count), and is a network-level
   lock needed for it?
4. The terms read for this PRD forbid misrepresenting or removing the source
   of material but give no exact credit wording. Confirm the current
   attribution requirement for the free tier on the public site and adjust
   the default credit line text in 74 if it names one.
5. Should the reserve in 26 be a percentage or an absolute number? Pick one
   and explain.
6. Is there a documented response header for remaining quota? If so, use it
   to correct the local meter; if not, record that the meter is local only.

## 14. Lessons from production, written as rules

1. Seen means imported. Record a URL only after the draft exists; a record
   written before the insert loses the article for good when the insert
   fails.
2. Every unit of work leaves a mark. A duplicate or rejected item that writes
   nothing gets looked at again on every run, and a queue full of them stops
   moving.
3. One article has many URLs. Mobile hosts, AMP hosts and paths, tracking
   queries, trailing slashes and fragments split one story into several
   drafts unless the key collapses them.
4. Never strip the query blindly. On a script endpoint the query is the page;
   only on slug paths can non-id parameters go.
5. When the key rules change, keep matching the old key. Keep a frozen plain
   key beside the canonical one, or every article in the table looks new the
   day the rules change.
6. A language edition is a different article. Do not merge language
   subdomains into the parent host, and check the language of what arrives,
   not only what was asked for.
7. Leave redirect wrappers to an unwrap tool. Keying an aggregator's
   redirect link gives a key no direct copy will ever match, and credits the
   story to the aggregator.
8. Never record your own host. A fallback to a local guid or permalink
   poisons the table and mis-credits the item to the site itself.
9. Count every request before you send it. The provider's quota counts what
   it received, not what succeeded, and a meter that counts only successes
   runs out early without warning.
10. The provider's quota error is the final word. When the API says the
    quota is gone, stop for the day, whatever your own count says.
11. Respect the plan. A free key belongs on a development box; refuse to run
    it anywhere else rather than hope nobody notices.
12. Keep the source on every draft. The publisher name, the original link
    and the data provider's credit go in the draft from the start, so an
    editor cannot lose them by accident.
13. Nothing publishes on its own. Enforce draft status after every filter
    and after the insert, because other code can change it.
14. Write state once per run, not once per item. Per-item option writes turn
    a busy run into a write storm.
15. Back off after the first database error; do not issue one failing query
    per article.
16. Keep keys out of URLs and logs. A key in a query string ends up in proxy
    logs, error pages and screenshots.
