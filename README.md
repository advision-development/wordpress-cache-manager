# Advision Cache Management

A WordPress plugin that clears every cache layer a site has, **in the right order**, and reports
each step on its own. Editors can use it from wp-admin; the Advision Hawkeye console can ask for
it remotely (later phases).

The design spec lives in the team's internal workspace, not in this public repository.

## Status

**0.1.2.** Clears the layers below from wp-admin (Tools → Adv Cache, and the
Adv Cache menu on the admin bar). Talking to Hawkeye is a later phase.

## The order

Clear from the source outward. Every layer rebuilds from the one beneath it, so clearing an
outer layer first lets it re-cache stale content straight away.

| # | Stage | Examples |
|---|---|---|
| 1 | Builder assets | Elementor CSS (Bricks: report only, see the spec) |
| 2 | Asset optimizers | Autoptimize, WP Rocket file optimization |
| 3 | Object cache | Memcached / Redis drop-ins |
| 4 | Page cache plugins | WP Rocket, W3TC, WP Super Cache, LiteSpeed |
| 5 | Host page cache | WP Engine, SiteGround |
| 6 | NitroPack | invalidate by default, purge only when asked |
| 7 | CDN | Cloudflare (from Hawkeye only), CloudFront |
| 8 | Warm | optional |

A layer that is not installed is **skipped and reported**, never a reason to stop.

## Build, test, release

```bash
./tests/docker.sh         # the suite on PHP 7.4 and 8.3, in containers
./tests/docker.sh build   # build.sh: lint, PHP 7.4 syntax gate, tests, dist/wordpress-cache-manager-<version>.zip
./tests/run.sh            # the suite on whatever php is on PATH, which is what CI calls
```

Releasing: bump `Version:` in the header **and** `ADVCM_VERSION`, add the CHANGELOG entry, merge,
then push a `v<version>` tag. The workflow checks the tag against both strings, builds and
publishes the release. **Push the tag and stop** — creating the release by hand makes the
workflow fail on a release that already exists.
