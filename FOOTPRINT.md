# What this plugin leaves on a site

For whoever writes a security-scanner rule, reviews a finding, or adds a mute. The same list,
machine-readable, is `footprint.json` at the root of this repository.

**Neither file ships inside the plugin.** Everything in the plugin's directory is served from
every site at a guessable URL, and a file naming the publisher and the repository there would let
anybody tie the sites running it to each other. The build excludes it.

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
| Cron (single) | `advcm_auto_flush` | no args; the auto-clear, about 60 s after a post matching a rule is published. Scheduled only when a rule is on; removed on deactivation and uninstall |
| Option | `advcm_jobs` | not autoloaded; last 10 clears, up to 30 while waiting for Hawkeye |
| Option | `advcm_layers` | not autoloaded; per layer, last clear |
| Option | `advcm_lock_<job id>` | while a job runs; deleted when it stops |
| Option | `advcm_last_error` | not autoloaded; the last fault the guards caught, shown on the screen for a day |
| Option | `advcm_site_wide_at` | not autoloaded; when a whole-site clear last started |
| Option | `advcm_rules` | not autoloaded; auto-clear rules, written by administrators |
| Option | `advcm_auto_<rule id>` | while a rule waits for its clear, with the first 10 posts that triggered it and a count of the rest; deleted when the clear picks it up |
| Option | `advcm_rules_last` | not autoloaded; per rule, when it last fired, how many posts and the job's state. Written by the clear, never by the rules form |
| Option | `advcm_rules_paused` | not autoloaded; while an administrator has paused every auto-clear rule |
| Site transient | `advcm_release` | updater's cached GitHub answer |
| Site transient | `advcm_loopback` | whether the site can reach its own home page; an hour (ten minutes when not) |
| Transient | `advcm_notice_<user id>` | one minute |
| Transient | `advcm_rate_<user id>` | per-URL clears in the last minute, at most 10 |
| Capability | `advcm_purge`, `advcm_purge_hard` | granted by a `user_has_cap` filter from `edit_others_posts` / `manage_options`; never stored |
| admin-post | `advcm_purge`, `advcm_resume`, `advcm_check_release` | logged in, capability and nonce |
| admin-post | `advcm_rules` | logged in, `advcm_purge_hard` and nonce; changes auto-clear rules, or pauses and resumes them all |
| Hook | `wp_after_insert_post` | marks the auto-clear rules a saved post triggers. One option read when no rule is on; never clears in the save request |
| Filter (offered) | `advcm_auto_excluded_post_types` | the post types an "any content" rule leaves out; validated, and `revision`, `auto-draft`, `nav_menu_item` stay out whatever it returns |
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
