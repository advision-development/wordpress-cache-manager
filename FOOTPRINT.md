# What this plugin leaves on a site

For whoever writes a security-scanner rule, reviews a finding, or adds a mute. The same list,
machine-readable, is `footprint.json` at the root of this repository.

**Neither file ships inside the plugin.** Everything in the plugin's directory is served from
every site at a guessable URL, and a file naming the publisher and the repository there would let
anybody tie the sites running it to each other. The build excludes it.

**Neither is a reason to exempt anything.** A file on a site that says "do not look at me" is the
first thing a compromised site would write. Recognise this plugin by checking its directory
against the release it claims to be — every release publishes `wordpress-cache-manager-<version>.sha256` beside the zip —
and decide exceptions where the site cannot edit them: in the scanner, or in Hawkeye.

## Everything is prefixed `advcm`

Cron hooks, options, transients, capabilities, admin-post actions and the page slug all begin
with `advcm`. A test in this repository fails if one does not, and another fails if this list
and the code disagree.

| Kind | Name | Notes |
|---|---|---|
| Cron (single) | `advcm_continue` | args `[job id]`; the next step of a background clear. Registered on every request, removed on deactivation and uninstall |
| Cron (single) | `advcm_auto_flush` | no args; the auto-clear, about 60 s after a post matching a rule is published — or, when WP-Cron is more than 2 minutes behind, timestamped just before its oldest due event, so it runs first. Scheduled only when a rule is on; removed on deactivation and uninstall |
| Cron (single) | `advcm_listings_scan` | no args; since 0.5.0, the next batch of a listings scan a person started: up to 20 Elementor documents within 8 s. Scheduled only by a press; reads only. Removed on deactivation and uninstall |
| Cron (single) | `advcm_optimizer_scan` | no args; since 0.6.0, only where NitroPack is installed: the oldest-copies scan every 6 h, first scheduled when a screen of this plugin opens. Up to 1,000 cache folders within 2 s per run (file times only), then this site's URLs hashed in batches of 500 to name the kept copies. Reads only. Removed on deactivation and uninstall |
| Option | `advcm_jobs` | not autoloaded; the last 20 clears people started (automatic ones are in `advcm_jobs_auto` since 0.4.0) |
| Option | `advcm_jobs_auto` | not autoloaded; since 0.4.0, the last 20 automatic clears, apart from the clears people started |
| Option | `advcm_layers` | not autoloaded; per layer, last clear |
| Option | `advcm_lock_<job id>` | while a job runs; deleted when it stops |
| Option | `advcm_last_error` | not autoloaded; the last fault the guards caught, shown on the screen for a day |
| Option | `advcm_site_wide_at` | not autoloaded; when a whole-site clear last started |
| Option | `advcm_site_wide_log` | not autoloaded; since 0.5.1, the times of the whole-site clears in the last hour, at most 4 |
| Option | `advcm_rules` | not autoloaded; auto-clear rules, and the "each post's own page" setting as the reserved rule `own-page`; written through the rules and settings forms by users holding `advcm_purge_hard` (editors and administrators since 0.4.0) |
| Option | `advcm_auto_<rule id>` | while a rule waits for its clear, with the first 10 posts that triggered it and a count of the rest (for `own-page`, also up to 40 of those posts' own URLs, taken at the save); deleted when the clear picks it up |
| Option | `advcm_rules_last` | not autoloaded; per rule, when it last fired, how many posts and the job's state. Written by the clear, never by the rules form |
| Option | `advcm_settings` | not autoloaded; since 0.4.0, the Settings tab: own-page ignores unchanged saves, the post types auto-clear leaves out, the NitroPack choice for new rules, whether a clear of some pages measures their cache age first (0.5.0), the cache target in hours (0.5.1), the version last seen. Read in a post save only after a rule is on, the save is visible and auto-clear is not paused |
| Option | `advcm_freshness` | not autoloaded; since 0.5.0, how old each page's cached copy was when a clear somebody started cleared it: the last 100 pages (URL cut to 2048 characters, job, who, HTTP code, each layer's age, NitroPack's state). At most 10 pages per job, never for a whole-site clear |
| Option | `advcm_freshness_auto` | not autoloaded; since 0.5.0, the same for automatic clears, the last 100 pages, kept apart |
| Option | `advcm_freshness_stats` | not autoloaded; since 0.5.1, the measurements added up: per page (at most 200, URL cut to 2048 characters) and per day (the last 30) — clears by hand and automatic, unknown, under 10 minutes, past the cache target, oldest, sums, rules seen, and since 0.5.2 where a redirect pointed |
| Option | `advcm_listings` | not autoloaded; since 0.5.0, the last listings scan: who started it, a cursor (a post id) and counters, at most 300 findings (red, amber, custom query; strings cut), green ones only counted |
| Option | `advcm_lock_listings` | not autoloaded; since 0.5.0, while a listings batch runs, naming the document it reads |
| Option | `advcm_optimizer_index` | not autoloaded; since 0.6.0, the oldest-copies scan: cursor, counters, per NitroPack device at most 100 copies for the last list and 100 for the one being built (folder, time, flags, a post/term/home reference, never a URL); under 150 KB |
| Option | `advcm_lock_optimizer` | not autoloaded; since 0.6.0, while an oldest-copies batch runs, naming the folder it reads |
| Option | `advcm_log` | not autoloaded; since 0.4.0, who changed what: the last 20 changes per tab section, each with the person, the time and the value before and after (cut to 200 characters). Written only by requests a person made |
| Option | `advcm_rules_paused` | not autoloaded; while an administrator has paused every auto-clear rule |
| Site transient | `advcm_release` | updater's cached GitHub answer |
| Site transient | `advcm_loopback` | whether the site can reach its own home page; an hour (ten minutes when not) |
| Transient | `advcm_notice_<user id>` | one minute |
| Transient | `advcm_rate_<user id>` | per-URL clears in the last minute, at most 10 |
| Transient | `advcm_optimizer_summary` | since 0.6.0, the Optimizer tab's summary (NitroPack's pending rebuilds and last purge from its API, copies per device, expiry); 10 min, 1 min after a failure. Deleted on uninstall |
| Transient | `advcm_optimizer_look_<md5>` | since 0.6.0, one lookup a person asked NitroPack's API for (a page's tags, at most 100; or the pages showing a post, at most 50); 10 min; a failure is not kept |
| Transient | `advcm_rate_look_<user id>` | since 0.6.0, lookups one person asked in the last minute, at most 10 |
| Transient | `advcm_rate_site` | since 0.5.1, per-URL clears everybody started in the last minute, at most 30 |
| Capability | `advcm_purge`, `advcm_purge_hard` | granted by a `user_has_cap` filter from `edit_others_posts` (both, since 0.4.0; a filter narrows either); never stored |
| admin-post | `advcm_purge`, `advcm_resume`, `advcm_check_release` | logged in, capability and nonce |
| admin-post | `advcm_listings` | logged in, `advcm_purge` and nonce; since 0.5.0, starts a listings scan on the Freshness tab |
| admin-post | `advcm_optimizer_refresh` | logged in, `advcm_purge` and nonce; since 0.6.0, Refresh now on the Optimizer tab: the summary asked again, the oldest-copies scan scheduled. Reads only |
| admin-post | `advcm_settings` | logged in, `advcm_purge_hard` and nonce; since 0.4.0, saves the Settings tab, pauses and resumes auto-clear, switches each post's own page |
| admin-post | `advcm_rules` | logged in, `advcm_purge_hard` and nonce; changes auto-clear rules, or pauses and resumes them all |
| Hook | `wp_after_insert_post` | marks the auto-clear rules a saved post triggers. One option read when no rule is on; never clears in the save request |
| Filter (offered) | `advcm_auto_excluded_post_types` | the post types an "any content" rule leaves out; validated, and `revision`, `auto-draft`, `nav_menu_item` stay out whatever it returns |
| admin-ajax | `advcm_optimizer_lookup` | `wp_ajax_` only, never `nopriv`; `advcm_purge` and nonce. Since 0.6.0, a page's NitroPack tags or the pages showing a post, after the screen has loaded; reads only |
| admin-ajax | `advcm_tick` | `wp_ajax_` only, never `nopriv`; capability and nonce. The status screen moving a due background clear |
| Page | `tools.php?page=advcm-cache` | |

Outside the prefix it touches one WordPress name: it deletes the `update_plugins` site transient
after a release check, so the Plugins screen re-reads the update list.

No REST routes, no `wp_ajax_nopriv_*`, nothing reachable without logging in. The plugin writes no
files; the files that change are the plugin's own, when it updates.

## Requests it makes

- `api.github.com` and `github.com` for its own releases, through WordPress's upgrader.
- **Its own site only**, for the warm-up, with a user agent containing `AdvisionCacheWarm/<version>`
  — the string to match in a firewall, a rate limit or a request log. And at most once an hour, a
  `HEAD` of its own home page from the status screen, to tell whether WordPress can wake its cron.
  And when somebody asks the screen how old a page's cache is, one `GET` of that page with a user
  agent containing `AdvisionCacheProbe/<version>`. All of it through WordPress's HTTP API, so the
  site's proxy settings and `WP_HTTP_BLOCK_EXTERNAL` apply.

## What a scanner should expect

WordPress Malware Quick Scan, read on 2026-10-01:

| Rule | Severity | Why |
|---|---|---|
| `plugin_not_in_directory` | info | not on wordpress.org; it joins the one finding that lists such plugins |
| `recent_application_files` | info | after every self-update, target `wp-content/plugins/wordpress-cache-manager` |

Anything else naming this plugin is worth reading. The code is written to stay clear of the rules
that would otherwise fire on a plugin like this one — `remote_code_dropper` (the updater builds
its URLs from constants and installs through `Plugin_Upgrader`), `suspicious_cron_event` (the hook
is always registered), `dual_head_blob_injector`, `unauthenticated_secret_endpoint` — and the
smoke test runs that scanner against it.
