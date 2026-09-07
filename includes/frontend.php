<?php
/**
 * GP Liveblog — frontend: live page template, renderers, float button, embeds.
 */

defined( 'ABSPATH' ) || exit;

/* ── Live page: route single-gp_liveblog to our template ─────────────── */
function gplb_template( $template ) {
	if ( is_singular( 'gp_liveblog' ) ) {
		$t = GPLB_DIR . 'templates/single-liveblog.php';
		if ( file_exists( $t ) ) { return $t; }
	}
	return $template;
}
add_filter( 'template_include', 'gplb_template' );

/* Staff marker on live pages (Admins/Editors): enables the in-page composer. */
function gplb_body_class( $classes ) {
	if ( is_singular( 'gp_liveblog' ) && gplb_editor_can() ) {
		$classes[] = 'gplb-canpost';
	}
	return $classes;
}
add_filter( 'body_class', 'gplb_body_class' );

/* ── Assets ──────────────────────────────────────────────────────────── */

/* QR Code Composer support on live pages: the theme only loads the QR
   library + painter on regular posts, so live pages load the same assets
   for the rail "scan to follow" box. Mirrors gp_base_qr_assets(). */
function gplb_qr_assets() {
	if ( ! is_singular( 'gp_liveblog' ) ) { return; }
	if ( ! is_dir( WP_PLUGIN_DIR . '/qr-code-composer' ) ) { return; }
	if ( wp_script_is( 'qrccreateqr-js' ) ) { return; } // plugin already handled it
	wp_enqueue_script( 'gp-qr-lib', plugins_url( 'qr-code-composer/admin/js/qr-code-styling.js' ), array(), '1.0', true );
	wp_enqueue_script( 'gp-qr-create', plugins_url( 'qr-code-composer/public/js/qrcode.js' ), array( 'jquery', 'gp-qr-lib' ), '1.0', true );
	wp_add_inline_script( 'gp-qr-create', 'var datas = ' . wp_json_encode( array(
		'size'       => 200,
		'shape'      => 'square',
		'color'      => '#000',
		'background' => 'transparent',
		'quiet'      => 0,
		'ecLevel'    => 'L',
	) ) . ';', 'before' );
}
add_action( 'wp_enqueue_scripts', 'gplb_qr_assets', 20 );

function gplb_assets() {
	$active = gplb_active_liveblogs();
	$on_live_page = is_singular( 'gp_liveblog' );

	// Embeds inside regular content also need the assets.
	$has_embed = false;
	if ( is_singular() ) {
		$post = get_post();
		if ( $post ) {
			$has_embed = has_shortcode( $post->post_content, 'gp_liveblog' ) || has_block( 'gp-liveblog/embed', $post );
		}
	}

	if ( ! $active && ! $on_live_page && ! $has_embed ) { return; } // nothing to show

	wp_enqueue_style( 'gplb', GPLB_URL . 'assets/css/liveblog.css', array(), GPLB_VERSION );
	wp_enqueue_script( 'gplb', GPLB_URL . 'assets/js/liveblog.js', array(), GPLB_VERSION, true );

	$live = array();
	foreach ( $active as $p ) {
		$live[] = array(
			'id'        => (int) $p->ID,
			'title'     => get_the_title( $p->ID ),
			'permalink' => get_permalink( $p->ID ),
			'subtitle'  => get_post_meta( $p->ID, '_gplb_subtitle', true ),
		);
	}
	wp_localize_script( 'gplb', 'GPLB', array(
		'rest'    => esc_url_raw( rest_url( GPLB_REST ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'canPost' => gplb_editor_can(),
		'isAdmin' => gplb_admin_can(),
		'active'  => $live,
		'current' => $on_live_page ? (int) get_queried_object_id() : 0,
		'i18n'    => array(
			'live'        => __( 'LIVE', 'gp-liveblog' ),
			'openFull'    => __( 'Open full liveblog', 'gp-liveblog' ),
			'watching'    => __( 'watching', 'gp-liveblog' ),
			'postUpdate'  => __( 'Post an update to viewers…', 'gp-liveblog' ),
			'publish'     => __( 'Publish', 'gp-liveblog' ),
			'update'      => __( 'Update', 'gp-liveblog' ),
			'image'       => __( 'Image', 'gp-liveblog' ),
			'link'        => __( 'Link', 'gp-liveblog' ),
			'social'      => __( 'Social', 'gp-liveblog' ),
			'teamNote'    => __( 'Team note', 'gp-liveblog' ),
			'media'       => __( 'Media', 'gp-liveblog' ),
			'placeholder' => __( 'Paste a URL to share with preview…', 'gp-liveblog' ),
			'liveUpdates' => __( 'Live updates', 'gp-liveblog' ),
			'syncing'     => __( 'syncing', 'gp-liveblog' ),
			'ended'       => __( 'Coverage ended', 'gp-liveblog' ),
			'editingAs'   => __( 'Editing as', 'gp-liveblog' ),
		),
	) );
}
add_action( 'wp_enqueue_scripts', 'gplb_assets' );

/* ── Renderers ───────────────────────────────────────────────────────── */

function gplb_render_entry( $e ) {
	$type = $e['type'];
	$who  = esc_html( $e['author'] );
	$time = esc_html( $e['ts_h'] );
	$chip = gplb_entry_types()[ $type ] ?? $type;
	$cls  = sanitize_html_class( $type );

	$body = '';
	if ( 'image' === $type && ! empty( $e['meta']['image_id'] ) ) {
		$img = wp_get_attachment_image( $e['meta']['image_id'], 'large' );
		$body .= $e['content'] ? '<p>' . wp_kses_post( $e['raw'] ) . '</p>' : '';
		$body .= $img ? '<figure>' . $img . '</figure>' : '';
	} elseif ( 'link' === $type || 'social' === $type ) {
		$card = ! empty( $e['meta']['link_card'] ) && is_array( $e['meta']['link_card'] ) ? $e['meta']['link_card'] : array();
		$url  = 'social' === $type ? ( $e['meta']['social_url'] ?: '' ) : ( $e['meta']['link_url'] ?: '' );
		if ( $e['content'] ) { $body .= '<p>' . wp_kses_post( $e['raw'] ) . '</p>'; }
		$media = ! empty( $e['meta']['media'] ) && is_array( $e['meta']['media'] ) ? $e['meta']['media'] : null;
		$body .= gplb_render_link_card( $card, $url, $type, $media );
	} else {
		$body .= wp_kses_post( $e['content'] );
	}

	$note = ( 'note' === $type ) ? ' gplb-note' : '';
	$tag  = ( 'note' === $type ) ? '<span class="gplb-lock" aria-hidden="true">🔒</span> ' : '';

	// Viewer reactions (skipped on private team notes).
	$foot = '';
	if ( 'note' !== $type ) {
		$tot   = ! empty( $e['reactions'] ) && is_array( $e['reactions'] ) ? $e['reactions'] : array();
		$total = (int) ( $e['reactions_total'] ?? array_sum( $tot ) );
		$foot  = '<footer class="gplb-react" data-entry="' . (int) $e['id'] . '">';
		foreach ( gplb_reactions() as $rk => $re ) {
			$n    = (int) ( $tot[ $rk ] ?? 0 );
			$foot .= '<button type="button" class="gplb-react-btn" data-emoji="' . esc_attr( $rk ) . '" aria-label="' . esc_attr( $rk ) . '" title="' . esc_attr( $rk ) . '"><span class="gplb-react-ico" aria-hidden="true">' . $re . '</span><span class="gplb-react-n">' . number_format_i18n( $n ) . '</span></button>';
		}
		$foot .= '<span class="gplb-react-total" hidden>' . number_format_i18n( $total ) . '</span></footer>';
	}

	return sprintf(
		'<article class="gplb-entry gplb-%s%s" data-id="%d" data-type="%s"><header class="gplb-entry-head"><span class="gplb-who">%s</span><span class="gplb-chip gplb-chip-%s">%s%s</span><time class="gplb-time">%s</time></header><div class="gplb-entry-body">%s</div>%s</article>',
		sanitize_html_class( $type ), $note, (int) $e['id'], esc_attr( $type ),
		$who, sanitize_html_class( $type ), $tag, esc_html( $chip ), $time, $body, $foot
	);
}

function gplb_render_link_card( $card, $url, $type = 'link', $media = null ) {
	// Rich media preview (YouTube/TikTok/Instagram) — thumbnail card that
	// plays inline on click (YouTube) or opens the platform post.
	if ( $media && ! empty( $media['type'] ) && in_array( $media['type'], array( 'youtube', 'tiktok', 'instagram' ), true ) && $url ) {
		$img   = ! empty( $media['image'] ) ? '<img src="' . esc_url( $media['image'] ) . '" alt="" loading="lazy">' : '';
		$title = ! empty( $media['title'] ) ? $media['title'] : ( $card['title'] ?? '' );
		$host  = ! empty( $media['author'] ) ? $media['author'] : ( 'youtube' === $media['type'] ? 'YouTube' : ucfirst( $media['type'] ) );
		$embed = esc_url( $media['embed'] ?? '' );
		$play  = '<span class="gplb-media-play" aria-hidden="true">▶</span>';
		$extra = 'youtube' === $media['type'] ? ' gplb-media--yt' : '';
		return sprintf(
			'<div class="gplb-media%s" data-media="%s" data-embed="%s"><a class="gplb-media-thumb" href="%s" target="_blank" rel="noopener nofollow">%s%s</a><a class="gplb-media-meta" href="%s" target="_blank" rel="noopener nofollow"><span class="gplb-card-host">%s</span><span class="gplb-media-title">%s</span></a></div>',
			esc_attr( $extra ), esc_attr( $media['type'] ), $embed,
			esc_url( $url ), $img, $play,
			esc_url( $url ), esc_html( $host ), esc_html( $title )
		);
	}
	if ( ! $card || empty( $card['title'] ) ) {
		return '<a class="gplb-card" href="' . esc_url( $url ) . '" rel="nofollow noopener" target="_blank">' . esc_html( $url ) . '</a>';
	}
	$img = ! empty( $card['image'] ) ? '<img src="' . esc_url( $card['image'] ) . '" alt="" loading="lazy">' : '';
	$site = esc_html( $card['site'] ?? '' );
	$host = $site ?: parse_url( $url, PHP_URL_HOST );
	$icon = 'social' === $type ? '<span class="gplb-card-icon">𝕏</span>' : '';
	return sprintf(
		'<a class="gplb-card%s" href="%s" rel="nofollow noopener" target="_blank">%s<div class="gplb-card-body"><span class="gplb-card-host">%s%s</span><span class="gplb-card-title">%s</span>%s</div></a>',
		'social' === $type ? ' gplb-card--social' : '',
		esc_url( $url ),
		$img ? '<span class="gplb-card-img">' . $img . '</span>' : '',
		$icon, esc_html( $host ),
		esc_html( $card['title'] ),
		! empty( $card['description'] ) ? '<span class="gplb-card-desc">' . esc_html( wp_trim_words( $card['description'], 22, '…' ) ) . '</span>' : ''
	);
}

function gplb_pinned_video_embed( $p ) {
	$src  = esc_url( $p['embed'] );
	$t    = 'youtube' === $p['type'] ? 'YouTube' : ucfirst( $p['type'] );
	return '<iframe title="' . esc_attr( $t ) . '" src="' . $src . '" width="100%" height="100%" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen loading="lazy"></iframe>';
}

/* ── Floating LIVE button + panel (all pages while coverage is live) ─── */
function gplb_float_markup() {
	if ( ! gplb_active_liveblogs() ) { return; }
	// Inside the full liveblog page the floating button is redundant (the page
	// itself is the live coverage) — skip it there, including for ended pages.
	if ( is_singular( 'gp_liveblog' ) ) { return; }
	?>
	<div class="gplb-float" id="gplbFloat" hidden>
		<div class="gplb-panel" id="gplbPanel" hidden>
			<header class="gplb-panel-head">
				<span class="gplb-live-dot" aria-hidden="true"></span>
				<div class="gplb-panel-titles"><strong id="gplbPanelTitle"><?php esc_html_e( 'Live', 'gp-liveblog' ); ?></strong><span id="gplbPanelSub"></span></div>
				<button class="gplb-panel-x" id="gplbPanelX" type="button" aria-label="<?php esc_attr_e( 'Close', 'gp-liveblog' ); ?>">×</button>
			</header>
			<div class="gplb-composer" id="gplbComposer" hidden>
				<textarea id="gplbText" rows="2" placeholder="<?php esc_attr_e( 'Post an update to viewers…', 'gp-liveblog' ); ?>"></textarea>
				<div class="gplb-tools" id="gplbTools">
					<button type="button" class="gplb-tool is-on" data-type="update">✍ <span><?php esc_html_e( 'Update', 'gp-liveblog' ); ?></span></button>
					<button type="button" class="gplb-tool" data-type="image">🖼</button>
					<button type="button" class="gplb-tool" data-type="link">🔗</button>
					<button type="button" class="gplb-tool" data-type="social">𝕏</button>
					<button type="button" class="gplb-tool gplb-tool-note" data-type="note">🔒 <span><?php esc_html_e( 'Team', 'gp-liveblog' ); ?></span></button>
				</div>
				<div class="gplb-urlrow" id="gplbUrlRow" hidden>
					<input type="url" id="gplbUrl" placeholder="<?php esc_attr_e( 'Paste a URL to share with preview…', 'gp-liveblog' ); ?>">
				</div>
				<div class="gplb-imgrow" id="gplbImgRow" hidden>
					<input type="file" id="gplbImg" accept="image/jpeg,image/png,image/webp">
				</div>
				<div class="gplb-postrow">
					<button class="gplb-btn gplb-btn-primary" id="gplbPublish" type="button"><?php esc_html_e( 'Publish', 'gp-liveblog' ); ?></button>
					<span class="gplb-status" id="gplbStatus" role="status"></span>
				</div>
			</div>
			<div class="gplb-feed" id="gplbFeed"><div class="gplb-feed-label"><span><?php esc_html_e( 'Live updates', 'gp-liveblog' ); ?></span><span class="gplb-sync">● <?php esc_html_e( 'syncing', 'gp-liveblog' ); ?></span></div><div id="gplbFeedList"></div></div>
			<footer class="gplb-panel-foot">
				<span class="gplb-watching"><span class="gplb-live-dot"></span><span id="gplbWatching">0</span> <?php esc_html_e( 'watching', 'gp-liveblog' ); ?></span>
				<a class="gplb-open-full" id="gplbOpenFull" href="#"><?php esc_html_e( 'Open full liveblog', 'gp-liveblog' ); ?> ↗</a>
			</footer>
		</div>
		<button class="gplb-float-btn" id="gplbFloatBtn" type="button" aria-expanded="false" aria-controls="gplbPanel">
			<span class="gplb-live-dot"></span><span class="gplb-float-label"><?php esc_html_e( 'LIVE', 'gp-liveblog' ); ?></span><span class="gplb-float-count" id="gplbFloatCount" hidden>0</span>
		</button>
	</div>
	<?php
}
add_action( 'wp_footer', 'gplb_float_markup' );

/* ── Embed shortcode [gp_liveblog id=123 collapsed=1] ────────────────── */
function gplb_embed_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0, 'collapsed' => 1 ), $atts, 'gp_liveblog' );
	$id = (int) $atts['id'];
	if ( ! $id || 'gp_liveblog' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
		return '';
	}
	$live = gplb_is_live( $id );
	$title = get_the_title( $id );
	$perm = get_permalink( $id );
	$entries = gplb_get_entries( $id, 0, 6, gplb_editor_can() );
	$html = '';
	foreach ( $entries as $e ) { $html .= gplb_render_entry( $e ); }

	ob_start();
	?>
	<div class="gplb-embed<?php echo $live ? ' is-live' : ' is-ended'; ?>" data-id="<?php echo (int) $id; ?>" data-live="<?php echo $live ? '1' : '0'; ?>">
		<button class="gplb-embed-toggle" type="button" aria-expanded="<?php echo $atts['collapsed'] ? 'false' : 'true'; ?>">
			<span class="gplb-live-dot"></span>
			<span class="gplb-embed-label"><span class="gplb-embed-open"><?php echo $live ? esc_html__( 'Open live coverage', 'gp-liveblog' ) : esc_html__( 'Read the liveblog', 'gp-liveblog' ); ?></span></span>
			<span class="gplb-embed-caret">▾</span>
		</button>
		<div class="gplb-embed-panel"<?php echo $atts['collapsed'] ? ' hidden' : ''; ?>>
			<header class="gplb-embed-head">
				<span class="gplb-live-pill"><?php echo $live ? esc_html__( 'LIVE', 'gp-liveblog' ) : esc_html__( 'ENDED', 'gp-liveblog' ); ?></span>
				<a class="gplb-embed-title" href="<?php echo esc_url( $perm ); ?>"><?php echo esc_html( $title ); ?></a>
			</header>
			<div class="gplb-embed-entries"><?php echo $html; // phpcs:ignore -- rendered above ?></div>
			<footer class="gplb-embed-foot"><a href="<?php echo esc_url( $perm ); ?>"><?php esc_html_e( 'Open full liveblog page', 'gp-liveblog' ); ?> →</a></footer>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'gp_liveblog', 'gplb_embed_shortcode' );

/* Register a simple block wrapper for the shortcode. */
function gplb_block() {
	wp_register_script( 'gplb-block', GPLB_URL . 'assets/js/block.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor' ), GPLB_VERSION, true );
	register_block_type( 'gp-liveblog/embed', array(
		'api_version'     => 2,
		'editor_script'   => 'gplb-block',
		'attributes'      => array(
			'id'        => array( 'type' => 'number', 'default' => 0 ),
			'collapsed' => array( 'type' => 'boolean', 'default' => true ),
		),
		'render_callback' => function ( $atts ) {
			return gplb_embed_shortcode( $atts );
		},
	) );
}
add_action( 'init', 'gplb_block' );

/* Expose liveblog picker data for the block + coverage bridge. */
function gplb_liveblog_choices() {
	$q = get_posts( array( 'post_type' => 'gp_liveblog', 'post_status' => 'any', 'numberposts' => 100, 'orderby' => 'date', 'order' => 'DESC' ) );
	$out = array();
	foreach ( $q as $p ) { $out[] = array( 'id' => (int) $p->ID, 'title' => get_the_title( $p->ID ), 'live' => gplb_is_live( $p->ID ) ); }
	return $out;
}

/* ── Coverage-card bridge: if a live liveblog targets the same featured
      page/post/category as the theme's Special Coverage, tell JS to add the
      LIVE COVERAGE button inside the card. Theme hook preferred when present. ── */

/** Render the generate-button "LIVE COVERAGE" anchor (card + anywhere). */
function gplb_render_coverage_button( $liveblog_id, $url, $label = '' ) {
	$label = $label ? $label : __( 'LIVE COVERAGE', 'gp-liveblog' );
	return sprintf(
		'<a class="gplb-embed-toggle gplb-coverage-btn" href="%s" style="margin-top:12px;text-decoration:none;font-size:13px"><span class="gplb-live-dot"></span><span class="gplb-embed-label">%s</span><span aria-hidden="true">↗</span></a>',
		esc_url( $url ),
		esc_html( $label )
	);
}

/* Server-side hook renderer — active when the theme ships the
   gp_base_coverage_card_actions hook (patch in theme-integration/). */
add_action( 'gp_base_coverage_card_actions', function () {
	$t = gplb_coverage_target_id();
	foreach ( gplb_active_liveblogs() as $p ) {
		$tm = get_post_meta( $p->ID, '_gplb_coverage_mode', true );
		$ti = (int) get_post_meta( $p->ID, '_gplb_coverage_id', true );
		if ( $tm === $t['mode'] && $ti === $t['id'] ) {
			echo gplb_render_coverage_button( $p->ID, get_permalink( $p->ID ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built above
			break;
		}
	}
} );

function gplb_coverage_target_id() {
	$mode = get_theme_mod( 'gp_coverage_mode', 'page' );
	$map  = array( 'page' => 'gp_coverage_page', 'post' => 'gp_coverage_post_id', 'category' => 'gp_coverage_category' );
	return array(
		'mode' => $mode,
		'id'   => (int) get_theme_mod( $map[ $mode ] ?? 'gp_coverage_page', 0 ),
	);
}

function gplb_active_liveblog_for_coverage() {
	$t = gplb_coverage_target_id();
	if ( ! $t['id'] ) { return null; }
	foreach ( gplb_active_liveblogs() as $p ) {
		$tm = get_post_meta( $p->ID, '_gplb_coverage_mode', true );
		$ti = (int) get_post_meta( $p->ID, '_gplb_coverage_id', true );
		if ( $tm && $ti && $tm === $t['mode'] && $ti === $t['id'] ) {
			return $p;
		}
	}
	return null;
}

add_action( 'wp_footer', function () {
	$lb = gplb_active_liveblog_for_coverage();
	if ( ! $lb ) { return; }
	?>
	<script>(window.gplbCoverage = { id: <?php echo (int) $lb->ID; ?>, url: <?php echo wp_json_encode( get_permalink( $lb->ID ) ); ?>, title: <?php echo wp_json_encode( get_the_title( $lb->ID ) ); ?> });</script>
	<?php
}, 5 );
