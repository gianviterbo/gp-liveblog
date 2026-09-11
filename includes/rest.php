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
			$entries       = gplb_get_entries( $id, (int) $req->get_param( 'after_id' ), 80, $include_notes );
			$out           = array(
				'live'    => gplb_is_live( $id ),
				'pinned'  => gplb_pinned_video( $id ),
				'entries' => $entries,
			);
			// Threaded replies: staff see every thread; visitors only threads
			// under updates the team explicitly opened for public replies.
			if ( $entries ) {
				$thread_ids = wp_list_pluck( $entries, 'id' );
				if ( $include_notes ) {
					$out['threads'] = gplb_threads_for( $thread_ids );
				} else {
					$pub = array_values( array_filter( $entries, function ( $e ) {
						return ! empty( $e['public_replies'] );
					} ) );
					if ( $pub ) {
						$out['threads'] = gplb_threads_for( wp_list_pluck( $pub, 'id' ) );
					}
				}
			}
			return $out;
		},
	) );

	register_rest_route( GPLB_REST, '/liveblogs/(?P<id>\d+)/entries', array(
		'methods'             => 'POST',
		'permission_callback' => function ( $req ) {
			if ( gplb_editor_can() ) { return true; }
			// Anonymous viewers may only reply to updates the team opened for
			// public replies (rate-limited in the callback).
			$p  = $req->get_json_params();
			$rt = (int) ( $p['reply_to'] ?? 0 );
			if ( 'reply' === ( $p['type'] ?? '' ) && $rt ) {
				$e = get_post( $rt );
				return $e && 'gp_liveblog_entry' === $e->post_type && gplb_entry_public_replies( $rt );
			}
			return false;
		},
		'callback'            => function ( $req ) {
			$id = (int) $req['id'];
			if ( 'gp_liveblog' !== get_post_type( $id ) || ! gplb_is_live( $id ) ) {
				return new WP_Error( 'gplb_not_live', __( 'Liveblog not found or not live.', 'gp-liveblog' ), array( 'status' => 400 ) );
			}
			$params = $req->get_json_params();
			$type   = isset( $params['type'] ) && in_array( $params['type'], array_merge( array_keys( gplb_entry_types() ), array( 'reply' ) ), true ) ? $params['type'] : 'update';
			$text   = isset( $params['text'] ) ? sanitize_textarea_field( $params['text'] ) : '';
			if ( ! $text && ! in_array( $type, array( 'image', 'link', 'social' ), true ) ) {
				return new WP_Error( 'gplb_empty', __( 'Entry text is required.', 'gp-liveblog' ), array( 'status' => 400 ) );
			}

			// Threaded reply: nests under an existing update. Staff may reply to
			// anything; anonymous viewers only where the team enabled public replies.
			$reply_to = (int) ( $params['reply_to'] ?? 0 );
			if ( 'reply' === $type ) {
				$is_editor = gplb_editor_can();
				$parent_entry = $reply_to ? get_post( $reply_to ) : null;
				if ( ! $parent_entry || 'gp_liveblog_entry' !== $parent_entry->post_type || (int) $parent_entry->post_parent !== $id ) {
					return new WP_Error( 'gplb_bad_reply_to', __( 'Reply target entry not found in this liveblog.', 'gp-liveblog' ), array( 'status' => 400 ) );
				}
				$pub_entry = gplb_entry_public_replies( $parent_entry->ID );
				if ( ! $is_editor && ! $pub_entry ) {
					return new WP_Error( 'gplb_denied', __( 'Public replies are disabled for this update.', 'gp-liveblog' ), array( 'status' => 403 ) );
				}
				if ( ! $is_editor && gplb_reply_throttled( $parent_entry->ID ) ) {
					return new WP_Error( 'gplb_throttled', __( 'Slow down — please wait a moment between replies.', 'gp-liveblog' ), array( 'status' => 429 ) );
				}
				$reply_text = $is_editor ? $text : mb_substr( $text, 0, 600 );
				if ( '' === trim( $reply_text ) ) {
					return new WP_Error( 'gplb_empty', __( 'Reply text is required.', 'gp-liveblog' ), array( 'status' => 400 ) );
				}
				$rid = wp_insert_post( array(
					'post_type'      => 'gp_liveblog_entry',
					'post_status'    => 'publish',
					'post_parent'    => $id,
					'post_author'    => get_current_user_id(),
					'post_title'     => wp_trim_words( wp_strip_all_tags( $reply_text ), 12, '…' ),
					'post_content'   => $reply_text,
					'post_date'      => wp_date( 'Y-m-d H:i:s' ),
					'post_date_gmt'  => gmdate( 'Y-m-d H:i:s' ),
				) );
				if ( is_wp_error( $rid ) ) { return $rid; }
				update_post_meta( $rid, '_gplb_type', 'reply' );
				update_post_meta( $rid, '_gplb_reply_to', $parent_entry->ID );
				if ( ! $is_editor ) {
					$name = isset( $params['author'] ) ? mb_substr( sanitize_text_field( (string) $params['author'] ), 0, 40 ) : '';
					if ( '' !== $name ) { update_post_meta( $rid, '_gplb_author_name', $name ); }
				}
				update_post_meta( $id, '_gplb_last_entry', time() );
				return array( 'ok' => true, 'reply' => gplb_entry_shape( get_post( $rid ), 'reply' ) );
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
				$media = gplb_oembed_card( $url ); // YT/TikTok/IG rich preview
				if ( $media ) { $meta['_gplb_media'] = $media; }
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
			if ( gplb_editor_can() ) {
				// Staff moderate threaded replies (incl. public viewer replies).
				if ( 'reply' === ( get_post_meta( $e->ID, '_gplb_type', true ) ?: '' ) ) { return true; }
				return (int) $e->post_author === get_current_user_id();
			}
			return false;
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
			if ( gplb_editor_can() ) {
				// Staff moderate everything from the control room: replies
				// (incl. public viewer replies) AND any teammate's update/note.
				return true;
			}
			return false;
		},
		'callback'            => function ( $req ) {
			$e = get_post( (int) $req['id'] );
			// Cascade: deleting an update removes its threaded replies.
			$kids = get_posts( array(
				'post_type'      => 'gp_liveblog_entry',
				'post_status'    => 'any',
				'numberposts'    => -1,
				'no_found_rows'  => true,
				'meta_key'       => '_gplb_reply_to',
				'meta_value'     => (int) $e->ID,
			) );
			foreach ( $kids as $k ) { wp_delete_post( $k->ID, true ); }
			wp_delete_post( $e->ID, true );
			return array( 'ok' => true, 'deleted' => (int) $e->ID );
		},
	) );

	/* ── Public-replies toggle (staff): open/close ONE update to viewers ─── */
	register_rest_route( GPLB_REST, '/entries/(?P<id>\d+)/public-replies', array(
		'methods'             => 'POST',
		'permission_callback' => function ( $req ) {
			$e = get_post( (int) $req['id'] );
			if ( ! $e || 'gp_liveblog_entry' !== $e->post_type ) { return false; }
			return gplb_editor_can();
		},
		'callback'            => function ( $req ) {
			$e    = get_post( (int) $req['id'] );
			$type = get_post_meta( $e->ID, '_gplb_type', true ) ?: 'update';
			if ( 'reply' === $type || 'note' === $type ) {
				return new WP_Error( 'gplb_bad_target', __( 'Only updates can be opened for public replies.', 'gp-liveblog' ), array( 'status' => 400 ) );
			}
			$body = $req->get_json_params();
			$on   = ! empty( $body['enabled'] );
			if ( $on ) { update_post_meta( $e->ID, '_gplb_public_replies', '1' ); }
			else { delete_post_meta( $e->ID, '_gplb_public_replies' ); }
			return array( 'ok' => true, 'id' => (int) $e->ID, 'public_replies' => gplb_entry_public_replies( $e->ID ) );
		},
	) );

	/* ── State (admin only): status/lock/watch for ops & the recap job ───── */
	register_rest_route( GPLB_REST, '/liveblogs/(?P<id>\\d+)/state', array(
		'methods'             => 'GET',
		'permission_callback' => function () { return gplb_admin_can(); },
		'callback'            => function ( $req ) {
			global $wpdb;
			$id = (int) $req['id'];
			if ( 'gp_liveblog' !== get_post_type( $id ) ) {
				return new WP_Error( 'gplb_not_found', __( 'Liveblog not found.', 'gp-liveblog' ), array( 'status' => 404 ) );
			}
			// v0.2.13: resolve live FIRST — gplb_is_live() may lazily flip the
			// session to ended (idle auto-end). Reading the meta before that call
			// returned a stale, empty ended_ph on the very request that ended it.
			$live       = gplb_is_live( $id );
			$started    = (int) get_post_meta( $id, '_gplb_started', true );
			$ended      = (int) get_post_meta( $id, '_gplb_ended', true );
			$last_entry = (int) get_post_meta( $id, '_gplb_last_entry', true );
			return array(
				'live'          => $live,
				'locked'        => gplb_is_locked( $id ),
				'status'        => gplb_live_status( $id ),
				'watching'      => (int) get_post_meta( $id, '_gplb_watching', true ) ?: 0,
				'entries_total' => (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'gp_liveblog_entry' AND post_status = 'publish' AND post_parent = %d",
					$id
				) ),
				'started_ph'    => $started ? gmdate( 'Y-m-d H:i:s', $started + 8 * 3600 ) : '',
				'ended_ph'      => $ended ? gmdate( 'Y-m-d H:i:s', $ended + 8 * 3600 ) : '',
				// v0.2.13: 'manual' = an editor pressed End event; 'auto' = idle
				// timeout; '' = ended before this version (reason unknown).
				'end_reason'    => (string) get_post_meta( $id, '_gplb_end_reason', true ),
				'last_entry_ph' => $last_entry ? gmdate( 'Y-m-d H:i:s', $last_entry + 8 * 3600 ) : '',
			);
		},
	) );

	/* ── Lifecycle (admin only): end / re-open / lock a liveblog ─────────── */
	register_rest_route( GPLB_REST, '/liveblogs/(?P<id>\\d+)/(?P<action>end|start|lock|unlock)', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return gplb_admin_can(); },
		'callback'            => function ( $req ) {
			$id = (int) $req['id'];
			if ( 'gp_liveblog' !== get_post_type( $id ) ) {
				return new WP_Error( 'gplb_not_found', __( 'Liveblog not found.', 'gp-liveblog' ), array( 'status' => 404 ) );
			}
			if ( 'end' === $req['action'] ) {
				gplb_end_liveblog( $id );
			} elseif ( 'start' === $req['action'] ) {
				gplb_start_liveblog( $id );
			} elseif ( 'lock' === $req['action'] ) {
				gplb_set_locked( $id, true );
			} elseif ( 'unlock' === $req['action'] ) {
				gplb_set_locked( $id, false );
			}
			// Bust the active-liveblog static cache.
			return array( 'ok' => true, 'live' => gplb_is_live( $id ), 'locked' => gplb_is_locked( $id ) );
		},
	) );

	/* ── Watching heartbeat: viewers ping every 30s while the page/panel is
	      open; the counter is a 2-minute sliding window of recent pings.
	      With a visitor id the beat also feeds total-viewer analytics. ──── */
	register_rest_route( GPLB_REST, '/liveblogs/(?P<id>\d+)/watch', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$id = (int) $req['id'];
			if ( 'gp_liveblog' !== get_post_type( $id ) ) {
				return new WP_Error( 'gplb_not_found', __( 'Liveblog not found.', 'gp-liveblog' ), array( 'status' => 404 ) );
			}
			$body    = $req->get_json_params();
			$visitor = gplb_visitor( $body['visitor'] ?? '' );
			if ( $visitor ) { gplb_record_viewer( $id, $visitor ); }
			$now    = time();
			$recent = get_transient( 'gplb_watch_' . $id );
			if ( ! is_array( $recent ) ) { $recent = array(); }
			$recent[] = $now;
			// keep only last 2 minutes
			$recent = array_values( array_filter( $recent, function ( $t ) use ( $now ) { return $t >= $now - 120; } ) );
			$recent = array_slice( $recent, -400 ); // hard cap
			set_transient( 'gplb_watch_' . $id, $recent, 180 );
			$watching = count( $recent );
			update_post_meta( $id, '_gplb_watching', $watching );
			// peak concurrent watchers (rolling max while live)
			$peak = (int) get_post_meta( $id, '_gplb_peak_watching', true );
			if ( $watching > $peak ) { update_post_meta( $id, '_gplb_peak_watching', $watching ); }
			global $wpdb;
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}gplb_viewers WHERE lb_id = %d", $id ) );
			return array( 'ok' => true, 'watching' => $watching, 'viewers' => $total, 'peak' => max( $peak, $watching ) );
		},
	) );

	/* ── Viewer reactions (v0.2.0): one reaction per visitor per entry,
	      switching emoji is allowed; totals returned. Public. ──────────── */
	register_rest_route( GPLB_REST, '/liveblogs/(?P<id>\d+)/entries/(?P<eid>\d+)/reactions', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$id  = (int) $req['id'];
			$eid = (int) $req['eid'];
			$e   = get_post( $eid );
			if ( ! $e || 'gp_liveblog_entry' !== $e->post_type || (int) $e->post_parent !== $id || 'publish' !== $e->post_status ) {
				return new WP_Error( 'gplb_not_found', __( 'Entry not found.', 'gp-liveblog' ), array( 'status' => 404 ) );
			}
			$body    = $req->get_json_params();
			$emoji   = sanitize_key( $body['emoji'] ?? '' );
			$visitor = gplb_visitor( $body['visitor'] ?? '' );
			$remove  = ! empty( $body['remove'] );
			if ( ! $visitor ) {
				return new WP_Error( 'gplb_no_visitor', __( 'Visitor id required.', 'gp-liveblog' ), array( 'status' => 400 ) );
			}
			$totals = gplb_set_reaction( $eid, $id, $emoji, $visitor, $remove );
			// bust the page stats cache so chips/panels catch up quickly
			delete_transient( 'gplb_stats_' . $id );
			return array( 'ok' => true, 'totals' => $totals, 'reacted' => $remove ? '' : $emoji );
		},
	) );

	/* ── Pinned video (v0.2.0): editors embed a YT/TikTok/IG video above
	      the entries. Empty url clears the pin. ────────────────────────── */
	register_rest_route( GPLB_REST, '/liveblogs/(?P<id>\d+)/video', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return gplb_editor_can(); },
		'callback'            => function ( $req ) {
			$id = (int) $req['id'];
			if ( 'gp_liveblog' !== get_post_type( $id ) ) {
				return new WP_Error( 'gplb_not_found', __( 'Liveblog not found.', 'gp-liveblog' ), array( 'status' => 404 ) );
			}
			$body = $req->get_json_params();
			$url  = esc_url_raw( trim( (string) ( $body['url'] ?? '' ) ) );
			if ( '' === $url ) {
				$pinned = gplb_save_pinned_video( $id, null );
				return array( 'ok' => true, 'pinned' => $pinned );
			}
			$parsed = gplb_parse_video_url( $url );
			if ( ! $parsed ) {
				return new WP_Error( 'gplb_bad_video', __( 'Supported: YouTube, TikTok or Instagram links.', 'gp-liveblog' ), array( 'status' => 400 ) );
			}
			$pinned = gplb_save_pinned_video( $id, $parsed );
			return array( 'ok' => true, 'pinned' => $pinned );
		},
	) );

	/* ── Stats (v0.2.0): public rollup for chips + internal reporting. ─── */
	register_rest_route( GPLB_REST, '/liveblogs/(?P<id>\d+)/stats', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$id = (int) $req['id'];
			if ( 'gp_liveblog' !== get_post_type( $id ) ) {
				return new WP_Error( 'gplb_not_found', __( 'Liveblog not found.', 'gp-liveblog' ), array( 'status' => 404 ) );
			}
			return gplb_liveblog_stats( $id );
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

/* ── oEmbed media previews (v0.2.0): YouTube/TikTok/Instagram ──────────
   Platform oEmbed gives a reliable thumbnail + title where generic og
   scraping is often blocked. Cached 1h per URL. */

function gplb_oembed_card( $url ) {
	$parsed = gplb_parse_video_url( $url );
	if ( ! $parsed ) { return null; } // not a supported platform link
	$key = 'gplb_oembed_' . md5( $url );
	$hit = get_transient( $key );
	if ( false !== $hit ) { return $hit; }

	$ep = '';
	if ( 'youtube' === $parsed['type'] ) { $ep = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( $url ); }
	elseif ( 'tiktok' === $parsed['type'] ) { $ep = 'https://www.tiktok.com/oembed?url=' . rawurlencode( $url ); }
	elseif ( 'instagram' === $parsed['type'] ) { $ep = 'https://graph.facebook.com/v18.0/instagram_oembed?url=' . rawurlencode( $url ) . '&access_token=' . rawurlencode( (string) apply_filters( 'gplb_ig_oembed_token', '' ) ); }

	$media = $parsed;
	if ( $ep ) {
		$resp = wp_remote_get( $ep, array(
			'timeout'    => 10,
			'redirection' => 3,
			'user-agent' => 'GadgetPilipinas Liveblog (oembed)',
		) );
		if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
			$j = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( is_array( $j ) ) {
				$media['title']  = mb_substr( wp_strip_all_tags( (string) ( $j['title'] ?? '' ) ), 0, 300 );
				$media['author'] = mb_substr( wp_strip_all_tags( (string) ( $j['author_name'] ?? '' ) ), 0, 120 );
				$media['image']  = esc_url_raw( (string) ( $j['thumbnail_url'] ?? '' ) );
				if ( ! empty( $j['thumbnail_width'] ) ) { $media['width'] = (int) $j['thumbnail_width']; }
			}
		}
	}
	// Instagram needs a Meta token; fall back to generic og scrape if no token.
	if ( 'instagram' === $parsed['type'] && empty( $media['image'] ) && empty( $media['title'] ) ) {
		$og = gplb_unfurl( $url );
		if ( $og ) {
			$media['title'] = $og['title'] ?? '';
			$media['image'] = $og['image'] ?? '';
			$media['author'] = '';
		}
	}
	// YouTube always has a thumbnail; synthesize one for TikTok edge cases
	// so the card still renders wide and pretty.
	if ( 'youtube' === $parsed['type'] && empty( $media['image'] ) ) {
		$media['image'] = 'https://i.ytimg.com/vi/' . rawurlencode( $parsed['id'] ) . '/hqdefault.jpg';
	}
	$media = array_filter( $media, function ( $v ) { return null !== $v && '' !== $v; } );
	set_transient( $key, $media, HOUR_IN_SECONDS );
	return $media;
}
