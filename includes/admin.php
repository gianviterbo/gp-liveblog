<?php
/**
 * GP Liveblog — wp-admin control room: one screen per liveblog with a
 * self-refreshing entry list and quick composer. Editors + Admins.
 */

defined( 'ABSPATH' ) || exit;

function gplb_admin_menu() {
	add_menu_page(
		__( 'Live Coverage', 'gp-liveblog' ),
		__( 'Live Coverage', 'gp-liveblog' ),
		'edit_gplb_entries',
		'gp-liveblog',
		'gplb_admin_room',
		'dashicons-megaphone',
		26
	);
}
add_action( 'admin_menu', 'gplb_admin_menu' );

function gplb_entry_count_for( $liveblog_id ) {
	$cache_key = 'gplb_count_' . (int) $liveblog_id;
	$found     = wp_cache_get( $cache_key, 'gplb' );
	if ( false === $found ) {
		$q = new WP_Query( array(
			'post_type'      => 'gp_liveblog_entry',
			'post_status'    => 'publish',
			'post_parent'    => (int) $liveblog_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		) );
		$found = (int) $q->found_posts;
		wp_cache_set( $cache_key, $found, 'gplb', 60 );
	}
	return (int) $found;
}

function gplb_admin_room() {
	if ( ! gplb_editor_can() ) {
		wp_die( esc_html__( 'You do not have permission to manage liveblogs.', 'gp-liveblog' ) );
	}
	$all = get_posts( array(
		'post_type'   => 'gp_liveblog',
		'post_status' => array( 'publish', 'draft' ),
		'numberposts' => -1,
		'orderby'     => 'modified',
		'order'       => 'DESC',
	) );
	$current = isset( $_GET['liveblog'] ) ? (int) $_GET['liveblog'] : ( $all[0]->ID ?? 0 );
	?>
	<div class="wrap gplb-admin">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Live Coverage', 'gp-liveblog' ); ?></h1>
		<?php if ( gplb_admin_can() ) : ?>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=gp_liveblog' ) ); ?>" class="page-title-action"><?php esc_html_e( 'New Liveblog', 'gp-liveblog' ); ?></a>
		<?php endif; ?>

		<?php if ( ! $all ) : ?>
			<p><?php esc_html_e( 'No liveblogs yet. Create one to start covering an event.', 'gp-liveblog' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<div class="gplb-admin-layout">
			<aside class="gplb-admin-list">
				<?php foreach ( $all as $lb ) :
					$live = gplb_is_live( $lb->ID );
					$sel  = (int) $lb->ID === $current; ?>
					<a class="gplb-admin-item<?php echo $sel ? ' is-sel' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'liveblog', (int) $lb->ID, admin_url( 'admin.php?page=gp-liveblog' ) ) ); ?>">
						<span class="gplb-admin-item-title"><?php echo esc_html( get_the_title( $lb->ID ) ); ?></span>
						<span class="gplb-admin-item-meta">
							<?php if ( $live ) : ?><span class="gplb-live-pill gplb-pill-live"><span class="gplb-live-dot"></span><?php esc_html_e( 'LIVE', 'gp-liveblog' ); ?></span><?php else : ?><span class="gplb-live-pill"><?php esc_html_e( 'ENDED', 'gp-liveblog' ); ?></span><?php endif; ?>
							<span><?php echo esc_html( number_format_i18n( gplb_entry_count_for( $lb->ID ) ) ); ?> <?php esc_html_e( 'entries', 'gp-liveblog' ); ?></span>
						</span>
					</a>
				<?php endforeach; ?>
			</aside>

			<section class="gplb-admin-main" data-liveblog="<?php echo (int) $current; ?>">
				<?php if ( $current && 'gp_liveblog' === get_post_type( $current ) ) : ?>
					<?php $lb = get_post( $current ); ?>
					<h2><?php echo esc_html( get_the_title( $current ) ); ?></h2>
					<p class="gplb-admin-permalink">
						<a href="<?php echo esc_url( get_permalink( $current ) ); ?>" target="_blank"><?php echo esc_url( get_permalink( $current ) ); ?></a>
						<?php if ( gplb_admin_can() ) : ?>
							<?php if ( gplb_is_live( $current ) ) : ?>
								<button class="button" type="button" id="gplbEndBtn" data-id="<?php echo (int) $current; ?>"><?php esc_html_e( 'End event', 'gp-liveblog' ); ?></button>
							<?php else : ?>
								<button class="button button-primary" type="button" id="gplbStartBtn" data-id="<?php echo (int) $current; ?>"><?php esc_html_e( 'Re-open live', 'gp-liveblog' ); ?></button>
							<?php endif; ?>
						<?php endif; ?>
					</p>

					<div class="gplb-admin-composer">
						<textarea id="gplbAdminText" rows="2" placeholder="<?php esc_attr_e( 'Post an update to viewers… (Ctrl/⌘+Enter to publish)', 'gp-liveblog' ); ?>"></textarea>
						<div class="gplb-tools">
							<?php foreach ( gplb_entry_types() as $tk => $tl ) : ?>
								<button type="button" class="gplb-tool<?php echo 'update' === $tk ? ' is-on' : ''; ?><?php echo 'note' === $tk ? ' gplb-tool-note' : ''; ?>" data-type="<?php echo esc_attr( $tk ); ?>"><?php echo esc_html( $tk === 'note' ? '🔒 ' . $tl : $tl ); ?></button>
							<?php endforeach; ?>
							<span class="gplb-admin-ts"><input type="text" id="gplbAdminTs" placeholder="<?php esc_attr_e( 'now', 'gp-liveblog' ); ?>" title="<?php esc_attr_e( 'Backdate e.g. 3:45 PM or now', 'gp-liveblog' ); ?>"></span>
						</div>
						<div class="gplb-urlrow" id="gplbAdminUrlRow" hidden><input type="url" id="gplbAdminUrl" placeholder="<?php esc_attr_e( 'Paste a URL to share with preview…', 'gp-liveblog' ); ?>"></div>
						<div class="gplb-imgrow" id="gplbAdminImgRow" hidden><input type="file" id="gplbAdminImg" accept="image/jpeg,image/png,image/webp"></div>
						<div class="gplb-admin-postrow">
							<button class="button button-primary" type="button" id="gplbAdminPublish"><?php esc_html_e( 'Publish entry', 'gp-liveblog' ); ?></button>
							<span class="gplb-status" id="gplbAdminStatus" role="status"></span>
						</div>
					</div>

					<div class="gplb-admin-table" id="gplbAdminTable" data-id="<?php echo (int) $current; ?>">
						<div class="gplb-admin-row gplb-admin-head">
							<span><?php esc_html_e( 'Time', 'gp-liveblog' ); ?></span><span><?php esc_html_e( 'Entry', 'gp-liveblog' ); ?></span><span><?php esc_html_e( 'Author', 'gp-liveblog' ); ?></span><span><?php esc_html_e( 'Type', 'gp-liveblog' ); ?></span><span><?php esc_html_e( 'Actions', 'gp-liveblog' ); ?></span>
						</div>
						<div class="gplb-admin-rows" id="gplbAdminRows"><?php esc_html_e( 'Loading…', 'gp-liveblog' ); ?></div>
					</div>
				<?php endif; ?>
			</section>
		</div>
	</div>
	<?php
}

function gplb_admin_assets( $hook ) {
	if ( 'toplevel_page_gp-liveblog' !== $hook ) { return; }
	wp_enqueue_style( 'gplb', GPLB_URL . 'assets/css/liveblog.css', array(), GPLB_VERSION );
	wp_enqueue_script( 'gplb-admin', GPLB_URL . 'assets/js/admin.js', array(), GPLB_VERSION, true );
	wp_localize_script( 'gplb-admin', 'GPLB', array(
		'rest'    => esc_url_raw( rest_url( GPLB_REST ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'canPost' => gplb_editor_can(),
		'isAdmin' => gplb_admin_can(),
		'i18n'    => array(
			'publish' => __( 'Publish', 'gp-liveblog' ),
			'delete'  => __( 'Delete', 'gp-liveblog' ),
			'edit'    => __( 'Edit', 'gp-liveblog' ),
			'save'    => __( 'Save', 'gp-liveblog' ),
			'cancel'  => __( 'Cancel', 'gp-liveblog' ),
			'ended'   => __( 'Ended', 'gp-liveblog' ),
			'live'    => __( 'LIVE', 'gp-liveblog' ),
		),
	) );
}
add_action( 'admin_enqueue_scripts', 'gplb_admin_assets' );
