<?php
/**
 * Plugin Name: GP Liveblog
 * Description: Real-time live coverage for launches & press events — team-authored entries (text, WebP images, link cards, social embeds, private team notes), auto-updating viewer feed, floating LIVE panel, collapsible embeds, LiveBlogPosting schema. Editors post from wp-admin control room or a frontend overlay; Admin owns liveblog lifecycle.
 * Version: 0.1.4
 * Author: Gadget Pilipinas
 * Text Domain: gp-liveblog
 *
 * @package GP_Liveblog
 */

defined( 'ABSPATH' ) || exit;

define( 'GPLB_VERSION', '0.1.4' );
define( 'GPLB_FILE', __FILE__ );
define( 'GPLB_DIR', plugin_dir_path( __FILE__ ) );
define( 'GPLB_URL', plugin_dir_url( __FILE__ ) );
define( 'GPLB_REST', 'gp-liveblog/v1' );

require_once GPLB_DIR . 'includes/rest.php';
require_once GPLB_DIR . 'includes/frontend.php';
require_once GPLB_DIR . 'includes/admin.php';

/* ── Post types ──────────────────────────────────────────────────────── */

function gplb_register_post_types() {
	register_post_type( 'gp_liveblog', array(
		'labels'       => array(
			'name'          => __( 'Liveblogs', 'gp-liveblog' ),
			'singular_name' => __( 'Liveblog', 'gp-liveblog' ),
			'add_new_item'  => __( 'Add New Liveblog', 'gp-liveblog' ),
			'edit_item'     => __( 'Edit Liveblog', 'gp-liveblog' ),
			'menu_name'     => __( 'Liveblogs', 'gp-liveblog' ),
		),
		'public'       => true,
		'show_in_rest' => true,
		'menu_icon'    => 'dashicons-video-alt3',
		'menu_position' => 27,
		'rewrite'      => array( 'slug' => 'live', 'with_front' => false ),
		'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'custom-fields' ),
		'has_archive'  => false,
		'map_meta_cap' => true,
		'capability_type' => array( 'gplb_liveblog', 'gplb_liveblogs' ),
	) );

	register_post_type( 'gp_liveblog_entry', array(
		'labels'       => array(
			'name'          => __( 'Liveblog Entries', 'gp-liveblog' ),
			'singular_name' => __( 'Liveblog Entry', 'gp-liveblog' ),
		),
		'public'       => false,       // never a public URL of its own
		'show_ui'      => false,       // managed via the control room
		'show_in_rest' => true,
		'supports'     => array( 'title', 'editor', 'author', 'custom-fields' ),
		'map_meta_cap' => true,
		'capability_type' => array( 'gplb_entry', 'gplb_entries' ),
	) );
}
add_action( 'init', 'gplb_register_post_types' );

/* Capabilities: Admins manage liveblogs; Editors post entries. */
function gplb_caps() {
	$admin = get_role( 'administrator' );
	$editor = get_role( 'editor' );
	foreach ( array( $admin, $editor ) as $role ) {
		if ( ! $role ) { continue; }
		$role->add_cap( 'edit_gplb_liveblogs' );
		$role->add_cap( 'edit_gplb_liveblog' );
		$role->add_cap( 'edit_gplb_entries' );
		$role->add_cap( 'edit_gplb_entry' );
		$role->add_cap( 'publish_gplb_entries' );
		$role->add_cap( 'edit_published_gplb_entries' );
		$role->add_cap( 'delete_gplb_entries' );
	}
	if ( $admin ) {
		$admin->add_cap( 'publish_gplb_liveblogs' );
		$admin->add_cap( 'edit_others_gplb_liveblogs' );
		$admin->add_cap( 'edit_published_gplb_liveblogs' );
		$admin->add_cap( 'delete_gplb_liveblogs' );
		$admin->add_cap( 'delete_published_gplb_liveblogs' );
		$admin->add_cap( 'delete_others_gplb_liveblogs' );
		$admin->add_cap( 'delete_gplb_entries' );
		$admin->add_cap( 'delete_published_gplb_entries' );
		$admin->add_cap( 'delete_others_gplb_entries' );
		$admin->add_cap( 'edit_others_gplb_entries' );
	}
	if ( $editor ) {
		$editor->add_cap( 'edit_published_gplb_liveblogs' );
		$editor->add_cap( 'delete_gplb_entries' );
		$editor->add_cap( 'delete_published_gplb_entries' );
	}
	update_option( 'gplb_caps_done', GPLB_VERSION, false );
}

/* Add caps on init (fires for REST + frontend too, unlike admin_init) but only
   run the DB writes when the stored cap version differs from the plugin version. */
function gplb_maybe_caps() {
	if ( get_option( 'gplb_caps_done', 0 ) !== GPLB_VERSION ) {
		gplb_caps();
	}
	// Self-healing: ensure the /live/ rewrite rule exists (fresh deploy or
	// permalink structure change). Cheap: one autoloaded option read.
	if ( ! gplb_rewrite_rule_ok() ) {
		flush_rewrite_rules();
	}
}

function gplb_rewrite_rule_ok() {
	$rules = get_option( 'rewrite_rules', array() );
	return is_array( $rules ) && isset( $rules['live/([^/]+)/?$'] );
}
add_action( 'init', 'gplb_maybe_caps' );
register_activation_hook( GPLB_FILE, 'gplb_caps' );

/* One-time repair (v0.1.3): pre-fix entries were stamped with UTC in BOTH
   post_date and post_date_gmt (rest.php bug), so get_the_date() showed UTC.
   Rebuild post_date (local) from the GMT value. Runs once, when an admin
   loads any page after the upgrade; capped at 500/pass. */
function gplb_backfill_entry_times() {
	if ( get_option( 'gplb_ts_fix_done', '' ) === GPLB_VERSION ) { return; }
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	global $wpdb;
	$offset = (int) wp_timezone()->getOffset( new DateTimeImmutable( 'now' ) ); // seconds
	$ids    = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_type = 'gp_liveblog_entry'
		   AND post_date_gmt <> '0000-00-00 00:00:00'
		   AND post_date = post_date_gmt
		 LIMIT 500"
	);
	foreach ( $ids as $eid ) {
		$gmt   = get_post_field( 'post_date_gmt', (int) $eid );
		$local = gmdate( 'Y-m-d H:i:s', strtotime( $gmt ) + $offset );
		// Only post_date — WP recomputes post_date_gmt (unchanged).
		wp_update_post( array( 'ID' => (int) $eid, 'post_date' => $local ) );
	}
	update_option( 'gplb_ts_fix_done', GPLB_VERSION, false );
}
add_action( 'init', 'gplb_backfill_entry_times' );

/* ── Entry type taxonomy-ish constants ───────────────────────────────── */
function gplb_entry_types() {
	return array(
		'update' => __( 'Update', 'gp-liveblog' ),
		'image'  => __( 'Image', 'gp-liveblog' ),
		'link'   => __( 'Link', 'gp-liveblog' ),
		'social' => __( 'Social', 'gp-liveblog' ),
		'note'   => __( 'Team note', 'gp-liveblog' ),
	);
}

/* ── Lifecycle helpers ───────────────────────────────────────────────── */

function gplb_live_status( $liveblog_id ) {
	return get_post_meta( $liveblog_id, '_gplb_status', true ) ?: 'ended';
}
function gplb_is_live( $liveblog_id ) {
	$s = gplb_live_status( $liveblog_id );
	if ( 'live' !== $s ) { return false; }
	$started = (int) get_post_meta( $liveblog_id, '_gplb_started', true );
	if ( ! $started ) { return false; }
	// Auto-end: 2h after last entry (configurable via filter).
	$last = (int) get_post_meta( $liveblog_id, '_gplb_last_entry', true );
	$ref  = $last ? $last : $started;
	$ttl  = (int) apply_filters( 'gplb_auto_end_seconds', 2 * HOUR_IN_SECONDS, $liveblog_id );
	if ( time() - $ref > $ttl ) {
		update_post_meta( $liveblog_id, '_gplb_status', 'ended' );
		update_post_meta( $liveblog_id, '_gplb_ended', time() );
		return false;
	}
	return true;
}

/** All liveblogs currently live (cached 30s so the float button is cheap). */
function gplb_active_liveblogs() {
	static $cached = null;
	if ( null !== $cached ) { return $cached; }
	$q = new WP_Query( array(
		'post_type'      => 'gp_liveblog',
		'post_status'    => 'publish',
		'posts_per_page' => 10,
		'no_found_rows'  => true,
		'meta_query'     => array( array( 'key' => '_gplb_status', 'value' => 'live' ) ),
	) );
	$out = array();
	foreach ( $q->posts as $p ) {
		if ( gplb_is_live( $p->ID ) ) { $out[] = $p; }
	}
	$cached = $out;
	return $out;
}

function gplb_start_liveblog( $id ) {
	update_post_meta( $id, '_gplb_status', 'live' );
	update_post_meta( $id, '_gplb_started', time() );
	update_post_meta( $id, '_gplb_last_entry', time() );
}
function gplb_end_liveblog( $id ) {
	update_post_meta( $id, '_gplb_status', 'ended' );
	update_post_meta( $id, '_gplb_ended', time() );
}

/* Track last-entry time on publish (for auto-end). */
add_action( 'wp_after_insert_post', function ( $post_id, $post ) {
	if ( 'gp_liveblog_entry' === ( $post->post_type ?? '' ) && 'publish' === ( $post->post_status ?? '' ) && $post->post_parent ) {
		update_post_meta( $post->post_parent, '_gplb_last_entry', time() );
	}
}, 10, 2 );

/* ── Entry helpers ───────────────────────────────────────────────────── */

/**
 * Fetch entries for a liveblog, newest first, optionally after an entry id
 * (polling) and/or type filter. Team notes only returned to editors/admins.
 */
function gplb_get_entries( $liveblog_id, $after_id = 0, $limit = 60, $include_notes = true ) {
	$q = new WP_Query( array(
		'post_type'      => 'gp_liveblog_entry',
		'post_status'    => 'publish',
		'post_parent'    => (int) $liveblog_id,
		'posts_per_page' => (int) $limit,
		'no_found_rows'  => true,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
	$out = array();
	foreach ( $q->posts as $e ) {
		if ( $after_id && (int) $e->ID <= (int) $after_id ) { continue; }
		$type = get_post_meta( $e->ID, '_gplb_type', true ) ?: 'update';
		if ( 'note' === $type && ! $include_notes ) { continue; }
		$out[] = gplb_entry_shape( $e, $type );
	}
	return $out;
}

/** Render shape used by REST + server-side render. */
function gplb_entry_shape( $e, $type = null ) {
	$type = $type ?: ( get_post_meta( $e->ID, '_gplb_type', true ) ?: 'update' );
	$author = get_userdata( (int) $e->post_author );
	return array(
		'id'        => (int) $e->ID,
		'type'      => $type,
		'author'    => $author ? $author->display_name : __( 'GP Staff', 'gp-liveblog' ),
		'author_id' => (int) $e->post_author,
		'ts'        => get_the_date( 'c', $e->ID ),
		'ts_h'      => get_the_date( 'g:i A', $e->ID ),
		'content'   => wpautop( wp_kses_post( $e->post_content ) ),
		'raw'       => $e->post_content,
		'meta'      => array(
			'link_url'   => get_post_meta( $e->ID, '_gplb_link_url', true ),
			'link_card'  => get_post_meta( $e->ID, '_gplb_link_card', true ), // serialized unfurl
			'social_url' => get_post_meta( $e->ID, '_gplb_social_url', true ),
			'image_id'   => (int) get_post_meta( $e->ID, '_gplb_image_id', true ),
			'image_url'  => (int) get_post_meta( $e->ID, '_gplb_image_id', true ) ? wp_get_attachment_image_url( (int) get_post_meta( $e->ID, '_gplb_image_id', true ), 'large' ) : '',
		),
	);
}

/* ── Schema: LiveBlogPosting while live, NewsArticle after ───────────── */

add_filter( 'rank_math/json_ld', function ( $data, $jsonld ) {
	if ( ! is_singular( 'gp_liveblog' ) ) { return $data; }
	$id = get_queried_object_id();
	if ( gplb_is_live( $id ) ) {
		$data['liveblog'] = array(
			'@type'            => 'LiveBlogPosting',
			'headline'         => get_the_title( $id ),
			'coverageStartTime' => gmdate( 'c', (int) get_post_meta( $id, '_gplb_started', true ) ),
			'coverageEndTime'  => '',
			'liveBlogUpdate'   => array(
				'@type' => 'BlogPosting',
				'dateModified' => gmdate( 'c', (int) get_post_meta( $id, '_gplb_last_entry', true ) ?: time() ),
				'headline'     => get_the_title( $id ),
			),
		);
	} elseif ( empty( $data['article'] ) && empty( $data['newsarticle'] ) && empty( $data['Article'] ) ) {
		// Rank Math has no schema for this CPT — give ended coverage a plain
		// Article so the (still useful) archive page keeps structured data.
		$data['gplb_article'] = array(
			'@type'           => 'Article',
			'headline'        => get_the_title( $id ),
			'datePublished'   => get_the_date( 'c', $id ),
			'dateModified'    => get_the_modified_date( 'c', $id ),
			'author'          => array( '@type' => 'Person', 'name' => get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $id ) ) ),
			'publisher'       => array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ) ),
			'mainEntityOfPage'=> get_permalink( $id ),
		);
	}
	return $data;
}, 10, 2 );

/* Fallback: emit JSON-LD directly when Rank Math is NOT active
   (LiveBlogPosting while live, Article after ended). */
add_action( 'wp_head', function () {
	if ( ! is_singular( 'gp_liveblog' ) ) { return; }
	if ( defined( 'RANK_MATH_VERSION' ) ) { return; } // Rank Math handles it above.
	$id  = get_queried_object_id();
	$url = get_permalink( $id );
	if ( gplb_is_live( $id ) ) {
		$entries = gplb_get_entries( $id, 0, 5, false );
		$updates = array();
		foreach ( $entries as $en ) {
			$updates[] = array(
				'@type'        => 'BlogPosting',
				'headline'     => wp_strip_all_tags( wp_trim_words( wp_strip_all_tags( $en['raw'] ), 24 ) ),
				'datePublished'=> $en['ts'],
			);
		}
		$schema = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'LiveBlogPosting',
			'@id'              => $url . '#liveblog',
			'url'              => $url,
			'headline'         => get_the_title( $id ),
			'description'      => get_the_excerpt( $id ),
			'coverageStartTime'=> gmdate( 'c', (int) get_post_meta( $id, '_gplb_started', true ) ),
			'liveBlogUpdate'   => $updates ?: array( array( '@type' => 'BlogPosting', 'headline' => get_the_title( $id ), 'datePublished' => get_the_date( 'c', $id ) ) ),
		);
	} else {
		$schema = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Article',
			'@id'              => $url . '#article',
			'url'              => $url,
			'headline'         => get_the_title( $id ),
			'description'      => get_the_excerpt( $id ),
			'datePublished'    => get_the_date( 'c', $id ),
			'dateModified'     => get_the_modified_date( 'c', $id ),
			'author'           => array( '@type' => 'Person', 'name' => get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $id ) ) ),
		);
	}
	echo '<script type="application/ld+json" class="gplb-schema">' . wp_json_encode( $schema ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON, safe.
}, 99 );

/* ── Helpers shared with frontend/admin ─────────────────────────────── */

function gplb_editor_can() {
	// Strictly the staff roles granted caps at activation (Admin + Editor).
	// No edit_posts fallback: Authors/Contributors must not post live updates
	// or see team notes.
	return current_user_can( 'edit_gplb_entries' );
}
function gplb_admin_can() {
	return current_user_can( 'manage_options' ) || current_user_can( 'publish_gplb_liveblogs' );
}

/* ── Liveblog editor metabox (admin): status, subtitle, coverage link ── */
function gplb_add_metabox() {
	add_meta_box(
		'gplb_meta',
		__( 'Liveblog settings', 'gp-liveblog' ),
		'gplb_metabox_html',
		'gp_liveblog',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'gplb_add_metabox' );

function gplb_metabox_html( $post ) {
	wp_nonce_field( 'gplb_meta', 'gplb_meta_nonce' );
	$status   = gplb_live_status( $post->ID );
	$subtitle = get_post_meta( $post->ID, '_gplb_subtitle', true );
	$cov_mode = get_post_meta( $post->ID, '_gplb_coverage_mode', true );
	$cov_id   = (int) get_post_meta( $post->ID, '_gplb_coverage_id', true );
	?>
	<p>
		<label for="gplb_status"><strong><?php esc_html_e( 'Status', 'gp-liveblog' ); ?></strong></label><br>
		<select name="gplb_status" id="gplb_status" style="width:100%;margin-top:4px">
			<option value="ended" <?php selected( $status, 'ended' ); ?>><?php esc_html_e( 'Ended (not broadcasting)', 'gp-liveblog' ); ?></option>
			<option value="live" <?php selected( $status, 'live' ); ?>><?php esc_html_e( 'Live (broadcasting)', 'gp-liveblog' ); ?></option>
		</select>
	</p>
	<p>
		<label for="gplb_subtitle"><strong><?php esc_html_e( 'Subtitle', 'gp-liveblog' ); ?></strong></label><br>
		<input type="text" name="gplb_subtitle" id="gplb_subtitle" value="<?php echo esc_attr( $subtitle ); ?>" style="width:100%;margin-top:4px" placeholder="<?php esc_attr_e( 'e.g. iPhone 18 · Watch S11 · AirPods Pro 3', 'gp-liveblog' ); ?>">
	</p>
	<p><strong><?php esc_html_e( 'Attach to Special Coverage', 'gp-liveblog' ); ?></strong><br>
		<span style="font-size:11.5px;color:#646970"><?php esc_html_e( 'When this liveblog is live, a LIVE COVERAGE button appears inside the coverage card if it features the same target.', 'gp-liveblog' ); ?></span>
	</p>
	<p>
		<label><?php esc_html_e( 'Coverage target', 'gp-liveblog' ); ?><br>
			<select name="gplb_cov_mode" id="gplb_cov_mode" style="width:100%;margin-top:4px">
				<option value="">— <?php esc_html_e( 'none', 'gp-liveblog' ); ?> —</option>
				<option value="page" <?php selected( $cov_mode, 'page' ); ?>><?php esc_html_e( 'Page (event microsite)', 'gp-liveblog' ); ?></option>
				<option value="post" <?php selected( $cov_mode, 'post' ); ?>><?php esc_html_e( 'Post', 'gp-liveblog' ); ?></option>
				<option value="category" <?php selected( $cov_mode, 'category' ); ?>><?php esc_html_e( 'Category', 'gp-liveblog' ); ?></option>
			</select>
		</label>
	</p>
	<p id="gplb_cov_target_id" style="display:none">
		<label><span id="gplb_cov_target_id_label"><?php esc_html_e( 'Page/post ID', 'gp-liveblog' ); ?></span><br>
			<input type="number" name="gplb_cov_id" id="gplb_cov_id" value="<?php echo (int) $cov_id; ?>" style="width:100%;margin-top:4px" placeholder="<?php esc_attr_e( 'Numeric ID of the page/post', 'gp-liveblog' ); ?>">
		</label>
	</p>
	<p id="gplb_cov_target_category" style="display:none">
		<label for="gplb_cov_cat"><?php esc_html_e( 'Category', 'gp-liveblog' ); ?></label><br>
		<?php
		wp_dropdown_categories( array(
			'name'              => 'gplb_cov_cat',
			'id'                => 'gplb_cov_cat',
			'selected'          => $cov_id,
			'hide_empty'        => 0,
			'hierarchical'      => 1,
			'orderby'           => 'name',
			'show_option_none'  => '— ' . __( 'none', 'gp-liveblog' ) . ' —',
			'option_none_value' => 0,
			'show_count'        => 1,
			'echo'              => 1,
		) );
		?>
	</p>
	<script>
	(function () {
		var mode = document.getElementById('gplb_cov_mode');
		if (!mode) { return; }
		var catBox = document.getElementById('gplb_cov_target_category');
		var idBox = document.getElementById('gplb_cov_target_id');
		var idLabel = document.getElementById('gplb_cov_target_id_label');
		var labels = { page: '<?php echo esc_js( __( 'Page ID', 'gp-liveblog' ) ); ?>', post: '<?php echo esc_js( __( 'Post ID', 'gp-liveblog' ) ); ?>' };
		function sync() {
			var v = mode.value;
			catBox.style.display = (v === 'category') ? 'block' : 'none';
			idBox.style.display = (v === 'page' || v === 'post') ? 'block' : 'none';
			if (idLabel && labels[v]) { idLabel.textContent = labels[v]; }
		}
		mode.addEventListener('change', sync);
		sync();
	})();
	</script>
	<?php
}

function gplb_save_metabox( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
	if ( ! isset( $_POST['gplb_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['gplb_meta_nonce'] ), 'gplb_meta' ) ) { return; }
	if ( ! gplb_admin_can() ) { return; }
	$status = ( isset( $_POST['gplb_status'] ) && 'live' === $_POST['gplb_status'] ) ? 'live' : 'ended';
	if ( 'live' === $status && 'live' !== gplb_live_status( $post_id ) ) {
		gplb_start_liveblog( $post_id );
	} elseif ( 'ended' === $status ) {
		gplb_end_liveblog( $post_id );
	}
	if ( isset( $_POST['gplb_subtitle'] ) ) {
		update_post_meta( $post_id, '_gplb_subtitle', sanitize_text_field( wp_unslash( $_POST['gplb_subtitle'] ) ) );
	}
	$cov_mode = isset( $_POST['gplb_cov_mode'] ) ? sanitize_key( $_POST['gplb_cov_mode'] ) : '';
	if ( 'category' === $cov_mode ) {
		$cov_id = isset( $_POST['gplb_cov_cat'] ) ? absint( $_POST['gplb_cov_cat'] ) : 0;
	} else {
		$cov_id = isset( $_POST['gplb_cov_id'] ) ? absint( $_POST['gplb_cov_id'] ) : 0;
	}
	if ( in_array( $cov_mode, array( 'page', 'post', 'category' ), true ) && $cov_id ) {
		update_post_meta( $post_id, '_gplb_coverage_mode', $cov_mode );
		update_post_meta( $post_id, '_gplb_coverage_id', $cov_id );
	} else {
		delete_post_meta( $post_id, '_gplb_coverage_mode' );
		delete_post_meta( $post_id, '_gplb_coverage_id' );
	}
}
add_action( 'save_post_gp_liveblog', 'gplb_save_metabox' );
