# What this plugin leaves on a site

For whoever writes a security-scanner rule, reviews a finding, or adds a mute. The same list,
machine-readable, is `wordpress-cache-manager/footprint.json`, shipped inside the plugin.

**Neither is a reason to exempt anything.** A file on a site that says "do not look at me" is the
first thing a compromised site would write. Recognise this plugin by checking its directory
against the release it claims to be — every release publishes `checksums.sha256` beside the zip —
and decide exceptions where the site cannot edit them: in the scanner, or in Hawkeye.

## Everything is prefixed `advcm`

Cron hooks, options, transients, capabilities, admin-post actions and the page slug all begin
with `advcm`. A test in this repository fails if one does not, and another fails if this list
and the code disagree.

| Kind | Name | Notes |
|---|---|---|
| Cron (single) | `advcm_continue` | args `[job id]`; the next step of a background clear. Registered on every request, removed on deactivation and uninstall |
| Option | `advcm_jobs` | not autoloaded; last 10 clears, up to 30 while waiting for Hawkeye |
| Option | `advcm_layers` | not autoloaded; per layer, last clear |
| Option | `advcm_lock_<job id>` | while a job runs; deleted when it stops |
| Option | `advcm_last_error` | not autoloaded; the last fault the guards caught, shown on the screen for a day |
| Site transient | `advcm_release` | updater's cached GitHub answer |
| Site transient | `advcm_loopback` | whether the site can reach its own home page; an hour (ten minutes when not) |
| Transient | `advcm_notice_<user id>` | one minute |
| Capability | `advcm_purge`, `advcm_purge_hard` | granted by a `user_has_cap` filter from `edit_others_posts` / `manage_options`; never stored |
| admin-post | `advcm_purge`, `advcm_resume`, `advcm_check_release` | logged in, capability and nonce |
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
