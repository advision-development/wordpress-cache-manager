# Advision Cache Management

A WordPress plugin that clears every cache layer a site has, **in the right order**, and reports
each step on its own. Editors can use it from wp-admin; the Advision Hawkeye console can ask for
it remotely (later phases).

The design spec lives in the team's internal workspace, not in this public repository.

## Status

**0.2.4.** Tools → CacheManager, in four tabs — Status, Clear, Cache age, History — and the
CacheManager menu on the admin bar. Status lists only the layers a clear runs on this site, checked each
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
the exact shape of this repository's releases. The two controls left to the repository's settings
— who may create `v*` tags, and a required review before publishing — are in `CLAUDE.md`.

## Build, test, release

```bash
./tests/docker.sh         # the suite on PHP 7.4 and 8.3, in containers
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
