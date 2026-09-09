<?php
/**
 * GP Liveblog — single liveblog template.
 * Uses the active theme's header/footer + gp-base article layout (content +
 * rail sidebar) so live pages match article pages; degrades to a single
 * column on themes without the gp-base grid.
 *
 * @package GP_Liveblog
 */

defined( 'ABSPATH' ) || exit;

$gplb_id   = get_queried_object_id();
$gplb_live = gplb_is_live( $gplb_id );
$gplb_canpost = gplb_editor_can();
$gplb_sub  = get_post_meta( $gplb_id, '_gplb_subtitle', true );
// Live: newest 40 server-side (rest streams via JS). Ended: FULL transcript
// in the HTML for SEO — every public entry, notes excluded.
$gplb_entries = $gplb_live
	? gplb_get_entries( $gplb_id, 0, 40, $gplb_canpost )
	: gplb_all_entries( $gplb_id, false );

$gplb_cats = get_the_terms( $gplb_id, 'category' );
$gplb_cat_names = ( $gplb_cats && ! is_wp_error( $gplb_cats ) ) ? implode( ' · ', wp_list_pluck( $gplb_cats, 'name' ) ) : '';

get_header();
?>
<div class="wrap gplb-single-wrap">

	<?php /* Side hero (gp-base single-post default): title left, featured
	       image right of the header; the content + rail grid starts below. */ ?>
	<?php if ( has_post_thumbnail() ) : ?>
	<div class="head-media">
	<?php endif; ?>

		<div class="head-col">
			<header class="gplb-hero">
				<div class="gplb-hero-kicker">
					<?php if ( $gplb_live ) : ?>
						<span class="gplb-live-pill gplb-pill-live"><span class="gplb-live-dot"></span><?php esc_html_e( 'LIVE', 'gp-liveblog' ); ?></span>
					<?php else : ?>
						<span class="gplb-live-pill"><?php esc_html_e( 'Coverage ended', 'gp-liveblog' ); ?></span>
					<?php endif; ?>
					<?php if ( $gplb_cat_names ) : ?>
						<span class="gplb-hero-cat"><?php echo esc_html( $gplb_cat_names ); ?></span>
					<?php endif; ?>
				</div>
				<h1 class="gplb-hero-title"><?php the_title(); ?></h1>
				<?php if ( $gplb_sub ) : ?><p class="gplb-hero-sub"><?php echo esc_html( $gplb_sub ); ?></p><?php endif; ?>
				<div class="gplb-hero-meta">
					<span><?php esc_html_e( 'Updated', 'gp-liveblog' ); ?> <time datetime="<?php echo esc_attr( get_the_modified_date( 'c' ) ); ?>"><?php echo esc_html( get_the_modified_time( 'g:i A' ) ); ?></time></span>
					<span class="gplb-watching"><span class="gplb-live-dot"></span><span data-gplb-watching><?php echo esc_html( number_format_i18n( (int) get_post_meta( $gplb_id, '_gplb_watching', true ) ?: 0 ) ); ?></span> <?php esc_html_e( 'watching', 'gp-liveblog' ); ?></span>
				</div>
				<?php
				// Public roll-up: total viewers, reactions, entries (live + final).
				$gplb_stats = gplb_liveblog_stats( $gplb_id );
				?>
				<div class="gplb-stats" id="gplbStats">
					<span class="gplb-stat"><span aria-hidden="true">👁</span> <b data-gplb-stat="viewers"><?php echo esc_html( number_format_i18n( $gplb_stats['viewers'] ) ); ?></b> <?php esc_html_e( 'viewers', 'gp-liveblog' ); ?></span>
					<span class="gplb-stat gplb-stat-liveonly"<?php echo $gplb_live ? '' : ' hidden'; ?>><span class="gplb-live-dot" aria-hidden="true"></span> <b data-gplb-stat="watching"><?php echo esc_html( number_format_i18n( $gplb_stats['watching'] ) ); ?></b> <?php esc_html_e( 'now', 'gp-liveblog' ); ?></span>
					<span class="gplb-stat"><span aria-hidden="true">⚡</span> <b data-gplb-stat="peak"><?php echo esc_html( number_format_i18n( $gplb_stats['peak'] ) ); ?></b> <?php esc_html_e( 'peak', 'gp-liveblog' ); ?></span>
					<span class="gplb-stat"><span aria-hidden="true">❤</span> <b data-gplb-stat="reactions"><?php echo esc_html( number_format_i18n( $gplb_stats['reactions'] ) ); ?></b> <?php esc_html_e( 'reactions', 'gp-liveblog' ); ?></span>
					<span class="gplb-stat"><span aria-hidden="true">✍</span> <b data-gplb-stat="entries"><?php echo esc_html( number_format_i18n( $gplb_stats['entries'] ) ); ?></b> <?php esc_html_e( 'updates', 'gp-liveblog' ); ?></span>
				</div>
			</header>
		</div>

		<?php if ( has_post_thumbnail() ) : ?>
		<figure class="hero-img gplb-hero-img">
			<?php the_post_thumbnail( 'medium_large', array( 'fetchpriority' => 'high' ) ); ?>
		</figure>
	</div>
	<?php endif; ?>

	<div class="article-layout">

		<div class="gplb-single">

			<?php if ( $gplb_live ) : ?>
			<div class="gplb-livecomposer" id="gplbLiveComposer">
				<?php if ( $gplb_canpost ) : ?>
					<div class="gplb-composer gplb-composer--live">
						<textarea id="gplbLiveText" rows="2" placeholder="<?php esc_attr_e( 'Post an update to viewers… (Ctrl/⌘+Enter to publish)', 'gp-liveblog' ); ?>"></textarea>
						<div class="gplb-tools">
							<button type="button" class="gplb-tool is-on" data-type="update">✍ <span><?php esc_html_e( 'Update', 'gp-liveblog' ); ?></span></button>
							<button type="button" class="gplb-tool" data-type="image">🖼</button>
							<button type="button" class="gplb-tool" data-type="link">🔗</button>
							<button type="button" class="gplb-tool" data-type="social">𝕏</button>
							<button type="button" class="gplb-tool gplb-tool-note" data-type="note">🔒 <span><?php esc_html_e( 'Team', 'gp-liveblog' ); ?></span></button>
						</div>
						<div class="gplb-urlrow" id="gplbLiveUrlRow" hidden>
							<input type="url" id="gplbLiveUrl" placeholder="<?php esc_attr_e( 'Paste a URL to share with preview…', 'gp-liveblog' ); ?>">
						</div>
						<div class="gplb-imgrow" id="gplbLiveImgRow" hidden>
							<input type="file" id="gplbLiveImg" accept="image/jpeg,image/png,image/webp">
						</div>
						<div class="gplb-postrow">
							<button class="gplb-btn gplb-btn-primary" id="gplbLivePublish" type="button"><?php esc_html_e( 'Publish', 'gp-liveblog' ); ?></button>
							<span class="gplb-status" id="gplbLiveStatus" role="status"></span>
						</div>
						<div class="gplb-videorow">
							<input type="url" id="gplbLiveVideoUrl" placeholder="<?php esc_attr_e( 'Pin a video above the updates — YouTube, TikTok or Instagram URL', 'gp-liveblog' ); ?>">
							<button class="gplb-btn" type="button" id="gplbLiveVideoPin">🎥 <?php esc_html_e( 'Pin video', 'gp-liveblog' ); ?></button>
							<span class="gplb-status" id="gplbLiveVideoStatus" role="status"></span>
						</div>
					</div>
				<?php else : ?>
					<p class="gplb-livecomposer-note">
						<span class="gplb-livecomposer-lock" aria-hidden="true">🔒</span>
						<span>
							<?php if ( is_user_logged_in() ) : ?>
								<?php esc_html_e( 'Your account cannot add updates — this liveblog is staff-managed and posting is limited to Admins and Editors.', 'gp-liveblog' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Live updates are added by our team. Log in as an Admin or Editor to post updates.', 'gp-liveblog' ); ?>
							<?php endif; ?>
						</span>
						<?php if ( ! is_user_logged_in() ) : ?>
							<a class="gplb-login-link" href="<?php echo esc_url( wp_login_url( get_permalink( $gplb_id ) ) ); ?>"><?php esc_html_e( 'Log in', 'gp-liveblog' ); ?> →</a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php
			/* Pinned video (editors embed a stream above the updates). */
			$gplb_pinned = gplb_pinned_video( $gplb_id );
			?>
			<div class="gplb-pinned" id="gplbPinned" data-live="<?php echo $gplb_live ? '1' : '0'; ?>">
				<?php if ( $gplb_pinned ) : ?>
				<div class="gplb-pinned-frame" id="gplbPinnedFrame">
					<div class="gplb-pinned-head">
						<span class="gplb-pinned-label"><span aria-hidden="true">🎥</span> <?php esc_html_e( 'Live video', 'gp-liveblog' ); ?></span>
						<?php if ( $gplb_canpost ) : ?>
							<button type="button" class="gplb-pinned-x" id="gplbPinnedRemove"><?php esc_html_e( 'Remove', 'gp-liveblog' ); ?></button>
						<?php endif; ?>
					</div>
					<div class="gplb-pinned-ratio"><?php echo gplb_pinned_video_embed( $gplb_pinned ); // phpcs:ignore WordPress.Security.EscapeOutput -- iframe built above ?></div>
				</div>
				<?php endif; ?>
			</div>

			<div class="gplb-timeline" id="gplbTimeline" data-id="<?php echo (int) $gplb_id; ?>">
				<?php if ( ! $gplb_entries ) : ?>
					<p class="gplb-empty"><?php esc_html_e( 'Coverage starts soon — check back for live updates.', 'gp-liveblog' ); ?></p>
				<?php else : ?>
					<?php foreach ( $gplb_entries as $gplb_e ) : ?>
						<?php echo gplb_render_entry( $gplb_e ); // phpcs:ignore WordPress.Security.EscapeOutput -- sanitized in renderer ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>

		<aside class="rail">
			<?php if ( is_dir( WP_PLUGIN_DIR . '/qr-code-composer' ) ) : /* QR Code Composer plugin active */ ?>
			<div class="gp-qr">
				<div class="qrcprowrapper"><div class="qrc_canvass" id="gp-qrc-<?php echo (int) $gplb_id; ?>" data-text="<?php echo esc_url( get_permalink( $gplb_id ) ); ?>" style="display:inline-block;"></div></div>
				<p class="gp-qr-note"><?php esc_html_e( 'Scan to follow live on your phone', 'gp-liveblog' ); ?></p>
			</div>
			<?php endif; ?>
			<?php if ( function_exists( 'gp_base_ad_slot' ) ) : ?>
				<?php gp_base_ad_slot( 'rail' ); ?>
			<?php endif; ?>
			<?php if ( is_active_sidebar( 'gp-article-sidebar' ) ) : ?>
				<?php dynamic_sidebar( 'gp-article-sidebar' ); ?>
			<?php elseif ( is_active_sidebar( 'sidebar-1' ) ) : ?>
				<?php dynamic_sidebar( 'sidebar-1' ); ?>
			<?php endif; ?>
		</aside>

	</div>
</div>
<?php
get_footer();
