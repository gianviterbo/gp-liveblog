=== GP Liveblog ===
Contributors: gadgetpilipinas
Tags: liveblog, live coverage, events, realtime
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.5
License: GPLv2 or later

Real-time live coverage for launches & press events, built into the GP stack.

== Description ==

Team-authored live coverage with a viewer live feed, floating LIVE panel,
collapsible embeds, LiveBlogPosting schema, and a wp-admin control room.

* **Liveblog pages** at /live/{slug}/ with a live-updating timeline (20s poll).
* **Entry types**: update, WebP image, link card (auto-unfurl), social embed,
  and private team notes (staff-only visibility).
* **Floating LIVE button** (bottom-right) that appears ONLY while coverage is
  live — opens a role-aware panel: viewers see the feed + "Open full liveblog";
  editors also get the quick composer. Hidden on the liveblog page itself.
* **In-page composer** on the live page (Admins/Editors): post text, image,
  link, social or team-note updates straight from the front-end
  (Ctrl/⌘+Enter to publish). Viewers see a notice that posting requires an
  Admin or Editor login.
* **Special Coverage bridge**: while a liveblog targeting the same page/post/
  category as the theme's Special Coverage card is live, a LIVE COVERAGE button
  renders inside the card (JS bridge; server-side hook ready for theme v1.24).
* **Auto-end** after 2h of entry silence (filterable) — feed pauses, entries stay.
* **LiveBlogPosting** schema during the event (Google LIVE badge); Rank Math
  aware; flips to standard article markup after.
* **Control room** in wp-admin: pick a liveblog, quick-compose (Ctrl/⌘+Enter),
  backdate support, edit/delete own entries, 15s self-refresh, End/Re-open
  (admin only).
* **Watching counter**: sliding 2-minute window of viewer heartbeats.

== Roles ==
* Administrator — create/edit/delete liveblogs; end/re-open; delete any entry.
* Editor — post entries, edit/delete their own, view team notes.

== Usage ==
1. Create a Liveblog (Liveblogs → New) and set Status = Live (metabox).
2. Optional: attach it to Special Coverage (mode + target ID) so the coverage
   card shows the LIVE COVERAGE button while live.
3. Editors post from the wp-admin control room (Liveblogs menu), the in-page
   composer on the live page, or the floating panel on the frontend while the
   page is open — all require an Admin or Editor login.
4. Embed a liveblog in any post/page with [gp_liveblog id="123"] or the
   "Liveblog embed" block.

== Frequently Asked Questions ==

= Where do images go? =
Uploads are converted to WebP server-side before storing (house policy) —
sources are deleted after conversion.

= Does it fight the cache/CDN? =
No. Pages render server-side and cache normally; live updates ride a
cache-busted REST poll, so LiteSpeed/Bunny HTML caching is untouched.

== Changelog ==
= 0.2.0 =
* Viewer reactions on every entry (live page + embeds): like/smile/laugh/
  sad/dislike/doubt/angry — one per visitor, switchable; counts everywhere.
* Insights: total viewers (unique visitors), peak concurrent watchers,
  reaction totals + per-entry counts; public stats chips on the live page
  and a stats row in the wp-admin control room; /liveblogs/{id}/stats API.
* YouTube / TikTok / Instagram links now render rich media preview cards
  (oEmbed thumbnail + title + author); YouTube plays inline on click.
* Pinned video: editors embed a video above the live updates (composer on
  the live page or the control room); viewers see it within ~20s of pin.
* Special Coverage card: LIVE COVERAGE button sits inline beside the CTA
  (same height, compact) instead of below it.
= 0.1.4 =
* QR Code Composer now works on live pages: the plugin loads the QR library +
  painter on gp_liveblog pages (the theme only loads them on posts) and the
  rail canvas id matches single-post markup.
* Featured images on liveblog pages: gp-base side-hero style — title left,
  image right of the header, content + rail grid below (single-post look).
= 0.1.3 =
* Entry timestamps now stored/displayed in site time (Asia/Manila); one-time
  backfill repairs entries stamped UTC by the v0.1.1–0.1.2 bug.
* Liveblog pages use the gp-base article layout: content + rail sidebar
  (rail ad slot + article sidebar widgets; QR "scan to follow" when present).
* Special Coverage: category target is now a pick-from-dropdown of all
  categories (hierarchical, with counts) instead of typing a numeric ID.
= 0.1.2 =
* Front-end composer on the live page for Admins/Editors (in-page, role-aware);
  viewers see a "log in as Admin or Editor" notice. Live pages no longer render
  the floating LIVE button (redundant on the page itself).
= 0.1.1 =
* REST cap fixes, rewrite self-heal, ended-page schema fallback gating.
= 0.1.0 =
* Initial build per locked design (2026-09).
