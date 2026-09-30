# Changelog

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
