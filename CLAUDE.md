# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

**Advision Cache Management**, a WordPress plugin (directory and repository
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
.github/workflows/         ci.yml (7.4 + 8.3), release.yml (on a v* tag)
```

## Commands

```bash
./tests/docker.sh          # the suite on PHP 7.4 and 8.3
./tests/docker.sh build    # build.sh inside a container, to see the zip
```

**Use Docker, not a local PHP.** Do not install PHP on the machine to run these; `docker.sh` is
the way. `run.sh` and `build.sh` are what CI calls, with PHP on its PATH.

**Every guard here was mutation-tested** when it was written: break it, watch its suite fail.
Counting `FAIL` lines is not enough — the `Throwable` guard's mutation fails by taking the whole
suite down with a fatal (exit 255), which prints no `FAIL` at all. Read the exit code.

## Rules for the pipeline

These are requirements, not preferences. Each was either asked for explicitly or measured.

- **The order is fixed by stage number**, never by an adapter or a caller: builder assets, asset
  optimizers, object cache, page cache plugins, host cache, NitroPack, CDN, warm. A caller asking
  for some layers gets them in stage order.
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

## Conventions

Same as the scanners: **Conventional Commits** with a lower-case subject that names the symptom,
the body carries the why, **no trailers**. Branches `type/kebab-case`, through a pull request.
**PHP 7.4 floor** — `build.sh` refuses PHP 8-only syntax and CI runs the suite on 7.4.
**Comments explain why**, especially where something looks wrong.

## Releasing

Bump the header `Version:` and `ADVCM_VERSION` together, CHANGELOG entry, merge, push the tag.
The workflow does the rest. **Push the tag and stop.**
