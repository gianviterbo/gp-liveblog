# GP Liveblog — Product Spec (Q&A v1, 2026-09-06)

Status: SCOPE LOCKED — pending mockup approval before build. Decision log from 4 Q&A rounds with Gian.

## 1. What we're building
A **custom liveblog system inside the gp-base stack** (WordPress plugin + theme integration).
Team (Editors + Admins) publishes real-time coverage of product launches & press events
(1–3 hour bursts). Viewers watch a live, auto-updating feed. Read-only for viewers.

## 2. Confirmed decisions

### Route & architecture
- **Custom-built** (plugin + gp-base theme), NOT third-party SaaS, NOT an existing liveblog plugin.
- Liveblog = its own **page at /live/{slug}/** (custom post type or dedicated template).
- **Embeddable anywhere**: homepage, category pages, specific posts — via block/shortcode,
  "at will" placement. Featured instances are **collapsible open/closed via a button**.
- Standalone page + embed both supported (Gian: "a mix of #3 and below").

### Content & posting model
- **Team-only posting**; viewers read-only.
- Content blocks per entry (all required):
  1. Plain text / short updates (with "discuss" capability — editorial commentary)
  2. WebP images (auto-converted on upload per house policy)
  3. Link share with **auto-preview card** (any URL — self-hosted unfurl of og tags)
  4. Social post embeds with preview: **X/Twitter, Facebook, Instagram, YouTube + TikTok**
- Entry tagging/linking to existing GP content (product pages, reviews, tags) beyond raw URLs.
- **Backdating + out-of-order insert** supported (editor sets timestamp / inserts between entries).

### Authoring surfaces (both)
- **wp-admin "control room"** — one screen: entry list + quick-add box, self-refreshing.
- **Frontend overlay** — editors watch the live page and quick-post from a floating panel.
- 5+ editors may post simultaneously → conflict handling (optimistic + server-ordered).

### Permissions
- **Admin only**: create/delete liveblogs. Anything deletable by admin.
- **Editors**: post entries; edit/delete their own entries (typo fixes, retractions).
- Private **team-notes** entry type: visible only to logged-in Editors/Admins (coordination).

### Live behavior & lifecycle
- Auto-updating feed: poll every ~15–30s (cache-busted endpoint, LiteSpeed/Bunny aware).
- After event ends: **auto-mark "Event Ended"** — pause auto-refresh, keep entries readable,
  allow manual re-open. (Deliverable: silence-based or manual trigger — TBD at build.)

### SEO
- **LiveBlogPosting** schema.org structured data during the event → Google red LIVE badge.
- After end: flip to standard article markup (no stale LIVE badge). Indexing of finished
  liveblogs: treated as normal coverage (final decision pending — see Open Items).

### Visual design
- **Match gp-base design system** (tokens, dark/light aware) — native feel, not a foreign widget.
- Open/close (and general live) button: **VengeanceUI "generate-button" aesthetic** ported to
  GP tokens — dark pill, layered inner shadows, GP-accent hue highlight sweep, shimmer letter
  animation for "live/active" state. Pure CSS port (no React) — reference captured in
  /tmp/generate-button.json.

## 3. Architecture sketch (for build planning)

```
Plugin: gp-liveblog
├── CPT: gp_liveblog (the event page, /live/slug/)      [admin create/delete]
├── CPT: gp_liveblog_entry (children of a liveblog)      [admin + editors]
│     postmeta: entry_type (update|image|link|social|team_note|product)
│     postmeta: display_ts (for backdating/insert order)
│     terms:    gp product/category tags for GP-content linking
├── REST/AJAX: GET entries?after_id=N (cache-busted, 15-30s poll)
│              POST entry (frontend overlay + control room)
├── Unfurl service: server-side og: scrape → preview card (link + social)
├── Image: upload → house WebP conversion (policy) → attach to entry
├── Social embed: oEmbed-ish render for X/FB/IG/YT/TikTok
├── Schema: LiveBlogPosting on live page → NewsArticle when ended
└── Theme (gp-base):
      ├── /live/slug template + entry timeline partial
      ├── gp_liveblog embed block/shortcode (collapsible, open/closed button)
      ├── control-room admin screen (enqueue only for roles w/ capability)
      └── frontend overlay panel (role-gated)
```

## 4. Open items (small, resolve at mockup/build time)
- Finished-liveblog indexing: index like a normal post vs noindex vs summary-post-hybrid.
- "Event Ended" trigger: auto-silence timer vs manual button (defaults: manual + optional auto).
- Team-note retention: auto-purge after event or keep in admin history?
- Poll interval: 15s vs 30s (bandwidth vs freshness) — default 20s, configurable.

## 5. Build order (after mockup approval)
1. HTML mockups: live page, embedded/collapsed widget + generate-button port, control room,
   frontend overlay → **Gian locks design**
2. Plugin skeleton: CPTs, roles/caps, entry REST endpoints
3. Composer: text/image/link/social + team-note + backdate/insert
4. Frontend: timeline + poll + overlay + embed block + schema
5. Cache/CDN strategy pass + load test (5 concurrent editors)
6. Deploy discipline per house rules (staging → validate → live)
