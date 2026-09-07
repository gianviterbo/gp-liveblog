<?php
/**
 * GP Liveblog — REST endpoints.
 * Namespace: gp-liveblog/v1
 *
 *  GET  /liveblogs                → active liveblogs (public, cheap)
 *  GET  /liveblogs/{id}/entries   → feed; ?after_id=N for polling; notes gated
 *  POST /liveblogs/{id}/entries   → create entry (editors+). JSON body.
 *  POST /entries/{id}             → update own entry (editors) / any (admins)
 *  DELETE /entries/{id}           → delete
 *  POST /unfurl                   → { url } → og: card (public POST, cached)
 *  POST /upload-image             → multipart file → WebP attachment (editors+)
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parse an editor-supplied timestamp in SITE time (PH): "3:45 PM", "now",
 * ISO-8601 (own offset wins). Returns a UTC epoch, 0 on garbage.
 */
function gplb_parse_ts( $raw ) {
	if ( '' === (string) $raw || null === $raw ) { return 0; }
	try {
		$dt = new DateTime( (string) $raw, wp_timezone() );
		$t  = $dt->getTimestamp();
		return $t > 0 ? $t : 0;
	} catch ( Exception $e ) {
		return 0;
	}
}

function gplb_rest() {
	register_rest_route( GPLB_REST, '/liveblogs', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$out = array();
			foreach ( gplb_active_liveblogs() as $p ) {
				$out[] = array(
					'id'        => (int) $p->ID,
					'title'     => get_the_title( $p->ID ),
					'permalink' => get_permalink( $p->ID ),
					'watching'  => (int) get_post_meta( $p->ID, '_gplb_watching', true ) ?: 0,
				);
			}
			return $out;
		},
	) );

	register_rest_route( GPLB_REST, '/liveblogs/(?P<id>\d+)/entries', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$id = (int) $req['id'];
			if ( 'gp_liveblog' !== get_post_type( $id ) ) {
				return new WP_Error( 'gplb_not_found', __( 'Liveblog not found.', 'gp-liveblog' ), array( 'status' => 404 ) );
			}
			$include_notes = gplb_editor_can();
			return array(
				'live'      => gplb_is_live( $id ),
				'entries'   => gplb_get_entries( $id, (int) $req->get_param( 'after_id' ), 80, $include_notes ),
			);
		},
	) );

	register_rest_route( GPLB_REST, '/liveblogs/(?P<id>\d+)/entries', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return gplb_editor_can(); },
		'callback'            => function ( $req ) {
			$id = (int) $req['id'];
			if ( 'gp_liveblog' !== get_post_type( $id ) || ! gplb_is_live( $id ) ) {
				return new WP_Error( 'gplb_not_live', __( 'Liveblog not found or not live.', 'gp-liveblog' ), array( 'status' => 400 ) );
			}
			$params = $req->get_json_params();
			$type   = isset( $params['type'] ) && in_array( $params['type'], array_keys( gplb_entry_types() ), true ) ? $params['type'] : 'update';
			$text   = isset( $params['text'] ) ? sanitize_textarea_field( $params['text'] ) : '';
			if ( ! $text && ! in_array( $type, array( 'image', 'link', 'social' ), true ) ) {
				return new WP_Error( 'gplb_empty', __( 'Entry text is required.', 'gp-liveblog' ), array( 'status' => 400 ) );
			}

			$content = $text;
			$meta    = array( '_gplb_type' => $type );

			if ( 'image' === $type ) {
				$att = (int) ( $params['image_id'] ?? 0 );
				if ( ! $att || 'attachment' !== get_post_type( $att ) ) {
					return new WP_Error( 'gplb_no_image', __( 'Image required for image entries.', 'gp-liveblog' ), array( 'status' => 400 ) );
				}
				$meta['_gplb_image_id'] = $att;
				$content = $text; // caption
			} elseif ( 'link' === $type || 'social' === $type ) {
				$url = esc_url_raw( $params['url'] ?? '' );
				if ( ! wp_http_validate_url( $url ) || ! preg_match( '#^https?://#i', $url ) ) {
					return new WP_Error( 'gplb_bad_url', __( 'A valid http(s) URL is required.', 'gp-liveblog' ), array( 'status' => 400 ) );
				}
				$meta['_gplb_link_url'] = 'social' === $type ? '' : $url;
				$meta['_gplb_social_url'] = 'social' === $type ? $url : '';
				$card = gplb_unfurl( $url );
				if ( $card ) { $meta['_gplb_link_card'] = $card; }
				$content = $text;
			}

			// Optional backdate (editor-set timestamp for out-of-order inserts).
			// Wall-clock strings mean site-local time (Asia/Manila on GP).
			$when = time();
			if ( ! empty( $params['ts'] ) && gplb_editor_can() ) {
				$t = gplb_parse_ts( (string) $params['ts'] );
				if ( $t ) { $when = $t; }
			}

			$entry_id = wp_insert_post( array(
				'post_type'    => 'gp_liveblog_entry',
				'post_status'  => 'publish',
				'post_parent'  => $id,
				'post_author'  => get_current_user_id() ?: (int) ( $params['author'] ?? 0 ),
				'post_title'   => wp_trim_words( wp_strip_all_tags( $text ), 12, '…' ),
				'post_content' => $content,
				// post_date = site-local (PH); post_date_gmt = UTC. (Old bug
				// stamped UTC into BOTH → times displayed as UTC.)
				'post_date'    => wp_date( 'Y-m-d H:i:s', $when ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $when ),
			) );
			if ( is_wp_error( $entry_id ) ) {
				return $entry_id;
			}
			foreach ( $meta as $k => $v ) { update_post_meta( $entry_id, $k, $v ); }
			update_post_meta( $id, '_gplb_last_entry', time() );

			return array( 'ok' => true, 'entry' => gplb_entry_shape( get_post( $entry_id ) ) );
		},
	) );

	register_rest_route( GPLB_REST, '/entries/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => function ( $req ) {
			$e = get_post( (int) $req['id'] );
			if ( ! $e || 'gp_liveblog_entry' !== $e->post_type ) { return false; }
			if ( gplb_admin_can() ) { return true; }
			return gplb_editor_can() && (int) $e->post_author === get_current_user_id();
		},
		'callback'            => function ( $req ) {
			$e   = get_post( (int) $req['id'] );
			$par = $req->get_json_params();
			$text = isset( $par['text'] ) ? sanitize_textarea_field( $par['text'] ) : $e->post_content;
			wp_update_post( array( 'ID' => $e->ID, 'post_content' => $text, 'post_title' => wp_trim_words( wp_strip_all_tags( $text ), 12, '…' ) ) );
			if ( ! empty( $par['ts'] ) ) {
				$t = gplb_parse_ts( (string) $par['ts'] );
				if ( $t ) {
					// Only post_date (local); WP recomputes post_date_gmt.
					wp_update_post( array( 'ID' => $e->ID, 'post_date' => wp_date( 'Y-m-d H:i:s', $t ) ) );
				}
			}
			return array( 'ok' => true, 'entry' => gplb_entry_shape( get_post( $e->ID ) ) );
		},
	) );

	register_rest_route( GPLB_REST, '/entries/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => function ( $req ) {
			$e = get_post( (int) $req['id'] );
			if ( ! $e || 'gp_liveblog_entry' !== $e->post_type ) { return false; }
			if ( gplb_admin_can() ) { return true; }
			return gplb_editor_can() && (int) $e->post_author === get_current_user_id();
		},
		'callback'            => function ( $req ) {
			$e = get_post( (int) $req['id'] );
			wp_delete_post( $e->ID, true );
			return array( 'ok' => true, 'deleted' => (int) $e->ID );
		},
	) );

	/* ── Lifecycle (admin only): end / re-open a liveblog ────────────────── */
	register_rest_route( GPLB_REST, '/liveblogs/(?P<id>\d+)/(?P<action>end|start)', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return gplb_admin_can(); },
		'callback'            => function ( $req ) {
			$id = (int) $req['id'];
			if ( 'gp_liveblog' !== get_post_type( $id ) ) {
				return new WP_Error( 'gplb_not_found', __( 'Liveblog not found.', 'gp-liveblog' ), array( 'status' => 404 ) );
			}
			if ( 'end' === $req['action'] ) {
				gplb_end_liveblog( $id );
			} else {
				gplb_start_liveblog( $id );
			}
			// Bust the active-liveblog static cache.
			return array( 'ok' => true, 'live' => gplb_is_live( $id ) );
		},
	) );

	/* ── Watching heartbeat: viewers ping every 30s while the page/panel is
	      open; the counter is a 2-minute sliding window of recent pings. ──── */
	register_rest_route( GPLB_REST, '/liveblogs/(?P<id>\d+)/watch', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$id = (int) $req['id'];
			if ( 'gp_liveblog' !== get_post_type( $id ) ) {
				return new WP_Error( 'gplb_not_found', __( 'Liveblog not found.', 'gp-liveblog' ), array( 'status' => 404 ) );
			}
			$now   = time();
			$recent = get_transient( 'gplb_watch_' . $id );
			if ( ! is_array( $recent ) ) { $recent = array(); }
			$recent[] = $now;
			// keep only last 2 minutes
			$recent = array_values( array_filter( $recent, function ( $t ) use ( $now ) { return $t >= $now - 120; } ) );
			$recent = array_slice( $recent, -400 ); // hard cap
			set_transient( 'gplb_watch_' . $id, $recent, 180 );
			update_post_meta( $id, '_gplb_watching', count( $recent ) );
			return array( 'ok' => true, 'watching' => count( $recent ) );
		},
	) );

	/* Unfurl: fetch og:/twitter: meta from a URL, cache 12h in a transient. */
	register_rest_route( GPLB_REST, '/unfurl', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return gplb_editor_can(); },
		'callback'            => function ( $req ) {
			$par = $req->get_json_params();
			$url = esc_url_raw( $par['url'] ?? '' );
			if ( ! preg_match( '#^https?://#i', $url ) || ! wp_http_validate_url( $url ) ) {
				return new WP_Error( 'gplb_bad_url', __( 'Valid http(s) URL required.', 'gp-liveblog' ), array( 'status' => 400 ) );
			}
			$card = gplb_unfurl( $url );
			return array( 'ok' => (bool) $card, 'card' => $card, 'url' => $url );
		},
	) );

	/* Image upload → WebP (house policy), returns attachment id + url. */
	register_rest_route( GPLB_REST, '/upload-image', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return gplb_editor_can(); },
		'callback'            => function ( $req ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			if ( empty( $_FILES['file'] ) ) {
				return new WP_Error( 'gplb_no_file', __( 'No file uploaded.', 'gp-liveblog' ), array( 'status' => 400 ) );
			}
			$move = wp_handle_upload( $_FILES['file'], array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
				),
			) );
			if ( ! empty( $move['error'] ) ) {
				return new WP_Error( 'gplb_upload_fail', $move['error'], array( 'status' => 400 ) );
			}

			$path = $move['file'];
			$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

			// Convert JPG/PNG → WebP (delete source per house policy).
			if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'jpe' ), true ) ) {
				$webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $path );
				$conv      = gplb_convert_webp( $path, $webp_path );
				if ( $conv ) {
					@unlink( $path ); // source deleted after conversion
					$path = $webp_path;
					$move['type'] = 'image/webp';
					$move['url']  = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $move['url'] );
				}
			}

			$att_id = wp_insert_attachment( array(
				'post_mime_type' => $move['type'],
				'post_title'     => sanitize_file_name( pathinfo( $path, PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
			), $path );
			if ( is_wp_error( $att_id ) ) { return $att_id; }
			$meta = wp_generate_attachment_metadata( $att_id, $path );
			wp_update_attachment_metadata( $att_id, $meta );

			return array( 'ok' => true, 'attachment_id' => (int) $att_id, 'url' => wp_get_attachment_url( $att_id ) );
		},
	) );
}
add_action( 'rest_api_init', 'gplb_rest' );

/* ── WebP conversion (Imagick preferred; GD fallback) ────────────────── */

function gplb_convert_webp( $src, $dest, $quality = 82 ) {
	if ( class_exists( 'Imagick' ) ) {
		try {
			$img = new Imagick( $src );
			$img->setImageFormat( 'webp' );
			$img->setOption( 'webp:lossless', 'false' );
			$img->setOption( 'webp:quality', (string) $quality );
			$ok = $img->writeImage( $dest );
			$img->clear();
			return $ok;
		} catch ( Exception $e ) {
			// fall through to GD
		}
	}
	if ( function_exists( 'imagewebp' ) ) {
		$info = @getimagesize( $src );
		if ( ! $info ) { return false; }
		$im = false;
		switch ( $info[2] ) {
			case IMAGETYPE_JPEG: $im = imagecreatefromjpeg( $src ); break;
			case IMAGETYPE_PNG:  $im = imagecreatefrompng( $src ); break;
		}
		if ( ! $im ) { return false; }
		imagealphablending( $im, false );
		imagesavealpha( $im, true );
		$ok = imagewebp( $im, $dest, $quality );
		imagedestroy( $im );
		return $ok;
	}
	return false;
}

/* ── Unfurl: fetch page, extract og/twitter meta ─────────────────────── */

function gplb_unfurl( $url ) {
	$key = 'gplb_unfurl_' . md5( $url );
	$hit = get_transient( $key );
	if ( false !== $hit ) { return $hit; }

	$resp = wp_remote_get( $url, array(
		'timeout'    => 12,
		'redirection' => 5,
		'user-agent' => 'Mozilla/5.0 (GadgetPilipinas Liveblog unfurl)',
		'headers'    => array( 'Accept' => 'text/html,application/xhtml+xml' ),
	) );
	if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
		return null;
	}
	$html = wp_remote_retrieve_body( $resp );
	if ( strlen( $html ) > 1500000 ) { $html = substr( $html, 0, 1500000 ); } // guard

	$tags = array();
	if ( preg_match_all( '/<meta[^>]+>/i', $html, $m ) ) {
		foreach ( $m[0] as $tag ) {
			if ( ! preg_match( '/property=["\']([^"\']+)["\']/i', $tag, $pm ) && ! preg_match( '/name=["\']([^"\']+)["\']/i', $tag, $pm ) ) {
				continue;
			}
			if ( preg_match( '/content=["\']([^"\']*)["\']/i', $tag, $cm ) ) {
				$tags[ strtolower( $pm[1] ) ] = html_entity_decode( $cm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
	}
	$title = '';
	if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $tm ) ) { $title = trim( $tm[1] ); }

	$pick = function ( $keys ) use ( $tags ) {
		foreach ( $keys as $k ) { if ( ! empty( $tags[ $k ] ) ) { return $tags[ $k ]; } }
		return '';
	};
	$image = $pick( array( 'og:image:secure_url', 'og:image:url', 'og:image', 'twitter:image', 'twitter:image:src' ) );
	if ( $image && 0 === strpos( $image, '//' ) ) { $image = 'https:' . $image; }

	$card = array(
		'title'       => $pick( array( 'og:title', 'twitter:title' ) ) ?: wp_strip_all_tags( $title ),
		'description' => $pick( array( 'og:description', 'twitter:description', 'description' ) ),
		'image'       => $image,
		'site'        => $pick( array( 'og:site_name', 'twitter:site' ) ) ?: wp_parse_url( $url, PHP_URL_HOST ),
		'url'         => $url,
	);
	$card = array_map( function ( $v ) { return mb_substr( (string) $v, 0, 500 ); }, $card );

	set_transient( $key, $card, 12 * HOUR_IN_SECONDS );
	return $card;
}
