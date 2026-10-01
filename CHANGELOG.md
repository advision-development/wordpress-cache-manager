# Changelog

## 0.2.1 — 2026-10-01

- **A background clear waited for ever where the site cannot wake its own cron.** WordPress fires
  scheduled events by requesting its own `wp-cron.php`; on a staging install behind HTTP
  authentication that request was refused and a Balanced clear did not move for five minutes.
  The status screen now moves a due job itself, through a logged-in admin-ajax call while it is
  open, and checks (once an hour) whether the site can reach itself: when it cannot, it says so
  and recommends Fast.
- A scheduled event left from before a pause could have fired early and skipped it, once something
  other than WP-Cron could move a job. A job now runs only when its next step is due, and a
  job's event is replaced, not added to, when its time changes.
- A clear started from WP-CLI is recorded as **WP-CLI**, not as whichever account the command
  acted as. Testing on staging attributed two clears to an editor who had pressed nothing.

## 0.2.0 — 2026-10-01

- **NitroPack now clears before WP Engine.** Its drop-in answers from PHP, behind WP Engine's
  Varnish, so clearing Varnish first let it store NitroPack's old copy in between. Read from
  NitroPack's own code, whose WP Engine integration purges Varnish after every NitroPack purge.
  The object cache now clears first of all.
- **Modes.** Fast (in the request), Balanced (background, recommended for the whole site) and
  Careful (background, longer pauses, a larger warm-up). Each is explained beside its button and
  the recommended one is selected. Background modes run on WP-Cron; a job that stops moving shows
  as stuck with a button to finish it now. With `DISABLE_WP_CRON`, only Fast is offered.
- **Warm-up.** The cleared pages are requested once as desktop and mobile, so the next visitor is
  not the one who waits. Site-wide: the home page and the most recently updated pages. Never an
  address off the site. User agent `AdvisionCacheWarm/<version>`, batches spaced so a rate limit
  in front does not see a burst, and a large warm-up continues across requests.
- **WP Engine per URL purges by path** — archives, categories and query strings included — in one
  call. The old loop stopped silently at WP Engine's limit of three purges per request and
  reported the rest as purged.
- **Nothing the plugin hooks can break a page.** Every hook goes through a guard; a failing filter
  returns what it was given, the screen shows a notice instead of a white page, and the fault is
  logged and shown on the screen. The plugin does not start on a PHP below 7.4, or if a file fails
  to load, and says so to administrators.
- **Easy to recognise.** Everything it writes is prefixed `advcm`; `FOOTPRINT.md` and
  `footprint.json` list it all; each release publishes per-file checksums.
- `Update URI` in the header, so a plugin registering this slug on wordpress.org can never be
  offered as its update.
- The menus said only "Cache"; they now read **Adv Cache**, and the screen **Advision Cache
  Management**.
- `tests/smoke.sh`: the plugin in a real WordPress, on PHP 7.4 and 8.3, with WordPress Malware
  Quick Scan run against it.

## 0.1.1 — 2026-09-30

- The plan said Bricks CSS "will be cleared" when every run skips it. A report-only layer is now
  planned as `skipped — report only`, and the runner never calls it.
- A failed update check that GitHub answered with 404 now says the repository may not be public,
  not only that there is no release.

## 0.1.0 — 2026-09-30

- **Clears every cache layer in order**, from wp-admin: Tools → Cache, and a Cache menu on the
  admin bar with "Clear this page" and "Clear the whole site". Editors and up can use it.
- Layers: Elementor CSS (site-wide, Elementor's own operation), WP Rocket minified files, the
  object cache, WP Rocket's page cache, WP Engine's page cache, NitroPack. Bricks CSS is reported
  and never regenerated.
- The screen shows the plan before anything is pressed: every layer, in order, and whether it will
  run here or why not. Each step is checked again right before it runs.
- Every call into another plugin is guarded with `catch ( Throwable )`, a fatal inside one step
  costs that step and the rest continue in a fresh request, and a builder failing part-way holds
  the page caches. Every step reports `ok`, `skipped`, `partial`, `failed` or `held`.
- **Keeps little.** The last 10 jobs in one non-autoloaded option, plus a per-layer summary —
  when each layer was last cleared, by whom, how, and its last success — shown on the screen. Jobs
  carry a `sent` flag for when sites report to Hawkeye: an unconfirmed job outlives the ten, up to
  30 in all, so the row stays under ~100 KB whatever Hawkeye is doing.
- NitroPack is invalidated site-wide by default; a full purge and overriding a hold need
  `manage_options`.
- The plugin skeleton: header, autoloader, and the GitHub updater copied from WordPress Access
  Quick Scan with its pinning, padding and failure caching unchanged.
- `tests/docker.sh` runs the suite on PHP 7.4 and 8.3 in containers.
- `build.sh`, the CI workflow (PHP 7.4 and 8.3) and the tag-push release workflow.
