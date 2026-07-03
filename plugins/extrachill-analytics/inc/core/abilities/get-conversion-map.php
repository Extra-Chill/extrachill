<?php
/**
 * Get Conversion Map Ability
 *
 * THE first-party cross-surface conversion instrument. Answers the central
 * rebuild question deterministically and per-entry-page: *when an anonymous
 * visitor lands first on an editorial article (blog 1), do they ever reach a
 * platform surface — events, community, or artist platform — in the same
 * session or on a return visit?*
 *
 * The rebuild thesis is that song-meaning / music-history articles are
 * "fishhooks" that should convert search visitors into returning platform
 * users. Until now there has been NO first-party line from the 6M-view article
 * front door to the platform surfaces it is supposed to feed. GA's
 * path_sequence gives a sampled, aggregate cross-host count but cannot tell you
 * WHICH articles (or categories) convert. This ability makes that funnel a
 * measured, per-page, per-category number you can rank and act on.
 *
 * Why this is deterministic and bot-resistant (identical guarantees to
 * get-retention-stats, which it mirrors closely):
 *
 *   `pageview` rows in `c8c_extrachill_analytics_events` are written
 *   server-side by the track-page-view ability ONLY for real, JS-executing,
 *   non-bot browsers (the beacon fires post-load behind the canonical UA
 *   classifier). The anonymous first-party `visitor_id` is a random UUID v4 —
 *   no PII, no fingerprint — so it cleanly ties a visitor's pageviews across
 *   sessions and across the network's blogs (`blog_id`). Rows with a NULL
 *   visitor_id (opted-out via GPC/DNT, or pre-cookie) cannot be attributed to a
 *   session and are excluded by construction.
 *
 * SESSIONIZATION: a "session" is a run of a visitor's pageviews with no gap
 * larger than the inactivity timeout (default 30 minutes — the GA-standard
 * session boundary). The ENTRY session is the visitor's first session whose
 * FIRST pageview is a blog-1 editorial article. From that anchor we measure:
 *
 *   - SAME-SESSION reach: the visitor hit a platform surface (events/community/
 *     artist) within that same entry session. The strongest signal — the
 *     article handed the visitor straight to the platform.
 *   - RETURN reach: the visitor hit a platform surface in ANY later session
 *     (a separate visit, possibly days later — the visitor_id ties them). A
 *     weaker but still real conversion: the article seeded a returning user.
 *   - RETURNED: the visitor came back for any second session at all
 *     (the denominator context for return reach).
 *
 * Same-session and return reach are reported SEPARATELY because they mean
 * different things: same-session is in-the-moment recirculation you can engineer
 * with crosslinks; return reach is durable stickiness. Collapsing them would
 * hide exactly the distinction the rebuild needs.
 *
 * OUTPUT is ranked two ways — per entry ARTICLE (top entry posts by entry
 * sessions) and per entry CATEGORY (the WordPress post category of the entry
 * article, the same category axis the content-flags / content-performance
 * abilities use) — so you can SEE which fishhooks actually hook, engineer
 * crosslinks from the converters, and stop pretending the dead ones convert.
 *
 * HONEST BY CONSTRUCTION: if current reach is ~0%, that 0% IS the finding — the
 * front door is measured to be siloed from the platform — not a bug. The note
 * field always carries the window and the data caveats so a low number is read
 * as signal, never as a broken query.
 *
 * @package ExtraChill\Analytics
 * @since 0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-conversion-map ability.
 */
function extrachill_analytics_register_conversion_map_ability() {
	wp_register_ability(
		'extrachill/get-conversion-map',
		array(
			'label'               => __( 'Get Conversion Map', 'extrachill-analytics' ),
			'description'         => __( 'First-party, bot-filtered cross-surface conversion map: for visitors whose first session starts on an editorial article (blog 1), the share that reach a platform surface (events/community/artist) same-session or on a return visit. Ranked per entry article and per entry category. Deterministic from the ec_vid pageview events table.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'               => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back for the window. Default 28.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'session_gap_mins'   => array(
						'type'        => 'integer',
						'description' => __( 'Inactivity gap (minutes) that ends a session. Default 30 (GA-standard).', 'extrachill-analytics' ),
						'default'     => 30,
					),
					'top_articles'       => array(
						'type'        => 'integer',
						'description' => __( 'Number of top entry articles to rank. Default 25.', 'extrachill-analytics' ),
						'default'     => 25,
					),
					'min_entry_sessions' => array(
						'type'        => 'integer',
						'description' => __( 'Minimum entry sessions for an article/category to appear in the ranked output. Default 1.', 'extrachill-analytics' ),
						'default'     => 1,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Object with overall conversion, per-article ranking, per-category ranking, and the exact UTC window.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_conversion_map',
			'permission_callback' => 'extrachill_analytics_can_read_reports',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Resolve the blog_id for an editorial-article entry surface and the platform
 * surfaces whose reach we measure.
 *
 * Resolves from the canonical extrachill-multisite map when available (single
 * source of truth) with hardcoded production fallbacks so the read degrades
 * rather than fatals if the helper hasn't loaded.
 *
 * @return array{entry_blog_id:int,platform:array<string,int>}
 */
function extrachill_analytics_conversion_surface_map() {
	$blog = function ( $key, $fallback ) {
		return function_exists( 'ec_get_blog_id' ) && null !== ec_get_blog_id( $key )
			? (int) ec_get_blog_id( $key )
			: (int) $fallback;
	};

	return array(
		'entry_blog_id' => $blog( 'main', 1 ),
		'platform'      => array(
			'events'    => $blog( 'events', 7 ),
			'community' => $blog( 'community', 2 ),
			'artist'    => $blog( 'artist', 4 ),
		),
	);
}

/**
 * Execute callback for get-conversion-map ability.
 *
 * Strategy: pull every in-window pageview for visitors who have at least one
 * blog-1 pageview, ordered by visitor + time, then sessionize and attribute in
 * PHP. The window is small and bot-filtered, and we only load the
 * article-touching cohort, so this is a bounded read — clearer and less
 * error-prone than a multi-level windowed SQL sessionization, and it keeps the
 * exact same per-visitor semantics get-retention-stats documents.
 *
 * @param array $input Input parameters.
 * @return array Conversion map.
 */
function extrachill_analytics_ability_get_conversion_map( $input ) {
	global $wpdb;

	$days               = isset( $input['days'] ) ? max( 1, (int) $input['days'] ) : 28;
	$session_gap_mins   = isset( $input['session_gap_mins'] ) ? max( 1, (int) $input['session_gap_mins'] ) : 30;
	$top_articles       = isset( $input['top_articles'] ) ? max( 1, (int) $input['top_articles'] ) : 25;
	$min_entry_sessions = isset( $input['min_entry_sessions'] ) ? max( 1, (int) $input['min_entry_sessions'] ) : 1;

	$gap_secs = $session_gap_mins * 60;

	$table      = extrachill_analytics_events_table();
	$event_type = defined( 'EC_ANALYTICS_EVENT_PAGEVIEW' ) ? EC_ANALYTICS_EVENT_PAGEVIEW : 'pageview';

	$surfaces      = extrachill_analytics_conversion_surface_map();
	$entry_blog_id = (int) $surfaces['entry_blog_id'];
	$platform      = $surfaces['platform'];
	$platform_ids  = array_map( 'intval', array_values( $platform ) );

	$now_utc = gmdate( 'Y-m-d H:i:s' );
	$since   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

	// Pull the full in-window pageview stream for every visitor who touched the
	// entry blog at least once. We restrict to that cohort first (a subquery on
	// the visitor_created index) so we never load the entire network's stream.
	// $table is the internal, trusted network events-table name from
	// $wpdb->base_prefix — never user input — so it cannot be a prepare()
	// placeholder. All values ARE placeholdered. Direct reads are intentional
	// (no cache layer for this cross-surface read).
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT e.visitor_id, e.blog_id, e.event_data, UNIX_TIMESTAMP(e.created_at) AS ts
			FROM {$table} e
			WHERE e.event_type = %s
				AND e.visitor_id IS NOT NULL AND e.visitor_id != ''
				AND e.created_at >= %s
				AND e.visitor_id IN (
					SELECT visitor_id FROM (
						SELECT DISTINCT visitor_id
						FROM {$table}
						WHERE event_type = %s
							AND visitor_id IS NOT NULL AND visitor_id != ''
							AND created_at >= %s
							AND blog_id = %d
					) AS entry_visitors
				)
			ORDER BY e.visitor_id ASC, e.created_at ASC, e.id ASC",
			$event_type,
			$since,
			$event_type,
			$since,
			$entry_blog_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// Accumulators.
	$overall = array(
		'entry_sessions'           => 0,
		'reached_events_same'      => 0,
		'reached_community_same'   => 0,
		'reached_artist_same'      => 0,
		'reached_any_same'         => 0,
		'reached_events_return'    => 0,
		'reached_community_return' => 0,
		'reached_artist_return'    => 0,
		'reached_any_return'       => 0,
		'reached_any'              => 0,
		'returned'                 => 0,
	);

	// Per entry article (keyed by entry post_id) and per entry category (keyed
	// by category term_id). Each holds the same stat shape as $overall minus the
	// label fields, plus identifying metadata.
	$by_article  = array();
	$by_category = array();

	$platform_id_to_key = array();
	foreach ( $platform as $key => $bid ) {
		$platform_id_to_key[ (int) $bid ] = $key;
	}

	// Walk the ordered stream visitor by visitor.
	$current_visitor = null;
	$buffer          = array();

	$flush = function ( $events ) use (
		$entry_blog_id,
		$platform_ids,
		$platform_id_to_key,
		$gap_secs,
		&$overall,
		&$by_article,
		&$by_category
	) {
		if ( empty( $events ) ) {
			return;
		}

		// Sessionize: split on inactivity gaps.
		$sessions = array();
		$session  = array();
		$prev_ts  = null;
		foreach ( $events as $ev ) {
			if ( null !== $prev_ts && ( $ev['ts'] - $prev_ts ) > $gap_secs ) {
				$sessions[] = $session;
				$session    = array();
			}
			$session[] = $ev;
			$prev_ts   = $ev['ts'];
		}
		if ( ! empty( $session ) ) {
			$sessions[] = $session;
		}

		// Find the ENTRY session: the first session whose first pageview is a
		// blog-1 editorial article. If the visitor never enters via the article
		// front door (e.g. their first session starts on a platform surface),
		// they are not an article-entry visitor and are skipped entirely — the
		// funnel question is specifically about article-started journeys.
		$entry_index = null;
		foreach ( $sessions as $i => $sess ) {
			if ( (int) $sess[0]['blog_id'] === $entry_blog_id ) {
				$entry_index = $i;
				break;
			}
		}
		if ( null === $entry_index ) {
			return;
		}

		$entry_session = $sessions[ $entry_index ];
		$entry_post_id = (int) ( $entry_session[0]['post_id'] ?? 0 );

		// Same-session reach: any platform pageview within the entry session.
		$same = array(
			'events'    => false,
			'community' => false,
			'artist'    => false,
		);
		foreach ( $entry_session as $ev ) {
			$bid = (int) $ev['blog_id'];
			if ( isset( $platform_id_to_key[ $bid ] ) ) {
				$same[ $platform_id_to_key[ $bid ] ] = true;
			}
		}

		// Return reach: did the visitor have any later session, and did any
		// later session touch a platform surface?
		$returned = ( count( $sessions ) > ( $entry_index + 1 ) );
		$ret      = array(
			'events'    => false,
			'community' => false,
			'artist'    => false,
		);
		foreach ( $sessions as $i => $sess ) {
			if ( $i <= $entry_index ) {
				continue;
			}
			foreach ( $sess as $ev ) {
				$bid = (int) $ev['blog_id'];
				if ( isset( $platform_id_to_key[ $bid ] ) ) {
					$ret[ $platform_id_to_key[ $bid ] ] = true;
				}
			}
		}

		$same_any = $same['events'] || $same['community'] || $same['artist'];
		$ret_any  = $ret['events'] || $ret['community'] || $ret['artist'];
		$any      = $same_any || $ret_any;

		// Accumulate into a target stat bucket (passed by reference).
		$apply = function ( &$bucket ) use ( $same, $ret, $same_any, $ret_any, $any, $returned ) {
			++$bucket['entry_sessions'];
			$bucket['reached_events_same']      += $same['events'] ? 1 : 0;
			$bucket['reached_community_same']   += $same['community'] ? 1 : 0;
			$bucket['reached_artist_same']      += $same['artist'] ? 1 : 0;
			$bucket['reached_any_same']         += $same_any ? 1 : 0;
			$bucket['reached_events_return']    += $ret['events'] ? 1 : 0;
			$bucket['reached_community_return'] += $ret['community'] ? 1 : 0;
			$bucket['reached_artist_return']    += $ret['artist'] ? 1 : 0;
			$bucket['reached_any_return']       += $ret_any ? 1 : 0;
			$bucket['reached_any']              += $any ? 1 : 0;
			$bucket['returned']                 += $returned ? 1 : 0;
		};

		$apply( $overall );

		// Per article.
		if ( $entry_post_id > 0 ) {
			if ( ! isset( $by_article[ $entry_post_id ] ) ) {
				$by_article[ $entry_post_id ] = extrachill_analytics_conversion_zero_bucket();
			}
			$apply( $by_article[ $entry_post_id ] );
		}

		// Per category — resolve the entry article's categories (a post may have
		// several; attribute the entry session to each so a category total is
		// "entry sessions whose article belongs to this category").
		$term_ids = $entry_post_id > 0 ? extrachill_analytics_conversion_post_categories( $entry_post_id ) : array();
		foreach ( $term_ids as $term_id => $term_name ) {
			if ( ! isset( $by_category[ $term_id ] ) ) {
				$by_category[ $term_id ]         = extrachill_analytics_conversion_zero_bucket();
				$by_category[ $term_id ]['name'] = $term_name;
			}
			$apply( $by_category[ $term_id ] );
		}
	};

	foreach ( (array) $rows as $row ) {
		$vid = (string) $row->visitor_id;
		if ( $vid !== $current_visitor ) {
			if ( null !== $current_visitor ) {
				$flush( $buffer );
			}
			$current_visitor = $vid;
			$buffer          = array();
		}

		$post_id = 0;
		if ( ! empty( $row->event_data ) ) {
			$decoded = json_decode( (string) $row->event_data, true );
			if ( is_array( $decoded ) && isset( $decoded['post_id'] ) ) {
				$post_id = (int) $decoded['post_id'];
			}
		}

		$buffer[] = array(
			'blog_id' => (int) $row->blog_id,
			'ts'      => (int) $row->ts,
			'post_id' => $post_id,
		);
	}
	if ( null !== $current_visitor ) {
		$flush( $buffer );
	}

	// Build ranked article output (resolve titles for the top N).
	$article_rank = array();
	foreach ( $by_article as $post_id => $bucket ) {
		if ( $bucket['entry_sessions'] < $min_entry_sessions ) {
			continue;
		}
		$article_rank[] = extrachill_analytics_conversion_rate_row(
			$bucket,
			array(
				'post_id' => (int) $post_id,
			)
		);
	}
	usort(
		$article_rank,
		static function ( $a, $b ) {
			return $b['entry_sessions'] <=> $a['entry_sessions'];
		}
	);
	$article_rank = array_slice( $article_rank, 0, $top_articles );
	// Resolve titles/permalinks only for the surviving top rows (blog 1 context).
	foreach ( $article_rank as &$ar ) {
		$post        = extrachill_analytics_get_blog_post( $entry_blog_id, (int) $ar['post_id'] );
		$ar['title'] = $post ? $post->post_title : '(unknown)';
		$ar['slug']  = $post ? $post->post_name : '';
	}
	unset( $ar );

	// Build ranked category output.
	$category_rank = array();
	foreach ( $by_category as $term_id => $bucket ) {
		if ( $bucket['entry_sessions'] < $min_entry_sessions ) {
			continue;
		}
		$category_rank[] = extrachill_analytics_conversion_rate_row(
			$bucket,
			array(
				'term_id'  => (int) $term_id,
				'category' => isset( $bucket['name'] ) ? $bucket['name'] : '',
			)
		);
	}
	usort(
		$category_rank,
		static function ( $a, $b ) {
			return $b['entry_sessions'] <=> $a['entry_sessions'];
		}
	);

	return array(
		'overall'          => extrachill_analytics_conversion_rate_row( $overall, array() ),
		'by_article'       => $article_rank,
		'by_category'      => $category_rank,
		'entry_blog_id'    => $entry_blog_id,
		'platform_blogs'   => $platform,
		'days'             => $days,
		'session_gap_mins' => $session_gap_mins,
		'period'           => gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' ),
		'since'            => $since,
		'as_of'            => $now_utc,
		'note'             => 'THE first-party cross-surface conversion funnel. entry_sessions = visitor journeys whose first session starts on a blog-1 editorial article. same-session reach = hit a platform surface (events/community/artist) within that entry session; return reach = hit one in a later session (visitor_id ties sessions across days); the two are distinct and reported separately. Deterministic + bot-filtered: pageview rows are written server-side only for non-bot, JS-executing browsers; visitor_id is an anonymous first-party UUID v4 (no PII). NULL-visitor rows (GPC/DNT opt-out) cannot be sessionized and are excluded. A low or zero reach is the real signal — the article front door measured to be siloed from the platform — not a broken query. The first-party pageview table is young, so short windows reflect only recently-accumulated visitor history.',
	);
}

/**
 * Build a zeroed per-target stat bucket.
 *
 * @return array Zeroed accumulator.
 */
function extrachill_analytics_conversion_zero_bucket() {
	return array(
		'entry_sessions'           => 0,
		'reached_events_same'      => 0,
		'reached_community_same'   => 0,
		'reached_artist_same'      => 0,
		'reached_any_same'         => 0,
		'reached_events_return'    => 0,
		'reached_community_return' => 0,
		'reached_artist_return'    => 0,
		'reached_any_return'       => 0,
		'reached_any'              => 0,
		'returned'                 => 0,
	);
}

/**
 * Project a raw stat bucket into an output row with computed rates.
 *
 * @param array $bucket Raw accumulator counts.
 * @param array $extra  Extra identifying fields to merge in (post_id, term_id...).
 * @return array Output row with absolute counts and rounded rates.
 */
function extrachill_analytics_conversion_rate_row( $bucket, $extra = array() ) {
	$n   = max( 0, (int) $bucket['entry_sessions'] );
	$pct = function ( $count ) use ( $n ) {
		return $n > 0 ? round( (int) $count / $n, 4 ) : 0.0;
	};

	$row = array(
		'entry_sessions'           => $n,
		'reached_any'              => (int) $bucket['reached_any'],
		'reached_any_rate'         => $pct( $bucket['reached_any'] ),
		'same_session'             => array(
			'events'    => $pct( $bucket['reached_events_same'] ),
			'community' => $pct( $bucket['reached_community_same'] ),
			'artist'    => $pct( $bucket['reached_artist_same'] ),
			'any'       => $pct( $bucket['reached_any_same'] ),
		),
		'return'                   => array(
			'events'    => $pct( $bucket['reached_events_return'] ),
			'community' => $pct( $bucket['reached_community_return'] ),
			'artist'    => $pct( $bucket['reached_artist_return'] ),
			'any'       => $pct( $bucket['reached_any_return'] ),
		),
		'returned_rate'            => $pct( $bucket['returned'] ),
		'reached_any_same_count'   => (int) $bucket['reached_any_same'],
		'reached_any_return_count' => (int) $bucket['reached_any_return'],
		'returned_count'           => (int) $bucket['returned'],
	);

	return array_merge( $extra, $row );
}

/**
 * Resolve the WordPress post categories for an entry article on the entry blog.
 *
 * Uses the same `category` taxonomy axis the content-flags / content-performance
 * abilities key on (e.g. song-meanings, music-history). Returns a map of
 * term_id => term name. Switches into the entry blog only for the read.
 *
 * @param int $post_id Entry article post ID (blog 1 context).
 * @return array<int,string> Map of term_id to term name.
 */
function extrachill_analytics_conversion_post_categories( $post_id ) {
	static $cache         = array();
	static $entry_blog_id = null;

	if ( isset( $cache[ $post_id ] ) ) {
		return $cache[ $post_id ];
	}

	if ( null === $entry_blog_id ) {
		$surfaces      = extrachill_analytics_conversion_surface_map();
		$entry_blog_id = (int) $surfaces['entry_blog_id'];
	}

	$switched = false;
	if ( is_multisite() && get_current_blog_id() !== $entry_blog_id ) {
		switch_to_blog( $entry_blog_id );
		$switched = true;
	}

	$terms = get_the_terms( $post_id, 'category' );
	$map   = array();
	if ( is_array( $terms ) ) {
		foreach ( $terms as $term ) {
			$map[ (int) $term->term_id ] = $term->name;
		}
	}

	if ( $switched ) {
		restore_current_blog();
	}

	$cache[ $post_id ] = $map;
	return $map;
}

/**
 * Fetch a post from a specific blog without leaking the switch.
 *
 * @param int $blog_id Blog ID.
 * @param int $post_id Post ID.
 * @return WP_Post|null Post object or null.
 */
function extrachill_analytics_get_blog_post( $blog_id, $post_id ) {
	if ( $post_id <= 0 ) {
		return null;
	}

	$switched = false;
	if ( is_multisite() && get_current_blog_id() !== (int) $blog_id ) {
		switch_to_blog( (int) $blog_id );
		$switched = true;
	}

	$post = get_post( $post_id );

	if ( $switched ) {
		restore_current_blog();
	}

	return $post instanceof WP_Post ? $post : null;
}
