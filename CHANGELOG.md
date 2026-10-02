# Changelog

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
