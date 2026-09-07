# gp-base theme — LIVE COVERAGE button in Special Coverage card (server-side)

**Status:** SHIPPED in gp-base **v1.24** (repo commit 83656ae, 2026-09-07).
Theme deployed to prod pending the usual gated release. Until the theme deploy,
the gp-liveblog plugin renders the button via its JS bridge
(`window.gplbCoverage` → injects into `.gp-coverage-card`) — same visual, works
with zero theme changes. This patch makes it server-rendered (no JS dependency,
better for crawlers/AMP) and is the long-term home.

## Files touched
1. `inc/coverage.php` — render hook inside the card body
2. `assets/css/main.css` — styles for the injected button wrapper

---

## 1. inc/coverage.php

In `gp_base_coverage_render()`, inside `.cov-body` after the `.cov-cta` anchor
(around line 200, before `</div>` closing `.cov-body`), add:

```php
					<?php
					/**
					 * Hook for add-ons (e.g. gp-liveblog): render a LIVE COVERAGE
					 * button inside the Special Coverage card when an active
					 * liveblog targets the same featured page/post/category.
					 *
					 * @param array $d Coverage render data (mode/url/heading).
					 */
					do_action( 'gp_base_coverage_card_actions', $d );
					?>
```

Resulting region:

```php
					<a class="cov-cta" href="<?php echo esc_url( $d['url'] ); ?>">
						<?php
						if ( $d['is_event'] ) {
							esc_html_e( 'View full coverage →', 'gp-base' );
						} elseif ( 'post' === $d['mode'] ) {
							esc_html_e( 'Read the story →', 'gp-base' );
						} elseif ( $d['is_cat'] ) {
							esc_html_e( 'Browse the category →', 'gp-base' );
						} else {
							esc_html_e( 'Open page →', 'gp-base' );
						}
						?>
					</a>
					<?php do_action( 'gp_base_coverage_card_actions', $d ); // gp-liveblog LIVE COVERAGE button ?>
				</div>
```

## 2. Plugin-side renderer (ships in gp-liveblog 0.2, uses the hook)

```php
add_action( 'gp_base_coverage_card_actions', function ( $d ) {
	$lb = null;
	foreach ( gplb_active_liveblogs() as $p ) {
		$tm = get_post_meta( $p->ID, '_gplb_coverage_mode', true );
		$ti = (int) get_post_meta( $p->ID, '_gplb_coverage_id', true );
		if ( $tm === $d['mode'] && $ti === (int) ( $d['id'] ?? 0 ) ) { $lb = $p; break; }
	}
	if ( ! $lb ) { return; }
	// render the generate-button "LIVE COVERAGE" anchor → microsite
	echo gplb_render_coverage_button( $lb->ID, get_permalink( $lb->ID ) );
} );
```

## 3. assets/css/main.css

Append (both themes inherit these tokens):

```css
/* gp-liveblog LIVE COVERAGE button inside the coverage card */
.gp-coverage-card .cov-actions-gplb { margin-top: 12px; }
.gp-coverage-card .cov-actions-gplb .gplb-embed-toggle { display: inline-flex; }
```

## Test after apply
1. Liveblog live + coverage target matches → open card → LIVE COVERAGE button
   present server-side in HTML (grep `LIVE COVERAGE`).
2. Liveblog ended / target mismatch → button absent (grep = 0).
3. Purge LiteSpeed + Bunny after theme deploy.
