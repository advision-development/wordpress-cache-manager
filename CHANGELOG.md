# Changelog

## 0.6.0 — 2026-10-21

Issue #21: editors on BMR flushed NitroPack site-wide when a new article did not show on a hub
page, mostly guessing. What would tell them was already on the server.

- **A NitroPack tab**, only on a site with NitroPack, which only shows: the site summary (copies
  per device, invalidated, pages waiting to rebuild, the last purge, the expiry in force), the 100
  oldest copies of each device with created and lives until, updated by a WP-Cron scan every 6
  hours, and pages showing a post. NitroPack's API is asked only when a person asks, cached ten
  minutes, ten lookups a minute per person.
- **Cache age is now Inspect a page**: with NitroPack, each device's copy, its tags in words and
  what would rebuild it, saying when no auto-clear rule names the page. Freshness and NitroPack
  rows link to it.
- **`x-cache-ctime` is NitroPack's** where NitroPack answers. It was shown as the host cache's; on
  BMR WP Engine keeps pages 10 minutes, and the hours-old ages were NitroPack's. Rows measured
  before keep their numbers, relabelled.
- One reader of NitroPack's copies for the probe, Freshness and the new views, every path from
  NitroPack's own SDK.

## 0.5.3 — 2026-10-21

- **A refused cache-age check was recorded as a probe.** A check of `/wp-login.php`, refused
  before any request, still appeared under the Cache age tab's Last changes. Seen while taking the
  manual's screenshots; the change log records no refusal, and now this one neither.
- "1 documents" reads "1 document" on the Freshness tab.
- Documentation brought in line with the code: the README says how a site updates (WP Engine does
  not install plugin updates by itself, so a person presses Update), and the auto-clear notes no
  longer say rules invalidate NitroPack by default or that only administrators write them.

## 0.5.2 — 2026-10-21

The first day of 0.5.1 on BMR: no clear by hand, waits of 90 s (median) to 5 min 20 s, and
Freshness's first reading — which showed where its own reading was wrong.

- **A post is not a listing.** Posts nobody edited for 38 hours were listed "past the target"
  when own-page cleared them on an edit. Their old copy was the unchanged post; no visitor saw an
  old version of a change. Past the target now lists pages a rule names or people clear; posts
  are counted apart in the summary.
- **A listing cleared while fresh is no finding.** /analysis/ was reported as cleared while
  fresh, 34 times in 40: a rule clears it on every publish, so its copy is always young, and every
  clear still changed it. The section is gone; "Cleared by hand, often" says how many of those
  clears found a fresh copy, which is where that means something.
- **Pages that redirect.** A rule on BMR named six pages that redirect to /analysis/, cleared for
  nothing on every publish. Each measurement keeps where a redirect points, and Freshness lists
  a rule's pages that redirect, with where to.
- **The change log reads "new: on" and "removed (was on)"** where it read "→ on" and "on →".

## 0.5.1 — 2026-10-20

- **An auto-clear lost in a plugin update is put back.** On BMR, updating to 0.5.0 switched the
  plugin off for about ten seconds, and a WP-Cron run in that window took an auto-clear's event
  with nothing listening: the clear waited 10 min 40 s for the next save. A waiting rule with no
  event — and a listings scan whose event is gone — is now scheduled again when a CacheManager
  screen opens, from its tick, and when a plugin update ends. Never in a save. (An update runs the
  version being replaced, so the update hook helps from 0.5.1 onwards; updating to 0.5.1, the
  first screen opened puts a lost clear back.)
- **Freshness adds up a month.** Measurements were the last 100 clears of each kind, which
  auto-clear fills in hours. They now also add up per page (200 pages) and per day (30 days) in
  `advcm_freshness_stats`, and an automatic clear's row names the rules that set it off.
- **The Freshness tab says what to do** — it only shows; it never clears, adds a rule or changes
  a setting: the last 30 days against a cache target (a new setting,
  3 hours by default), pages kept past it and whether a rule clears them ("add one"), pages
  cleared while fresh and the rules that do it, pages cleared by hand often, then the listings —
  labelled "over 30 days", "7–30 days", "no posts", "broken filter", "cannot match", "not
  checked", the same list in one template shown once with ×N — and the raw measurements folded.
- **Every tab explains itself**: what it is for, and a folded "How this tab works" — when to use
  it, how it works underneath, what each column, badge and word means, and its limits.
- **Security** (review of 2026-10-20; no backdoor or injection found):
  - whole-site clears are limited to 4 an hour, and page clears to 30 a minute site-wide, beside
    the per-person limits;
  - the change log shows display names, not logins;
  - WordPress's own machinery (wp-admin, wp-login.php, the REST API, xmlrpc.php, wp-cron.php,
    core and plugin code) is refused as a page to clear or check; the probe uses
    `wp_safe_remote_get`;
  - the admin bar's clears are posted, with no nonce in their links, and a GET is refused;
  - other plugins' errors lose the server's paths before History shows them;
  - footprint.json and FOOTPRINT.md corrected; the release workflow says what really protects
    a release.
  - Still to set in GitHub, outside the plugin: a ruleset on `v*` tags, a protected `main`,
    immutable releases, a reviewed `release` environment, SHA-pinned actions, 2FA. Signing the
    zip is the step after.

## 0.5.0 — 2026-10-20

On BMR the state pages' news carousels (`/usa/alabama/`) showed the same posts for months and were
reported as a cache problem. It was not: the Elementor widget lists posts tagged `alabama`, and no
post had been tagged `alabama` since 2025-12-31. Every clear rebuilt the same page. This release
makes that visible, without touching content.

- **A Freshness tab.** Listings with nothing new: a scan, started by a person and run in WP-Cron
  batches, of every Elementor posts / loop-grid / loop-carousel widget that filters by term, with
  the newest post it matches (red over 30 days or a filter that cannot match, amber from 7). Cache
  age at clear: how old each page's cached copy was when a clear cleared it. Cleared by hand,
  often: pages people keep clearing, the cache age at those clears, and whether a rule covers them.
  Read-only; `advcm_listings`, `advcm_freshness`, `advcm_freshness_auto`, cron
  `advcm_listings_scan`, admin-post `advcm_listings`.
- **A clear of some pages measures them first**, once per job and before its first stage: at most
  10 pages, 5 seconds each, 20 in all; never a whole-site clear. A setting switches it off.
- **Exact cache ages.** The Cache age tab reads WP Engine's `x-cache-ctime`, NitroPack's state in
  the page and the time of NitroPack's own copy — each only where the site has that layer.
- **History as two tables**, with a badge per state, the wait highlighted, and the cache age each
  clear found; each row opens to its steps and pages.

## 0.4.1 — 2026-10-20

- **History says how long a clear somebody asked for waited**, not only an automatic one: from the
  request to its first step that ran. A Fast clear runs in the request and shows nothing; a
  Balanced or Careful clear that WP-Cron ran late shows how late (more than 5 seconds), so a clear
  waiting hours, as BMR's did behind a flooded cron, is visible in History. It reads the time each
  step already stored, so clears kept from before the update show it too.

## 0.4.0 — 2026-10-20

After a day of 0.3.2 on BMR nobody could tell who had changed a rule, the editors using the plugin
could not change rules, settings were spread across tabs, and History kept the last three automatic
clears and lost them within hours.

- **Editors can do everything.** Everyone who can clear may add, switch and delete rules, pause
  auto-clear, change settings, purge NitroPack in full and override a builder hold. The owner chose
  a record over a restriction (2026-10-20). `advcm_purge_hard_base_capability` still lets a site
  take it back. Choosing layers, which no screen offers, stays with administrators.
- **A change log on every tab**: who, when, what, before → after, for clears people started, rule
  and setting changes, the pause, stuck jobs finished by hand, release checks, Cache age probes and
  plugin updates. 20 per tab, 10 shown, values cut to 200 characters; refusals and WP-Cron record
  nothing. `advcm_log`.
- **A Settings tab** with every setting: pause, each post's own page, own page ignoring saves that
  change nothing (new, on by default), the post types auto-clear leaves out (was a code filter
  only), and the NitroPack choice new rules start with. `advcm_settings`.
- **Own page ignores saves that change nothing.** On BMR something saved seven review pages every
  hour without changing them, and own-page cleared them every hour. A save that leaves title,
  content, excerpt, slug, status, password, date, parent and order as they were no longer clears the post's page; listing rules
  still fire.
- **History in two lists, 20 each**: clears people started, and automatic clears in their own row
  (`advcm_jobs_auto`), so automatic clears never push out a clear somebody pressed. The retention
  of unsent jobs for Hawkeye is off until Hawkeye exists. Automatic clears saved by 0.3.2 move on
  the next save.
- **History says how long**: each clear shows how long it took, and an automatic one how long it
  waited after the first save that set it off — the number that showed BMR's clears 8.5 hours late.

## 0.3.2 — 2026-10-19

- **A listing stayed old for hours after an auto-clear that reported ok.** On BMR a pick was
  published on 2026-10-18, its rule cleared `/analysis/` a minute later, every step said ok, and the
  page still showed the old list 18 hours on. The rule invalidated NitroPack, which keeps serving
  the old copy while it rebuilds, and the warm-up then requested the page at once: the host cache
  stored NitroPack's old copy in front of it. Now:
  - A job that invalidated NitroPack skips its warm-up and says why. After a purge, or where
    NitroPack did not run, the warm-up runs as before.
  - A warm-up answered with `X-Nitro-Cache: STALE` is `partial`, naming the pages, not `ok`.
  - A new rule offers **Purge** first and recommends it: the new list shows at once, un-optimized
    for a few minutes. Existing rules keep what they were saved with.
- **An auto-clear ran 8.5 hours late on a site whose WP-Cron was behind.** BMR's cron held 3,500
  copies of another plugin's hourly event; cron runs due events oldest first, so a clear scheduled
  a minute out waited behind every one. Now:
  - When cron's oldest due event is more than 2 minutes late, the clear is scheduled just before
    it, so the next cron run does it first. A burst of saves may then be two clears instead of one.
  - *Status* says how far behind WP-Cron is and how many events wait.
  - While the screen is open, its tick runs an auto-clear that is a full minute past due, as it
    already moved background jobs. Only the request whose `delete_option` takes a rule's mark
    clears it, so the tick and cron never clear the same mark twice.
- **Editors no longer need to clear a post they just edited.** On BMR most manual clears were an
  editor clearing the post they had saved a minute before: NitroPack invalidates it and serves the
  old copy while it rebuilds. A new site setting on the *Auto-clear* tab, *Each post's own page*,
  puts a published, updated or unpublished post's own URL into the auto-clear, purging NitroPack.
  A post taken down clears the address it had, not its `__trashed` one; a burst clears at most 40
  posts' pages; an address a filter moved off the site is never cleared. It is stored as the
  reserved rule `own-page` in `advcm_rules`, so a save on a site using none of this still reads one
  option. Off until an administrator switches it on.
- Every step is told how the steps before it in the job ended (`earlier`), and whether NitroPack
  invalidates or purges is decided in one place, `ADVCM_Adapter_Nitropack::invalidates()`.

## 0.3.1 — 2026-10-01

- **An auto-clear says what triggered it.** History showed an auto-clear as *Auto-clear* and
  nothing else, so "why was `/analysis/` cleared at 10:42" meant reading the editorial log. A
  waiting rule now records the posts whose saves set it off — title, ID, type, and whether each
  was *published*, *updated* or *unpublished* — up to ten per rule and a count of the rest ("and
  7 more"). History shows each rule in words and its posts above the steps, each title linking to
  its edit screen for somebody who may edit it. A post saved twice in the window is listed once.
  Clears from 0.3.0 show as before.
- **The rules table has a *Last clear* column**: when each rule last fired, how many posts
  triggered it, and how the clear went, linked to the job while History still holds it. Kept in
  its own row, `advcm_rules_last`, which the clear writes; the rules themselves are written only by
  the form, so a clear running while an administrator deletes a rule cannot put it back.
- **A rule can cover any content**: every post type a visitor can view, so one rule clears the home
  page whether a post, a page or a custom type is published. It leaves out media, menus, reusable
  blocks, theme and page-builder templates (Elementor, Bricks), and ACF, WPCode, WPForms and
  TablePress records — public types that every template save would otherwise turn into a clear of
  every listing. The `advcm_auto_excluded_post_types` filter can change that list; revisions,
  auto-drafts and menu items stay out whatever it returns.
- **A rule on a category also fires for its subcategories.** A post filed only under
  `news-local` never cleared the `news` listing it appears on, because the rule matched the term
  alone. In a hierarchical taxonomy a rule now matches the term and every term under it, as
  WordPress's own category archive does; in a flat one, such as tags, only the term.
- **Auto-clear can be paused as a whole**, by an administrator, without touching any rule: while
  paused a publish marks nothing, a clear already scheduled clears nothing and drops what was
  waiting, and the tab says so above everything else. Resuming gives back exactly the rules that
  were on. With no rule switched on a save still costs one option read; with one on, a save that a
  visitor can see costs one more, for the pause.
- Fixed a test that failed about one run in forty, when a random rule id was all digits.

## 0.3.0 — 2026-10-01

- **Listing pages refresh themselves when a post is published.** `/analysis/` kept showing an old
  list for days: NitroPack invalidates the pages that rendered a post, a listing never rendered the
  new one, and its copy lives 30 days. A new *Auto-clear* tab holds rules — a post type, optionally
  a term, up to ten of this site's URLs. When a matching post is published, updated while
  published, or taken down, the URLs are cleared about a minute later by WP-Cron, as one per-URL
  job for every post saved in that minute. NitroPack is invalidated by default, so the optimized
  copy keeps serving while it rebuilds; a rule may ask for a purge.
  - Off until an administrator adds a rule. With none switched on, a save costs one option read;
    with one, measured at 0.03 ms. Nothing is cleared in the save request, and a fault in the hook
    is logged without touching the save.
  - Hooked on `wp_after_insert_post`, so a post written in the block editor — whose terms REST
    saves after the status changes — matches a category rule.
  - Administrators write rules; editors see them and can test a post against them. History shows
    each run as *Auto-clear* and keeps the last three.
- A per-URL clear can now invalidate NitroPack instead of purging it; only an auto-clear asks for it.
- A job started by a WP-Cron that WP-CLI drives keeps its auto-clear name instead of becoming
  *WP-CLI*.
- `tests/smoke.sh` covers it in a real WordPress on PHP 7.4, 8.3 and 8.4: a rule added through the
  screen and refused to an editor, a post published through REST, a burst of six coalesced into one
  clear run by the site's real WP-Cron, and a save that succeeds while the hook throws.

## 0.2.5 — 2026-10-01

- **The two NitroPack options could be ticked together and contradict each other.** "Leave
  NitroPack as it is" and "Purge NitroPack instead of invalidating it" were separate checkboxes;
  with both ticked the clear left NitroPack alone and dropped the purge without saying so. They are
  now one choice of three — **Invalidate** (default, recommended), **Leave as it is**, **Purge**
  (administrators only) — and the server reads exactly one value: two at once, an unknown one, or a
  purge asked for by somebody who may not, all become the default. The choice appears only where
  NitroPack is installed, where before the purge box showed to administrators on any site.

## 0.2.4 — 2026-10-01

- **Clear the whole site without NitroPack.** A whole-site clear on a NitroPack site sets it
  rebuilding every page — measured on a large site, purging the host cache page by page as it goes
  for a long while after. "Leave NitroPack as it is" clears everything else and lets NitroPack keep
  serving its optimized copies. Offered only where NitroPack is installed, to anyone who can clear;
  the screen says what it costs: pages NitroPack holds keep their old version until it refreshes
  them itself. History shows the step as left out on request.
- The plugin is called **CacheManager** everywhere: the Plugins screen, the menu, the admin bar,
  the screen's title, the update details panel, and the reason NitroPack logs for its purges.
- CI and `tests/docker.sh` also run on PHP 8.4, which production runs.

## 0.2.3 — 2026-10-01

- **The screen is four tabs**: Status (the layers a clear runs here, when each was last cleared,
  whether the site can wake its cron), Clear (the two forms and their modes), Cache age (the
  per-page check) and History (recent clears, with the progress of a running one). A press lands
  on History with its job open; a refused one on Clear with the reason.
- **Only what runs is listed.** Status shows only the layers a clear actually runs on this site; a
  plugin that is not installed is not mentioned. History follows the same rule, but keeps a layer
  that was there when a clear was planned and gone by its step.
- The list is computed every time the screen opens, so a cache plugin installed or removed after
  this one shows there on the next load.
- While a clear runs, every tab keeps moving it; only History reloads, so a list of URLs being
  typed on Clear is not lost.

## 0.2.2 — 2026-10-01

- **How old is a page's cache**, on the screen: one request to the page, what each layer says, and
  the age — exact from `Age`, 0 on a `MISS`, "at most" since this plugin's last clear when the host
  only says `HIT`, otherwise "unknown". It never invents a number.
- **Security**, from an adversarial review:
  - The admin-bar "Clear the whole site" never asked first: its `onclick` came out of the admin
    bar's `esc_js()` as a syntax error. It is a listener now.
  - The package URL check is the exact shape of a release; `%2e%2e` got past the old `..` check.
  - Releases are built only from commits on `main`, with actions pinned to commits, a read-only
    build and a separate publish job.
  - Editors can no longer choose layers; a builder layer always brings the page-storing ones.
  - The whole-site gap and a new limit of 10 page clears a minute per person no longer live in the
    job history, which a burst of small clears could empty; a running job is never evicted from it.
  - URL lists are capped in size and line length, and refusals are stored as a bounded few.
  - `footprint.json` no longer ships inside the plugin, where every site would serve it.
  - The warm-up goes through WordPress's HTTP API, so a site's proxy and blocking settings apply.
  - A dead job lock is taken over by compare-and-swap, so two requests cannot both run it.
  - Error messages shown on the screen no longer carry server paths.
  - The auto-update docblock said the opposite of what the code does; it now says what it trusts.
- A finished job no longer leaves its cron event behind, which on a site whose cron never wakes
  would have accumulated one per job.

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
