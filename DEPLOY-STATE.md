# GP Liveblog — Deployment State

**Status:** LIVE on production (gadgetpilipinas.net) — v0.1.1, active.
Deployed: 2026-09-07 (PH) after Gian's "Proceed".

## 2026-09-07 hotfix — float button hidden inside liveblog pages
- Change: `gplb_float_markup()` now returns early on `is_singular('gp_liveblog')`
  (includes/frontend.php, +3 lines, no version bump — pure PHP, nothing cached).
- Deploy: upload 10/10 to random dir `gp-liveblog-e4SfqEDg`, normalized to `gp-liveblog/`.
- Verify: patched frontend.php byte-size match (14,351 = local build); local WP harness
  E2E (liveblog live → live page float 0 / home + post float present); prod fresh-origin
  render of /live/apple-september-event-2026/ serves assets from canonical
  `plugins/gp-liveblog/`, float 0, "Coverage ended".
- Deferred: prod E2E with a temp LIVE liveblog (autologin daily quota was exhausted;
  deploy-day cycle create→verify→end→delete can be repeated when needed).

## v0.1.2 — front-end composer for Admins/Editors — DEPLOYED 2026-09-07 (PH)
- In-page composer on the live page (only while live): text/image/link/social/
  team-note, Ctrl/⌘+Enter publish, entry prepends to timeline. Server-rendered
  ONLY for roles with `edit_gplb_entries` (Admin + Editor).
- Non-staff see a notice strip instead: anonymous → "Log in as an Admin or
  Editor to post updates." + Log in link; logged-in non-staff → "Your account
  cannot add updates…". Strips never contain composer markup.
- **Tightened `gplb_editor_can()`** — dropped the `edit_posts` fallback (was
  letting Authors/Contributors post entries + see team notes). Now strictly
  `edit_gplb_entries`; caps re-granted on activation (version bump re-runs).
- Version bumped 0.1.1 → 0.1.2 (JS/CSS changed → cache-bust `?ver=`).
- Deploy: upload 10/10 → random dir `gp-liveblog-hz8dffj8` → normalized;
  prod now serves `liveblog.js/css?ver=0.1.2` from canonical `gp-liveblog/`;
  namespace alive `[]`; ended page clean. Harness E2E: all roles pass (see below).
- **ROLLBACK ARTIFACT (Gian may ask to revert):** pre-feature prod build saved at
  `/data/workspace/gp-liveblog/rollback/gp-liveblog` (v0.1.1 + float-hotfix;
  all 10 files byte-size-verified vs the recorded hotfix upload) and zipped as
  `/data/workspace/gp-liveblog/gp-liveblog-0.1.1-hotfix-rollback.zip`.
  Rollback = deploy that dir/zip via `hosting_deployWordpressPlugin`; caps
  re-grant automatically (version stamp 0.1.2 → 0.1.1 mismatch). Smoke-tested
  on harness: notice/composer absent, float gate + home float intact.
- Deferred: prod live-event E2E (autologin daily quota exhausted again;
  retry deploy-day cycle later).

## v0.1.3 — PH time, sidebar, category dropdown — build notes (see DEPLOYED below)
- **PH time:** entries now store post_date in site-local time (post_date_gmt stays
  UTC) — the v0.1.1/0.1.2 REST bug stamped UTC into both, so chats displayed UTC.
  Backdate strings ("3:45 PM") parse as site-local too. One-time admin-gated
  backfill (option `gplb_ts_fix_done`) repairs legacy UTC-stamped entries
  (post_date = post_date_gmt signature), ≤500/pass.
- **Sidebar on liveblog pages:** template now uses gp-base's `.article-layout`
  grid (content column + `aside.rail`) matching single.php: rail ad slot, QR
  "scan to follow" when qr-code-composer exists, `gp-article-sidebar` widgets
  (falls back to `sidebar-1`). Collapses below the content on tablet/mobile
  via theme CSS. Non-gp-base themes degrade to a single column.
- **Special Coverage category picker:** liveblog metabox "Coverage target" now
  swaps by mode — Category shows a hierarchical dropdown of all categories
  (with counts) via wp_dropdown_categories; Page/Post keep a labelled numeric ID.
  Save handler reads `gplb_cov_cat` for category mode.
- Version 0.1.3 (JS/CSS/template changed → `?ver=` cache-bust).
- Harness E2E (all passed): new REST entry → ts_h = PH now (8:38 PM both);
  legacy 12:00 UTC entry backfilled to 20:00 PH (GMT untouched, flag set);
  sidebar `.article-layout` + `aside.rail` render with widgets; composer/notice/
  float behavior unchanged (float 0 on live page, 3 on home); metabox renders
  category dropdown (selected + nested options) in the block-editor Liveblog
  tab; save-path stores the picked category id. Screenshot in session.
- Rollback artifact: `/data/workspace/gp-liveblog/rollback/gp-liveblog-0.1.2`
  + `gp-liveblog-0.1.2-rollback.zip` (0.1.2 = current prod).
- Zip: `gp-liveblog-0.1.3.zip`.

## v0.1.3 — PH time, sidebar, category dropdown — DEPLOYED 2026-09-07 (PH)
- Entry timestamps: post_date stored site-local (PH), post_date_gmt UTC; backdates
  parse as PH wall-clock; one-time admin-gated backfill (`gplb_ts_fix_done`) repairs
  legacy UTC-stamped entries on the admin's first page load after upgrade.
- Liveblog pages: gp-base `.article-layout` grid + `aside.rail` (rail ad slot,
  QR "scan to follow" [qr-code-composer IS on prod], gp-article-sidebar widgets).
- Metabox: Coverage target Category → hierarchical dropdown of all categories
  (counts); Page/Post → labelled numeric ID.
- Deploy: 10/10 uploads → `gp-liveblog-t0Xf9OS4`; deploy step returned 500 but
  the swap completed — canonical dir serves `liveblog.js/css?ver=0.1.3`; stale
  dir auto-cleaned (404). Fresh origin render (12:45:20 PH): article-layout +
  rail present, rail ad slot ×2 + QR + 3 sidebar widgets render, hero/timeline
  intact, "Coverage ended", float 0, namespace `[]`.
- Pending: entry-time backfill fires on first admin page load (Gian's next
  logged-in visit); prod live-event E2E still deferred (autologin down).
- Rollback artifact: `rollback/gp-liveblog-0.1.2` + `gp-liveblog-0.1.2-rollback.zip`.

## v0.1.4 — QR on live pages + featured image (built, NOT yet deployed)
- **QR fix:** gp-base only loads the QR painter on `is_singular('post')`, so the
  live page canvass rendered empty. Plugin now self-loads the same assets
  (`gp-qr-lib` + `gp-qr-create` + inline `datas`, mirrors `gp_base_qr_assets()`)
  on `gp_liveblog` pages; rail canvas id changed to the single-post convention
  `gp-qrc-{id}`. Prod QR box will paint once deployed (qr-code-composer present).
- **Featured image:** gp-base **side-hero** style (Gian's pick) — title/hero
  header on the left, featured image on the right (`.head-media` grid, 320px,
  object-fit cover) with the content + rail grid starting below, exactly like
  single posts. No image → plain full-width hero header. CPT already supported
  thumbnails (editor "Set featured image" was present).
- Version 0.1.4. Harness E2E (all passed): QR assets enqueued + canvass painted
  (stub painter w/ real file paths); figure order hero → image → grid → rail;
  composer/notice/float unchanged. Screenshot in session.
- Rollback artifact: `rollback/gp-liveblog-0.1.3` + `gp-liveblog-0.1.3-rollback.zip`.
- Zip: `gp-liveblog-0.1.4.zip`.

## v0.1.4 — QR on live pages + side-hero featured image — DEPLOYED 2026-09-07 (PH)
- Deploy: 3rd attempt succeeded (1st/2nd returned 500 mid-swap w/o effect);
  upload `gp-liveblog-yxkDOitF`; canonical serves `liveblog.js/css?ver=0.1.4`
  (fresh origin render 13:05:49). Prod page now shows side-hero `.head-media`
  + rail; QR scripts (qr-code-styling.js, qrcode.js + inline datas) enqueued
  on the live page → QR paints. Float 0. Stale dirs 404 (auto-cleaned).
- Theme side: gp-base **v1.24** committed & pushed to GitHub
  (gianviterbo/gp-base-theme `main` @ 83656ae): `gp_base_coverage_card_actions`
  hook added in `inc/coverage.php` (server-side LIVE COVERAGE button; plugin
  JS bridge dedupes via existing-button check). Theme package rebuilt:
  `gp-base.zip` 1.24 (83 files, integrity OK, hook + version verified inside).
  Theme NOT yet deployed to prod (awaiting Gian's go — normal gated release).
- Docs: theme-integration/coverage-card-hook.patch.md marked shipped in v1.24.
- Rollback artifact: `rollback/gp-liveblog-0.1.3` + `gp-liveblog-0.1.3-rollback.zip`.

## v0.2.0 — reactions, analytics, media previews, pinned video (built, NOT deployed)
- **Reactions** per entry (live page + embeds): 👍😊😂😢👎🤔😡 — one per
  visitor (localStorage id, switchable/removable), counts everywhere; tables
  `gplb_reactions` (+`gplb_viewers`) auto-created on version bump.
- **Insights:** unique total viewers (visitor-tagged watch beats + rolling
  peak), reaction totals; public stats chips on live page (👁/now/⚡/❤/✍,
  30s poll) + stats row in control room; REST `GET /liveblogs/{id}/stats`.
- **Media previews:** YT/TikTok/IG links → oEmbed card (thumb/title/author,
  1h cache); YouTube plays inline on click. Fixed `v=` query regex.
- **Pinned video** above the updates: set from live-page composer ("🎥 Pin
  video") or control room; REST `POST /liveblogs/{id}/video`; viewers sync
  via entries poll (≤20s). YT-nocookie/TikTok/IG embeds.
- **Coverage card:** JS bridge now inserts LIVE COVERAGE inline after the
  CTA; CSS matches height/compact (`.gp-coverage-card .gplb-coverage-btn`).
- Harness E2E passed: tables, unique viewers (2 visitors → 2, repeat stays),
  reaction switch/remove/dedupe, stats endpoint, real YT oEmbed (title+img),
  pin/unpin, page chips + footers + media card + iframe render (screenshot in
  session). GitHub `main` @ 42187d5.
- Rollback artifact: `rollback/gp-liveblog-0.1.4` + `gp-liveblog-0.1.4-rollback.zip`
  (git archive of 7c3f157). Zip: `gp-liveblog-0.2.0.zip`.
- PENDING (unrelated): gp-base v1.24 theme deploy (built, awaiting go).

## v0.2.0 — reactions, analytics, media previews, pinned video — DEPLOYED 2026-09-07 (PH)
- Deploy: upload 10/10 → `gp-liveblog-mUBjEOe4`; status success; canonical
  serves `liveblog.js/css?ver=0.2.0` (fresh origin render 16:29). Ended-page
  render verified: 5 stats chips, reaction footers on entries, no pinned,
  float 0. `GET /liveblogs/212334/stats` OK; tables auto-created on bump.
- **NOTE: prod Apple "Surprise and Shine" event went LIVE ~16:30 PH right as
  this deployed** — stats now accrue for real (watching 5+; viewers/reactions
  totals start from deploy moment; old 0.1.4-era watchers count once their
  browsers reload the new JS). Bunny per-PoP staleness ≤ TTL; snippet #69
  (purge on publish) keeps the live page fresh.
- Rollback artifact: `rollback/gp-liveblog-0.1.4` + `gp-liveblog-0.1.4-rollback.zip`.
- PENDING: gp-base v1.24 theme deploy (awaiting go).

## v0.2.1 + v0.2.2 + gp-base v1.24/1.25 — coverage button inline + image repair (2026-09-07 PH, Apple event LIVE)
- **gp-base v1.24 DEPLOYED** (Gian: "Proceed") — server-side LIVE COVERAGE button now
  renders in the card HTML (hook in inc/coverage.php). Theme deploys via MCP
  `hosting_deployWordpressTheme`; raw api.hostinger.com was Cloudflare-530 all day
  (deploy_theme.py unusable; MCP files API unaffected). activate:true required —
  without it the upload dir is discarded unswapped.
- **v0.2.1** — bridge fix: coverage card body can render AFTER DOMContentLoaded
  (theme lazy panel) → inject() now retries every 500ms ×20 instead of a single
  pass; button is only ever placed directly after `.cov-cta` inside `.cov-body`
  (never appended to card root). CSS forces `inline-flex` (the shared
  `.gplb-embed-toggle{display:flex;margin:6px auto}` embed rule was making the
  coverage pill full-width block).
- **v0.2.2** — removed legacy inline `style="margin-top:12px;..."` from
  `gplb_render_coverage_button()`; pill padding/line-height matched to the CTA
  (34px ↔ 35px). Browser-verified on prod home: button inline beside "Browse the
  category →" (11px gap, same row, correct height) — screenshot in session.
- **gp-base v1.25** — render-time legacy-image repair: `the_content` filter
  rewrites own-domain `/wp-content/uploads/*.jpg` refs to their `.webp` twins
  when the file exists (file_exists + 24h transient). Fixes the April 2026
  "iPhone 18 design leak" article (and any other pre-WebP-migration post) whose
  sources were deleted by the 49 GB conversion. Editorial images verified 200.
  Commit d6ac52d.
- **NOT fixed (needs admin):** Hostinger referral ad creative #212019
  (`Badge_dark_320×120.png`, Advanced Ads, rail slot on the leak article) has a
  stray space in its stored filename → 404. File exists without the space.
  Fix when autologin resets: Advanced Ads → edit ad → clean the image URL (or
  re-insert from media). Do NOT rename the file (other creatives may use the
  clean URL).
- Live state: plugin 0.2.2 + theme 1.25 on prod; Apple event live throughout.
- Rollback artifacts: `gp-liveblog-0.2.1.zip` (previous), new
  `gp-liveblog-0.2.2.zip`; theme rollback = git tag/commit d6ac52d parent 83656ae.

## Production verification (all passed)
- Plugin active: `gp-liveblog/gp-liveblog` v0.1.1 (single registration, no stale dirs)
- REST namespace `/gp-liveblog/v1/liveblogs` responds, `[]` when idle
- Live page 200 w/ hero + entries + LIVE pill + float button; schema = exactly **1× LiveBlogPosting** (no duplicate)
- Ended page: float/assets vanish site-wide (0 footprint), schema degrades to **Article**
- Caps: admin create/edit/delete liveblogs; editor POST denied lifecycle (403); anonymous POST 401
- Entry posting via REST works; feed hides team notes from anonymous
- Rewrite self-heal: `/live/` rule auto-flushed on deploy
- Test content (liveblog 212332 + entry 212333) created, verified, ended, deleted. Deleted page → 404.

## Prod issues found & fixed during deploy
1. **Caps never granted via REST** — `admin_init` hook doesn't fire on REST-only traffic.
   → moved to `init` + `gplb_caps_done` version-stamped option (re-runs on version bump).
2. **`rest_cannot_edit`** — missing `edit_published_gplb_liveblogs`.
3. **`rest_cannot_delete`** — missing `delete_published_*` + `delete_others_gplb_liveblogs`. (0.1.1)
4. **Live page 404** — CPT rewrite rule absent; deploy-tool activation doesn't flush.
   → `gplb_rewrite_rule_ok()` self-heal on init (checks option, flushes when missing).
5. **Duplicate LiveBlogPosting** — fallback emitted even when Rank Math active.
   → fallback gated on `RANK_MATH_VERSION`; ended pages now get plain Article via Rank Math filter.

## Deploy quirks (Hostinger MCP)
- Each deploy uploads to a RANDOM dir (`gp-liveblog-XXXXXX`); tool normalizes to `gp-liveblog/` on success.
- A 500 mid-deploy left a stale inactive dir (`gp-liveblog-DBt1DyYk`) → removed via
  `DELETE /wp-json/wp/v2/plugins/{dir}/{file}` (use raw path, no %2F).
- Version bump is REQUIRED to re-trigger cap/rewrite grants (option keyed to GPLB_VERSION).

## Theme integration (pending)
- JS bridge in plugin works with zero theme changes (injects LIVE COVERAGE button
  into `.gp-coverage-card` when a live liveblog targets the featured item).
- Server-side hook patch prepared: `theme-integration/coverage-card-hook.patch.md`
  → rides next gp-base release.

## Source of truth
- **GitHub:** `gianviterbo/gp-liveblog` (main) — repo ROOT is canonical plugin
  source (gp-liveblog.php at root). Workflow: edit repo root → `cp -r` changed
  files to `build/gp-liveblog/` → deploy from build dir.
- Build/deploy dir: `/data/workspace/gp-liveblog/build/gp-liveblog/`
- Zip: `/data/workspace/gp-liveblog/gp-liveblog-0.1.4.zip` (current)
- Rollbacks (dir + zip each): `rollback/gp-liveblog/` = 0.1.1+hotfix;
  `rollback/gp-liveblog-0.1.2/`, `rollback/gp-liveblog-0.1.3/`; zips
  `gp-liveblog-0.1.1-hotfix-rollback.zip`, `gp-liveblog-0.1.2-rollback.zip`,
  `gp-liveblog-0.1.3-rollback.zip`
- Local harness: /tmp/wptest (volatile, PHP-CLI SQLite — recreated per session if needed)
