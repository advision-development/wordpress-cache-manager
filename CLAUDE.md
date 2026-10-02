# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

**CacheManager**, a WordPress plugin (directory and repository
`wordpress-cache-manager`, prefix `ADVCM_` / `advcm`). It clears a site's cache layers in a fixed
order and reports each step. It is its own system: it shares no code at runtime with the
scanners (`wordpress-malware-quick-scan`, `wordpress-access-quick-scan`) or with
`trending-now-plugin`. Code copied from them is copied, renamed and owned here.

The design spec lives in the team's internal workspace, not in this repository; read it before
changing the pipeline.

```text
wordpress-cache-manager/   the plugin; this directory is what the zip contains
tests/                     CLI harnesses, no WordPress install needed
build.sh                   lint, PHP 7.4 gate, tests, zip
.github/workflows/         ci.yml (7.4, 8.3, 8.4), release.yml (on a v* tag)
```

## Commands

```bash
./tests/docker.sh          # the suite on PHP 7.4 and 8.3
./tests/docker.sh build    # build.sh inside a container, to see the zip
```

**Use Docker, not a local PHP.** Do not install PHP on the machine to run these; `docker.sh` is
the way. `run.sh` and `build.sh` are what CI calls, with PHP on its PATH.

`./tests/smoke.sh` (and `PHP=7.4 ./tests/smoke.sh`) runs the plugin in a real WordPress with
Elementor and WordPress Malware Quick Scan from its public release. Run it before a release.

**Every guard here was mutation-tested** when it was written: break it, watch its suite fail.
Counting `FAIL` lines is not enough — the `Throwable` guard's mutation fails by taking the whole
suite down with a fatal (exit 255), which prints no `FAIL` at all. Read the exit code.

## Rules for the pipeline

These are requirements, not preferences. Each was either asked for explicitly or measured.

- **The order is fixed by stage number**, never by an adapter or a caller: object cache, builder
  CSS, asset files, page cache plugin, NitroPack, host cache, warm-up, CDN. A caller asking for
  some layers gets them in stage order. **NitroPack is before the host cache** because its drop-in
  answers from PHP, behind WP Engine's Varnish — read from NitroPack's code, and the first version
  had it the other way round.
- **Nothing this plugin hooks may break a page.** Every `add_action`/`add_filter` goes through
  `ADVCM_Safe` (a test fails on one that does not), every catch is `Throwable`, the bootstrap is
  inside a try, and the screen renders each section on its own. This was asked for explicitly:
  installing, running or using it must never produce a white screen or an error on the site.
- **No pause from builder CSS through the host cache.** Pauses belong after the object cache and
  before the CDN; `ADVCM_Modes` refuses any other placement, filtered or not.
- **Background work is WP-Cron**, which is core and on every site. Not Action Scheduler: it is a
  library some plugins bundle, not something a site from zero has.
- **Everything written to a site is named `advcm`**, and `footprint.json` lists all of it; both
  are tests. Never put exceptions for a scanner inside this plugin — a site that tells a scanner
  what not to look at is what a compromised site does. Exceptions belong in the scanner or in
  Hawkeye, anchored on the release checksums.
- **Detect at run time.** An adapter's `detect()` runs right before its stage, not from a stored
  inventory. A plugin removed since the last inventory is `skipped: not installed`.
- **Preflight before the first call**: which stages will run, in what order, which are skipped
  and why. The run re-checks, because the site can change in between.
- **Every call to another plugin is inside `try { } catch ( \Throwable $e )`.** `Throwable`, not
  `Exception`: calling a function of a plugin that is gone is an `Error`.
- **A failed or missing stage never stops the others**, with one exception: a builder
  regeneration that fails part-way holds the page-cache stages, because purging them over half
  rebuilt CSS makes NitroPack and the host cache store unstyled pages.
- **Every stage reports**: `ok`, `skipped: <why>`, `failed: <message>`, `partial: n of m`, with
  milliseconds. A job is `done`, `done with skips` or `done with failures` — never a bare success.
- **Never change another plugin's or the host's configuration.** Read it, report it, purge
  through its own API. Turning settings on or off is a human decision in that vendor's panel.
- **Bricks CSS is not regenerated** in v1. Its `regenerate_css_file()` deletes every CSS file at
  index 0 and the front end silently drops a missing file, so a run that dies part-way leaves
  pages unstyled. Report its state instead.
- **NitroPack: use the SDK functions** (`nitropack_sdk_invalidate()`, `nitropack_sdk_purge()`),
  not `nitropack_purge()` / `nitropack_invalidate()`, which also invalidate the home page and
  every archive on each call. A full-site default is invalidate; a full purge re-queues every page.

- **Auto-clear never runs in the save request.** `wp_after_insert_post` only marks a rule
  (`add_option`, so a burst is one mark) and schedules one event a minute out; the clear is a
  per-URL job in WP-Cron. Not `transition_post_status`: REST — the block editor — sets terms after
  it, so a rule on a category would miss every Gutenberg post. A rule names this site's URLs only,
  re-checked when it runs, and never the whole site.
- **Only the rules form writes `advcm_rules`.** Anything the cron learns about a rule — its last
  clear — goes to `advcm_rules_last`, and the pause to `advcm_rules_paused`. A cron writing the
  rules row while an administrator edits it would put back a rule they had just deleted. Both are
  tests.
- **The save hook's cost is an assertion**: with no rule on, exactly one option read
  (`advcm_rules`); the pause is read only after a rule is on and the save is a visible one. A new
  check goes after those two, not before.
- **"Any content" never covers revisions, auto-drafts or menu items**, whatever
  `advcm_auto_excluded_post_types` returns, and a filter answer that is not a list of post type
  names is ignored — a lone `*` would otherwise switch every such rule off silently.

## Security rules

From the adversarial review of 2026-10-01; each has a test or a workflow check.

- **Releases install themselves on every site**, so the release path is the attack surface: the
  workflow builds only a tag on `main`, pins every action to a commit, keeps `persist-credentials`
  off, builds read-only, and publishes from a separate job. **Still the owner's to set in GitHub:**
  a ruleset limiting who may create `v*` tags, and a protected `release` environment with required
  reviewers on the publish job. Signing the zip is the next step beyond those.
- **The package URL must match the release's exact shape** (`/releases/download/v<ver>/<repo>-<ver>.zip`,
  same tag as the release). A literal `..` check was bypassed by `%2e%2e`.
- **Nothing reachable logged out.** admin-post and `wp_ajax_` only, each with capability and nonce.
- **Editors cannot choose layers.** Builder CSS alone would leave every page cache pointing at
  deleted CSS. Administrators can, and a builder layer always brings the page-storing layers.
- **Rate limits do not live in the job history**, which a burst of small clears can empty:
  `advcm_site_wide_at` claimed with `add_option`, and 10 page clears a minute per person.
- **No file in the plugin directory may identify the fleet.** It is served from every site;
  `footprint.json` lives in the repository and the build excludes it.
- **Admin-bar JavaScript is a listener, never `meta.onclick`**: the admin bar runs `onclick`
  through `esc_js()`, which broke the confirmation into a syntax error.
- **Auto-clear rules are written by administrators only** (`advcm_purge_hard`), through an
  admin-post action with a nonce. A rule is an instruction the site carries out on every publish,
  so it is held to the same bar as the costly options.
- **HTTP goes through WordPress's API** (`wp_remote_*`), never `Requests` directly, so a site's
  HTTP policy applies.

## Conventions

Same as the scanners: **Conventional Commits** with a lower-case subject that names the symptom,
the body carries the why, **no trailers**. Branches `type/kebab-case`, through a pull request.
**PHP 7.4 floor** — `build.sh` refuses PHP 8-only syntax and CI runs the suite on 7.4.
**Comments explain why**, especially where something looks wrong.

## Releasing

Bump the header `Version:` and `ADVCM_VERSION` together, CHANGELOG entry, merge, push the tag.
The workflow does the rest. **Push the tag and stop.**
