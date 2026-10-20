# CacheManager

A WordPress plugin that clears every cache layer a site has, **in the right order**, and reports
each step on its own. Editors can use it from wp-admin; the Advision Hawkeye console can ask for
it remotely (later phases).

The design spec lives in the team's internal workspace, not in this public repository.

## Status

**0.5.1.** Tools → CacheManager, in seven tabs — Status, Clear, Cache age, History, Freshness,
Auto-clear, Settings — each with a box of its last changes, and the CacheManager menu on the admin bar. Status lists only the layers a clear runs on this site, checked each
time the screen opens: install a cache plugin later and it appears on the next load. Talking to Hawkeye is a later phase.

## The order

Clear from the source outward. Every layer rebuilds from the one beneath it, so clearing an
outer layer first lets it re-cache stale content straight away.

| # | Stage | Today |
|---|---|---|
| 1 | Object cache | `wp_cache_flush()`, or the posts' caches per URL |
| 2 | Builder CSS | Elementor (site-wide, its own tool's operation). Bricks: reported, never regenerated |
| 3 | Asset files | WP Rocket's minified files |
| 4 | Page cache plugin | WP Rocket |
| 5 | NitroPack | inside the host cache: its drop-in answers from PHP. Invalidated site-wide, purged per URL |
| 6 | Host page cache | WP Engine, by path |
| 7 | Warm-up | the cleared pages, as desktop and mobile |
| 8 | CDN | Cloudflare, from Hawkeye only — later |

NitroPack before the host cache is measured, not assumed: on WP Engine its `advanced-cache.php`
answers from PHP behind Varnish, and its own WP Engine integration purges Varnish after it.

A layer that is not installed is **skipped and reported**, never a reason to stop. If builder CSS
fails part-way, everything after it is **held**, so no cache stores half-styled pages.

## Modes

| Mode | Runs | Pauses | Warm-up | Recommended for |
|---|---|---|---|---|
| Fast | in the request | none | the pages asked for, or the home page | a few pages |
| Balanced | in the background | 2 min after the object cache, 1 min before the CDN | home + 20 most recently updated | the whole site |
| Careful | in the background | 5 min and 3 min | home + 100, five at a time, spaced | large sites, busy hours |

Never a pause from builder CSS through the host cache: once builder CSS is deleted, a cached page
points at files that are gone. Background modes run on WP-Cron, which is WordPress core; with
`DISABLE_WP_CRON` only Fast is offered. A background job that stops moving is shown as stuck, with
a button to finish it now.

## How old is a page's cache

Tools → CacheManager → *Cache age*: the site requests the page once and reads what
each layer says — Cloudflare, the host cache, NitroPack. The age is exact where a layer sends
`Age`, 0 on a `MISS`, "at most N" since this plugin last cleared it when a layer only says `HIT`
(WP Engine sends no `Age`), and otherwise "unknown" with the most it can be. It never guesses.

Since 0.5.0 it also reads WP Engine's `x-cache-ctime` (the time the host cache stored the copy,
so the age is exact there), the `NITROPACK_STATE` NitroPack writes into the page (`FRESH` or
`STALE`), and the time of NitroPack's own copy of the page, from the file its SDK names. Each only
where the site has that layer: no row is shown for a layer a site does not have.

## Freshness

A page can look old because a cache kept an old copy, or because the page has nothing new to show.
A clear fixes the first and does nothing for the second. The *Freshness* tab tells them apart.

- **Listings with nothing new.** Elementor `posts`, `loop-grid` and `loop-carousel` widgets that
  filter by category or tag, on every published page and template. For each, the newest post the
  filter matches, queried as Elementor does (the filter is stored as term_taxonomy_ids; IN within a
  taxonomy, AND across). Red over 30 days, or a filter that can match nothing (a deleted term, a
  taxonomy the post type lacks); amber from 7 days. A custom query id is listed, not checked. Found
  on BMR: the Alabama news carousel showed the same posts for ten months because no post had been
  tagged `alabama` since 2025-12-31 — no clear could fix that.
  A person starts the scan; it runs in WP-Cron, 20 documents within 8 seconds per run, and changes
  nothing. One scan at a time. Where WP-Cron cannot wake, the open tab moves it 5 documents at a
  time once it is 2 minutes late. A site without Elementor finishes at once with nothing to check.
- **Cache age at clear.** Before a clear of some pages, each page is asked once how old its cached
  copy is, and the answer kept: the age a visitor would have got, each layer's own, and NitroPack's
  state. At most 10 pages per clear, 5 seconds each, 20 seconds in all; never for a whole-site
  clear. It is not a stage, and nothing it does can stop the clear. Switch it off in Settings.
- **What it adds up to** (since 0.5.1). Measurements are summed per page (200 pages) and per day
  (30 days), and the tab leads with them: the last 30 days against the cache target in Settings
  (3 hours by default); pages kept past the target, with "no rule clears it — add one"; pages
  cleared while fresh (three clears in four under 10 minutes) and the rules that clear them —
  the content did not change, or a rule fires more than the page does; and pages cleared by hand
  often. Listings are labelled by what they mean, the same list in one template once with ×N.

Freshness only shows: it never clears a page, adds a rule or changes a setting; its links open
the tab where a person decides. An "at most" age is a bound, not an age, and never counts as past
the target. Days are the last 30 calendar days.

Every tab starts with what it is for and a folded "How this tab works": when to use it, how it
works underneath, what each column, badge and word means, and its limits.

## Auto-clear: listing pages refresh themselves

A page that lists posts — a category page, a Page built from a query like `/analysis/` — is not
refreshed by NitroPack when a new post appears on it: NitroPack invalidates the pages that rendered
a post, and the listing never rendered the new one. Tools → CacheManager → *Auto-clear* holds rules:
**a post type (or any content), optionally a term, and up to ten of this site's URLs**. When a
matching post is published, updated while published, or taken down, its URLs are cleared about a
minute later by WP-Cron, as one per-URL job with every post saved in between: object cache,
NitroPack (purged, as a new rule recommends, or invalidated), the host cache, a warm-up. Nothing
runs in the save; with no rule switched on the hook costs one option read.

**A cron that is behind.** WP-Cron runs due events oldest first, so on a site where something
else has flooded it a clear a minute out waits behind all of them (on BMR, 8.5 hours). When cron's
oldest due event is more than 2 minutes late, the clear is scheduled just before it instead and
runs first. *Status* says how far behind cron is, and while the screen is open it runs a clear that
is still a minute past due.

**No warm-up after an invalidate.** An invalidated NitroPack page keeps answering with its old
copy while it rebuilds, so requesting it straight away has the host cache store that copy in front
of NitroPack: on BMR, `/analysis/` stayed old for 18 hours that way. A job that invalidated
NitroPack skips its warm-up and says why; a warm-up that NitroPack answers with `STALE` is
`partial`, never `ok`.

- **Any content** covers every post type a visitor can view, less media, menus, reusable blocks,
  theme and page-builder templates and form or snippet plugins' records; the
  `advcm_auto_excluded_post_types` filter changes that list, never to let revisions in.
- **A term in a hierarchical taxonomy includes its children**: a rule on the category `news`
  fires for a post filed only under `news-local`, as the `news` archive lists it.
- **History says what triggered each clear**: every rule in words, and up to ten posts per rule —
  title, ID, type, and whether it was published, updated or unpublished — with a count of the
  rest. The rules table shows each rule's last clear.
- **History says how long**: each clear shows how long it *took*, and how long it *waited*: an
  automatic one from the first save that set it off, one somebody asked for from the request to its
  first step (a Fast clear runs in the request and shows no wait; a background one WP-Cron ran late
  shows how late).

## Settings

Every setting a person may change is on the *Settings* tab:

| Setting | Default | |
|---|---|---|
| Pause auto-clear | off | Stops every rule and each post's own page at once; each rule keeps its own on or off for the resume. |
| Each post's own page | off | A published, updated or unpublished post's own URL goes into the same clear, purging NitroPack, so an edit shows without anybody clearing it by hand (on BMR, most manual clears were exactly that). Kept as the reserved rule `own-page` in the rules row, so a save still costs one option read. |
| Own page ignores saves that change nothing | on | A save that leaves title, content, excerpt, slug, status, password, date, parent and order as they were does not clear the post's page (on BMR, something saved seven review pages every hour unchanged). A change only to custom fields or a page builder's own data reads as unchanged; clearing by hand still works. Listing rules always fire. |
| Post types auto-clear leaves out | media, page-builder templates | For each post's own page and for rules on "any content". Revisions, auto-drafts, menu items and form, snippet and field-group records are always left out. |
| NitroPack for new rules | purge | The choice the add-rule form starts with. |
| Longest a page should stay cached | 3 hours | Changes no cache: what the Freshness tab counts as past the target. |
| Cache age before a clear | on | Before clearing some pages, ask each how old its cached copy is (at most 10 pages, 20 seconds), for the Freshness tab. |

Not settings, on purpose: the plugin's auto-update, the mode timings, who may use the plugin, rate
limits and storage caps. They are filters and constants; a checkbox for them is a way to break a
site or the release path.

## Who can do what

Everyone who can clear the cache — editors (`edit_others_posts`) and administrators — can use every
tab and every action: clear pages or the whole site, purge NitroPack in full, override a builder
hold, add, switch and delete rules, and change every setting. The change log is the record of who
did it. A site can take the costly actions, rules and settings back from editors with the
`advcm_purge_hard_base_capability` filter. Choosing which layers a clear runs has no control on any
screen and stays with administrators (`manage_options`). The *Check for updates* button needs
`update_plugins`.

## Change log

Each tab ends with its **last changes**: who, when (UTC), what, and the value before → after.
Recorded: clears people started, rule changes, setting changes and the pause, stuck jobs finished
by hand, release checks, Cache age probes (a reload within ten minutes is not recorded again), and
updates of this plugin (as nobody's: WordPress installs them on its own). Refusals, WP-Cron and
automatic clears are not: History has those. Kept in `advcm_log`, 20 per tab, 10 shown, each value
cut to 200 characters.

## History

Two tables: **Clears** (started by somebody, or WP-CLI) and **Automatic clears** (auto-clear rules
and each post's own page), the last 20 of each, in separate rows so a busy day of publishing never
pushes out a clear somebody pressed. A running clear is never dropped. Columns: when (UTC and how
long ago), who, what (the whole site, or the first two pages and how many more), mode, state,
how long it waited (amber over ten minutes, red over an hour), how long it took, and the oldest
cache age its pages had. Each row opens to its steps, every page with its cache age, and what set
it off.

Nothing grows past its cap, none of it autoloaded: about 100 KB per History list, 30 KB of change
log, and at most about 250 KB for each of the two measurement rows (`advcm_freshness`,
`advcm_freshness_auto`, 100 pages each) and 300 KB for the listings scan (`advcm_listings`, 300
findings) — typically a tenth of that.

## Nothing it does can break a page

Every hook goes through `ADVCM_Safe`: a filter that throws returns what it was given, an action
that throws stops there, the screen prints a notice instead of a white page, and every fault goes
to the PHP error log and to the screen. Every catch is `Throwable`. On a PHP below 7.4, or if a
file fails while it loads, the plugin does not register at all and says so to administrators.

## What it leaves on a site

`FOOTPRINT.md` and `footprint.json`, in this repository and not in the plugin: every option,
transient, cron event, capability and request, all prefixed `advcm`. Each release also publishes
`wordpress-cache-manager-<version>.sha256`, one checksum per file, so a scanner can tell an
unmodified install from anything else.

## Security

Reviewed adversarially on 2026-10-01 from seven positions (anonymous, subscriber, editor, site
content, network, release publisher, another plugin). Nothing is reachable without logging in:
no REST routes, no `wp_ajax_nopriv_`. Releases are built only from commits on `main`, by a
read-only job with actions pinned to commits; publishing is a separate job. Package URLs must have
the exact shape of this repository's releases.

Reviewed again on 2026-10-20: no backdoor, obfuscation or injection. 0.5.1 added the plugin-side
fixes — limits on whole-site and site-wide page clears, display names instead of logins in the
change log, WordPress's own endpoints refused as pages, posted admin-bar clears, path-free errors.

**The real risk is the release path, and it is in GitHub, not in the code.** The plugin installs
its own releases on every site, so anyone who can publish a release here can run code on all of
them. That is guarded only by the repository's settings: a ruleset limiting who may create `v*`
tags, a protected `main` with required review, immutable releases, a `release` environment with
required reviewers, SHA-pinned actions and 2FA. Until those are set, any account with write
access — or a token stolen from one — is enough. Signing the zip with a key held outside GitHub,
verified by the plugin, is the step that removes GitHub from the trust.

## Build, test, release

```bash
./tests/docker.sh         # the suite on PHP 7.4, 8.3 and 8.4, in containers
./tests/docker.sh build   # build.sh: lint, PHP 7.4 syntax gate, tests, dist/wordpress-cache-manager-<version>.zip
./tests/run.sh            # the suite on whatever php is on PATH, which is what CI calls
./tests/smoke.sh          # a real WordPress in containers: every mode, a layer removed, every page
                          # still answering, and WordPress Malware Quick Scan run against it
PHP=7.4 ./tests/smoke.sh  # the same on the floor the header claims
```

Releasing: bump `Version:` in the header **and** `ADVCM_VERSION`, add the CHANGELOG entry, merge,
then push a `v<version>` tag. The workflow checks the tag against both strings, builds and
publishes the release. **Push the tag and stop** — creating the release by hand makes the
workflow fail on a release that already exists.
