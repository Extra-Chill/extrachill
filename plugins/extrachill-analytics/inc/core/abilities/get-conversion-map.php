<?php
/**
 * Get Conversion Map Ability
 *
 * THE first-party cross-surface conversion instrument. Answers the central
 * rebuild question deterministically and per-entry-page: *when an anonymous
 * visitor starts an eligible journey on an editorial article (blog 1), do they
 * ever reach a tracked destination on the events, community, or
 * artist platform in the same session or on a return visit?*
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
 * SCOPE: entry semantics remain editorial and post-backed. Destination reach
 * includes every eligible pageview collected on a platform blog, including
 * route-level homepage, archive, search, auth, and directory views added by
 * issue #182. Historical periods remain singular-only before that deployment.
 *
 * SESSIONIZATION: a "session" is a run of a visitor's pageviews with no gap
 * larger than the inactivity timeout (default 30 minutes — the GA-standard
 * session boundary). The ENTRY session is the visitor's first eligible session
 * whose FIRST pageview is a published blog-1 `post`. Only the first eligible,
 * mature journey per visitor in the reporting window enters the denominator.
 * From that anchor we measure:
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
			'description'         => __( 'First-party, bot-filtered editorial-to-platform conversion map: for visitors whose first eligible journey starts on a published blog-1 post, the share that reach an eligible collected route on events/community/artist same-session or on a return visit. Ranked per entry article and category.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'                    => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back for the window. Default 28.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'session_gap_mins'        => array(
						'type'        => 'integer',
						'description' => __( 'Inactivity gap (minutes) that ends a session. Default 30 (GA-standard).', 'extrachill-analytics' ),
						'default'     => 30,
					),
					'top_articles'            => array(
						'type'        => 'integer',
						'description' => __( 'Number of top entry articles to rank. Default 25.', 'extrachill-analytics' ),
						'default'     => 25,
					),
					'min_entry_sessions'      => array(
						'type'        => 'integer',
						'description' => __( 'Minimum entry sessions for an article/category to appear in the ranked output. Default 1.', 'extrachill-analytics' ),
						'default'     => 1,
					),
					'return_observation_days' => array(
						'type'        => 'integer',
						'description' => __( 'Minimum completed days after an entry journey before it enters the denominator. Excludes late-window entries with unequal return opportunity. Default 7.', 'extrachill-analytics' ),
						'default'     => 7,
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

	$days                    = isset( $input['days'] ) ? max( 1, (int) $input['days'] ) : 28;
	$session_gap_mins        = isset( $input['session_gap_mins'] ) ? max( 1, (int) $input['session_gap_mins'] ) : 30;
	$top_articles            = isset( $input['top_articles'] ) ? max( 1, (int) $input['top_articles'] ) : 25;
	$min_entry_sessions      = isset( $input['min_entry_sessions'] ) ? max( 1, (int) $input['min_entry_sessions'] ) : 1;
	$return_observation_days = isset( $input['return_observation_days'] ) ? max( 0, (int) $input['return_observation_days'] ) : 7;

	$gap_secs = $session_gap_mins * 60;

	$table      = extrachill_analytics_events_table();
	$event_type = defined( 'EC_ANALYTICS_EVENT_PAGEVIEW' ) ? EC_ANALYTICS_EVENT_PAGEVIEW : 'pageview';

	$surfaces      = extrachill_analytics_conversion_surface_map();
	$entry_blog_id = (int) $surfaces['entry_blog_id'];
	$platform      = $surfaces['platform'];
	$platform_ids  = array_map( 'intval', array_values( $platform ) );

	$now_utc       = gmdate( 'Y-m-d H:i:s' );
	$since         = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
	$stream_since  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days -{$session_gap_mins} minutes" ) );
	$mature_before = gmdate( 'Y-m-d H:i:s', strtotime( "-{$return_observation_days} days" ) );

	// Pull one inactivity-gap of pre-window context plus the in-window stream for
	// every visitor who touched the entry blog. The buffer prevents a session
	// that started just before the lower boundary from being truncated. We
	// restrict to that cohort first (a subquery on
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
			$stream_since,
			$event_type,
			$stream_since,
			$entry_blog_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// Outcomes are read independently from the pageview cohort because direct
	// source attribution remains useful when a signup has no visitor identity.
	// Successful registrations/newsletter signups are server-side outcomes, so
	// their write-time request classifier is not used as an outcome validity
	// filter (REST registration requests can legitimately carry is_bot=true).
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$outcome_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, event_type, event_data, source_url, user_id, visitor_id, UNIX_TIMESTAMP(created_at) AS ts
			FROM {$table}
			WHERE event_type IN (%s, %s)
				AND created_at >= %s
				AND created_at <= %s
			ORDER BY created_at ASC, id ASC",
			'newsletter_signup',
			'user_registration',
			$since,
			$now_utc
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

	$outcome_types        = array( 'newsletter_signup', 'user_registration' );
	$outcome_overall      = extrachill_analytics_conversion_outcome_zero_bucket();
	$outcomes_by_article  = array();
	$outcomes_by_category = array();
	$outcome_coverage     = array();
	foreach ( $outcome_types as $outcome_type ) {
		$outcome_coverage[ $outcome_type ] = extrachill_analytics_conversion_outcome_zero_coverage();
	}
	$journeys_by_visitor = array();

	$platform_id_to_key = array();
	foreach ( $platform as $key => $bid ) {
		$platform_id_to_key[ (int) $bid ] = $key;
	}

	// Walk the ordered stream visitor by visitor.
	$current_visitor = null;
	$buffer          = array();

	$flush = function (
		$events,
		$visitor_id
	) use (
		$entry_blog_id,
		$platform_ids,
		$platform_id_to_key,
		$gap_secs,
		$since,
		$mature_before,
		&$overall,
		&$by_article,
		&$by_category,
		&$journeys_by_visitor
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

		// Find the first eligible, mature entry journey in the reporting window.
		// One journey per visitor is deliberate: the denominator is visitor
		// journeys, not every entry session a repeat reader starts.
		$entry_index = null;
		foreach ( $sessions as $i => $sess ) {
			if ( extrachill_analytics_conversion_is_mature_entry_session( $sess[0], $entry_blog_id, $since, $mature_before ) ) {
				$entry_index = $i;
				break;
			}
		}
		if ( null === $entry_index ) {
			return;
		}

		$entry_session                      = $sessions[ $entry_index ];
		$entry_post_id                      = (int) ( $entry_session[0]['post_id'] ?? 0 );
		$journeys_by_visitor[ $visitor_id ] = array(
			'post_id'              => $entry_post_id,
			'entry_ts'             => (int) $entry_session[0]['ts'],
			'same_session_through' => (int) $entry_session[ count( $entry_session ) - 1 ]['ts'] + $gap_secs,
		);

		// Same-session reach: any platform pageview within the entry session.
		$same = array(
			'events'    => false,
			'community' => false,
			'artist'    => false,
		);
		foreach ( $entry_session as $ev ) {
			if ( extrachill_analytics_conversion_is_measured_platform_event( $ev, $platform_id_to_key ) ) {
				$same[ $platform_id_to_key[ (int) $ev['blog_id'] ] ] = true;
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
				if ( extrachill_analytics_conversion_is_measured_platform_event( $ev, $platform_id_to_key ) ) {
					$ret[ $platform_id_to_key[ (int) $ev['blog_id'] ] ] = true;
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
				$flush( $buffer, $current_visitor );
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
		$flush( $buffer, $current_visitor );
	}

	// Attribute each deduplicated outcome through two independent lenses:
	// direct source_url resolution and the visitor's eligible entry journey.
	// Neither lens fills gaps in the other.
	$seen_outcomes = array();
	foreach ( (array) $outcome_rows as $row ) {
		$type = (string) $row->event_type;
		if ( ! isset( $outcome_coverage[ $type ] ) ) {
			continue;
		}

		$data = json_decode( (string) $row->event_data, true );
		$data = is_array( $data ) ? $data : array();
		++$outcome_coverage[ $type ]['stored_events'];

		// The newsletter emitter already skips registration subscriptions. Keep
		// this guard so legacy or alternate emitters cannot inflate the outcome.
		if ( 'newsletter_signup' === $type && 'registration' === (string) ( $data['context'] ?? '' ) ) {
			++$outcome_coverage[ $type ]['automatic_registration_excluded'];
			continue;
		}

		$outcome    = array(
			'id'         => (int) $row->id,
			'event_type' => $type,
			'event_data' => $data,
			'source_url' => (string) $row->source_url,
			'user_id'    => (int) $row->user_id,
			'visitor_id' => (string) $row->visitor_id,
			'ts'         => (int) $row->ts,
		);
		$dedupe_key = extrachill_analytics_conversion_outcome_dedupe_key( $outcome );
		if ( isset( $seen_outcomes[ $type ][ $dedupe_key ] ) ) {
			++$outcome_coverage[ $type ]['duplicate_events'];
			continue;
		}
		$seen_outcomes[ $type ][ $dedupe_key ] = true;
		++$outcome_coverage[ $type ]['deduplicated_outcomes'];

		$direct_post_id = 0;
		if ( '' === trim( $outcome['source_url'] ) ) {
			++$outcome_coverage[ $type ]['missing_source_url'];
		} else {
			++$outcome_coverage[ $type ]['with_source_url'];
			$direct_post_id = extrachill_analytics_conversion_source_article_id( $outcome['source_url'], $entry_blog_id );
			if ( $direct_post_id > 0 ) {
				++$outcome_coverage[ $type ]['direct_source_attributed'];
				extrachill_analytics_conversion_apply_outcome( $outcome_overall, $type, 'direct_source' );
				extrachill_analytics_conversion_apply_article_outcome(
					$outcomes_by_article,
					$outcomes_by_category,
					$direct_post_id,
					$type,
					'direct_source'
				);
			} else {
				++$outcome_coverage[ $type ]['unresolved_source_url'];
			}
		}

		$visitor_id = $outcome['visitor_id'];
		if ( '' === $visitor_id ) {
			++$outcome_coverage[ $type ]['missing_visitor_identity'];
			continue;
		}
		++$outcome_coverage[ $type ]['with_visitor_identity'];

		if ( ! isset( $journeys_by_visitor[ $visitor_id ] ) ) {
			++$outcome_coverage[ $type ]['identity_without_eligible_journey'];
			continue;
		}

		$stage = extrachill_analytics_conversion_outcome_journey_stage( $outcome['ts'], $journeys_by_visitor[ $visitor_id ] );
		if ( null === $stage ) {
			++$outcome_coverage[ $type ]['outcome_before_entry'];
			continue;
		}

		++$outcome_coverage[ $type ]['visitor_journey_attributed'];
		extrachill_analytics_conversion_apply_outcome( $outcome_overall, $type, $stage );
		extrachill_analytics_conversion_apply_article_outcome(
			$outcomes_by_article,
			$outcomes_by_category,
			(int) $journeys_by_visitor[ $visitor_id ]['post_id'],
			$type,
			$stage
		);
	}

	foreach ( $outcome_types as $outcome_type ) {
		$outcome_coverage[ $outcome_type ] = extrachill_analytics_conversion_finalize_outcome_coverage( $outcome_coverage[ $outcome_type ] );
	}

	$article_outcomes  = extrachill_analytics_conversion_rank_article_outcomes(
		$outcomes_by_article,
		$outcome_coverage,
		$entry_blog_id,
		$top_articles
	);
	$category_outcomes = extrachill_analytics_conversion_rank_category_outcomes( $outcomes_by_category, $outcome_coverage );

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
	// Resolve identity only for the surviving top rows (blog 1 context).
	foreach ( $article_rank as &$ar ) {
		$post     = extrachill_analytics_get_blog_post( $entry_blog_id, (int) $ar['post_id'] );
		$identity = extrachill_analytics_conversion_article_identity( $entry_blog_id, $post );
		$ar       = array_merge( $ar, $identity );
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
		'overall'                     => extrachill_analytics_conversion_rate_row( $overall, array() ),
		'by_article'                  => $article_rank,
		'by_category'                 => $category_rank,
		'entry_blog_id'               => $entry_blog_id,
		'platform_blogs'              => $platform,
		'outcomes'                    => array(
			'overall'               => extrachill_analytics_conversion_outcome_row( $outcome_overall, $outcome_coverage ),
			'by_article'            => $article_outcomes,
			'by_category'           => $category_outcomes,
			'coverage'              => $outcome_coverage,
			'attribution_semantics' => 'direct_source resolves the outcome event source_url to a published main-site article. visitor_journey attributes an identified outcome occurring after that visitor\'s first eligible mature entry journey, split at the configured pageview-session boundary. The lenses are independent and may both attribute one outcome; do not add them as unique people. Coverage status measured, partial, or not_instrumented must be read with each count.',
		),
		'days'                        => $days,
		'session_gap_mins'            => $session_gap_mins,
		'return_observation_days'     => $return_observation_days,
		'denominator'                 => 'One first eligible, mature editorial-entry journey per visitor. An eligible entry is a session starting in the reporting window on a published blog-1 post; late entries without the configured return observation period are excluded.',
		'measured_destination_routes' => 'Eligible collected pageviews on events, community, and artist, including post-backed singular views and route-level homepage, archive, search, auth, and directory views. Entry journeys remain published blog-1 posts only. Historical periods before issue #182 deployment remain singular-only.',
		'period'                      => gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' ),
		'since'                       => $since,
		'as_of'                       => $now_utc,
		'note'                        => 'First-party, bot-filtered editorial-to-platform funnel. entry_sessions is a legacy field name: it counts one first eligible, mature entry journey per visitor, not every entry session. Eligible entries start on a published blog-1 post; route views never become editorial entries. Same-session and return reach include eligible collected events/community/artist routes. Newsletter and registration outcomes are successful server-side events and are reported through separate direct-source and visitor-journey lenses. Automatic registration newsletter subscriptions are excluded. Missing source or visitor identity remains explicit coverage, never an inferred zero. Route-level destination collection is additive from issue #182 onward, so historical periods remain singular-only. The query reads one inactivity-gap before the lower boundary to avoid session truncation, and excludes late entries until they have the configured return observation period. NULL-visitor pageviews (GPC/DNT opt-out) cannot be sessionized and are excluded.',
	);
}

/**
 * Build an empty outcome-attribution bucket.
 *
 * @return array<string,array<string,int>> Outcome counts by type and lens.
 */
function extrachill_analytics_conversion_outcome_zero_bucket() {
	$shape = array(
		'direct_source' => 0,
		'same_session'  => 0,
		'later_session' => 0,
	);

	return array(
		'newsletter_signup' => $shape,
		'user_registration' => $shape,
	);
}

/**
 * Build empty coverage counters for one outcome type.
 *
 * @return array<string,int> Coverage counters.
 */
function extrachill_analytics_conversion_outcome_zero_coverage() {
	return array(
		'stored_events'                     => 0,
		'automatic_registration_excluded'   => 0,
		'deduplicated_outcomes'             => 0,
		'duplicate_events'                  => 0,
		'with_source_url'                   => 0,
		'direct_source_attributed'          => 0,
		'missing_source_url'                => 0,
		'unresolved_source_url'             => 0,
		'with_visitor_identity'             => 0,
		'missing_visitor_identity'          => 0,
		'visitor_journey_attributed'        => 0,
		'identity_without_eligible_journey' => 0,
		'outcome_before_entry'              => 0,
	);
}

/**
 * Deduplicate an outcome by its strongest available person identity.
 *
 * Registration user IDs live in event_data because the account is created
 * before the request becomes authenticated. Visitor identity is the fallback;
 * identity-free events remain individually observable rather than collapsed.
 *
 * @param array $outcome Normalized outcome row.
 * @return string Stable type-local deduplication key.
 */
function extrachill_analytics_conversion_outcome_dedupe_key( $outcome ) {
	$data    = isset( $outcome['event_data'] ) && is_array( $outcome['event_data'] ) ? $outcome['event_data'] : array();
	$user_id = (int) ( $data['user_id'] ?? ( $outcome['user_id'] ?? 0 ) );
	if ( $user_id > 0 ) {
		return 'user:' . $user_id;
	}

	$visitor_id = trim( (string) ( $outcome['visitor_id'] ?? '' ) );
	if ( '' !== $visitor_id ) {
		return 'visitor:' . $visitor_id;
	}

	return 'event:' . (int) ( $outcome['id'] ?? 0 );
}

/**
 * Classify an identified outcome relative to an eligible entry journey.
 *
 * @param int   $outcome_ts Outcome UTC timestamp.
 * @param array $journey    Entry timestamp and same-session upper boundary.
 * @return string|null same_session, later_session, or null before entry.
 */
function extrachill_analytics_conversion_outcome_journey_stage( $outcome_ts, $journey ) {
	if ( (int) $outcome_ts < (int) ( $journey['entry_ts'] ?? 0 ) ) {
		return null;
	}

	return (int) $outcome_ts <= (int) ( $journey['same_session_through'] ?? 0 )
		? 'same_session'
		: 'later_session';
}

/**
 * Resolve a direct source URL to a published main-site article.
 *
 * @param string $source_url    Outcome source URL.
 * @param int    $entry_blog_id Editorial blog ID.
 * @return int Published article ID, or 0 when outside coverage/unresolved.
 */
function extrachill_analytics_conversion_source_article_id( $source_url, $entry_blog_id ) {
	$source_host = strtolower( (string) wp_parse_url( $source_url, PHP_URL_HOST ) );
	$switched    = false;
	if ( is_multisite() && get_current_blog_id() !== (int) $entry_blog_id ) {
		switch_to_blog( (int) $entry_blog_id );
		$switched = true;
	}
	$entry_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

	if ( preg_replace( '/^www\./', '', $source_host ) !== preg_replace( '/^www\./', '', $entry_host ) ) {
		if ( $switched ) {
			restore_current_blog();
		}
		return 0;
	}

	$post_id = (int) url_to_postid( $source_url );
	if ( $switched ) {
		restore_current_blog();
	}

	return $post_id > 0 && extrachill_analytics_conversion_is_editorial_entry(
		array(
			'blog_id' => $entry_blog_id,
			'post_id' => $post_id,
		),
		$entry_blog_id
	) ? $post_id : 0;
}

/**
 * Increment one outcome lens in a bucket.
 *
 * @param array  $bucket Outcome bucket, by reference.
 * @param string $type   Outcome event type.
 * @param string $lens   direct_source, same_session, or later_session.
 */
function extrachill_analytics_conversion_apply_outcome( &$bucket, $type, $lens ) {
	if ( isset( $bucket[ $type ][ $lens ] ) ) {
		++$bucket[ $type ][ $lens ];
	}
}

/**
 * Apply an attributed outcome to article and category buckets.
 *
 * @param array  $by_article  Article buckets, by reference.
 * @param array  $by_category Category buckets, by reference.
 * @param int    $post_id     Entry/direct-source article ID.
 * @param string $type        Outcome event type.
 * @param string $lens        Attribution lens.
 */
function extrachill_analytics_conversion_apply_article_outcome( &$by_article, &$by_category, $post_id, $type, $lens ) {
	if ( $post_id <= 0 ) {
		return;
	}
	if ( ! isset( $by_article[ $post_id ] ) ) {
		$by_article[ $post_id ] = extrachill_analytics_conversion_outcome_zero_bucket();
	}
	extrachill_analytics_conversion_apply_outcome( $by_article[ $post_id ], $type, $lens );

	foreach ( extrachill_analytics_conversion_post_categories( $post_id ) as $term_id => $term_name ) {
		if ( ! isset( $by_category[ $term_id ] ) ) {
			$by_category[ $term_id ] = array(
				'name'     => $term_name,
				'outcomes' => extrachill_analytics_conversion_outcome_zero_bucket(),
			);
		}
		extrachill_analytics_conversion_apply_outcome( $by_category[ $term_id ]['outcomes'], $type, $lens );
	}
}

/**
 * Finalize coverage statuses without converting missing instrumentation to zero.
 *
 * @param array $coverage Raw coverage counters.
 * @return array Coverage counters plus stable lens statuses.
 */
function extrachill_analytics_conversion_finalize_outcome_coverage( $coverage ) {
	$total = (int) $coverage['deduplicated_outcomes'];
	if ( 0 === $total ) {
		$direct_status  = 'measured';
		$journey_status = 'measured';
	} else {
		$direct_status  = 0 === (int) $coverage['with_source_url']
			? 'not_instrumented'
			: ( (int) $coverage['direct_source_attributed'] === $total ? 'measured' : 'partial' );
		$journey_status = 0 === (int) $coverage['with_visitor_identity']
			? 'not_instrumented'
			: ( (int) $coverage['with_visitor_identity'] === $total ? 'measured' : 'partial' );
	}

	$coverage['direct_source_status']   = $direct_status;
	$coverage['visitor_journey_status'] = $journey_status;
	return $coverage;
}

/**
 * Project outcome counts with their coverage status.
 *
 * Not_instrumented lenses return null counts. Partial lenses retain measured
 * counts alongside the explicit status and missing-coverage counters.
 *
 * @param array $bucket   Outcome counts.
 * @param array $coverage Finalized coverage keyed by outcome type.
 * @return array<string,array<string,mixed>> Machine-readable outcomes.
 */
function extrachill_analytics_conversion_outcome_row( $bucket, $coverage ) {
	$row = array();
	foreach ( array( 'newsletter_signup', 'user_registration' ) as $type ) {
		$direct_status  = (string) $coverage[ $type ]['direct_source_status'];
		$journey_status = (string) $coverage[ $type ]['visitor_journey_status'];
		$row[ $type ]   = array(
			'direct_source'   => array(
				'count'           => 'not_instrumented' === $direct_status ? null : (int) $bucket[ $type ]['direct_source'],
				'coverage_status' => $direct_status,
			),
			'visitor_journey' => array(
				'same_session_count'  => 'not_instrumented' === $journey_status ? null : (int) $bucket[ $type ]['same_session'],
				'later_session_count' => 'not_instrumented' === $journey_status ? null : (int) $bucket[ $type ]['later_session'],
				'coverage_status'     => $journey_status,
			),
		);
	}
	return $row;
}

/**
 * Rank article outcome rows deterministically.
 *
 * @param array $buckets       Outcome buckets keyed by post ID.
 * @param array $coverage      Finalized coverage.
 * @param int   $entry_blog_id Editorial blog ID.
 * @param int   $limit         Maximum rows.
 * @return array<int,array<string,mixed>> Ranked article outcomes.
 */
function extrachill_analytics_conversion_rank_article_outcomes( $buckets, $coverage, $entry_blog_id, $limit ) {
	$rows = array();
	foreach ( $buckets as $post_id => $bucket ) {
		$post   = extrachill_analytics_get_blog_post( $entry_blog_id, (int) $post_id );
		$rows[] = array_merge(
			array(
				'post_id'             => (int) $post_id,
				'attribution_signals' => extrachill_analytics_conversion_outcome_signal_total( $bucket ),
				'outcomes'            => extrachill_analytics_conversion_outcome_row( $bucket, $coverage ),
			),
			extrachill_analytics_conversion_article_identity( $entry_blog_id, $post )
		);
	}
	usort(
		$rows,
		static function ( $a, $b ) {
			$signal_order = $b['attribution_signals'] <=> $a['attribution_signals'];
			return 0 !== $signal_order ? $signal_order : $a['post_id'] <=> $b['post_id'];
		}
	);
	return array_slice( $rows, 0, $limit );
}

/**
 * Rank category outcome rows deterministically.
 *
 * @param array $buckets  Category records keyed by term ID.
 * @param array $coverage Finalized coverage.
 * @return array<int,array<string,mixed>> Ranked category outcomes.
 */
function extrachill_analytics_conversion_rank_category_outcomes( $buckets, $coverage ) {
	$rows = array();
	foreach ( $buckets as $term_id => $record ) {
		$rows[] = array(
			'term_id'             => (int) $term_id,
			'category'            => (string) $record['name'],
			'attribution_signals' => extrachill_analytics_conversion_outcome_signal_total( $record['outcomes'] ),
			'outcomes'            => extrachill_analytics_conversion_outcome_row( $record['outcomes'], $coverage ),
		);
	}
	usort(
		$rows,
		static function ( $a, $b ) {
			$signal_order = $b['attribution_signals'] <=> $a['attribution_signals'];
			return 0 !== $signal_order ? $signal_order : $a['term_id'] <=> $b['term_id'];
		}
	);
	return $rows;
}

/**
 * Count attribution signals for deterministic ranking only.
 *
 * Direct and journey signals may describe the same outcome and are therefore
 * intentionally not labelled as unique outcomes.
 *
 * @param array $bucket Outcome counts.
 * @return int Attribution signal count.
 */
function extrachill_analytics_conversion_outcome_signal_total( $bucket ) {
	$total = 0;
	foreach ( $bucket as $counts ) {
		$total += array_sum( array_map( 'intval', $counts ) );
	}
	return $total;
}

/**
 * Resolve stable identity for an article output row.
 *
 * @param int          $blog_id Entry blog ID.
 * @param WP_Post|null $post    Entry article post.
 * @return array{title:string,slug:string,url:string,path:string} Article identity.
 */
function extrachill_analytics_conversion_article_identity( $blog_id, $post ) {
	if ( ! $post instanceof WP_Post ) {
		return array(
			'title' => '(unknown)',
			'slug'  => '',
			'url'   => '',
			'path'  => '',
		);
	}

	$switched = false;
	if ( is_multisite() && get_current_blog_id() !== (int) $blog_id ) {
		switch_to_blog( (int) $blog_id );
		$switched = true;
	}

	$url  = (string) get_permalink( $post );
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( '' !== $path ) {
		$path = '/' . ltrim( $path, '/' );
	}

	if ( $switched ) {
		restore_current_blog();
	}

	return array(
		'title' => (string) $post->post_title,
		'slug'  => (string) $post->post_name,
		'url'   => $url,
		'path'  => $path,
	);
}

/**
 * Whether a session-start event is a published editorial post on the entry blog.
 *
 * @param array $event         Session event.
 * @param int   $entry_blog_id Editorial blog ID.
 * @return bool Whether the event is an eligible entry.
 */
function extrachill_analytics_conversion_is_editorial_entry( $event, $entry_blog_id ) {
	if ( (int) ( $event['blog_id'] ?? 0 ) !== (int) $entry_blog_id ) {
		return false;
	}

	$post = extrachill_analytics_get_blog_post( $entry_blog_id, (int) ( $event['post_id'] ?? 0 ) );
	return $post && 'post' === $post->post_type && 'publish' === $post->post_status;
}

/**
 * Whether an entry-session start is inside the report window and fully observed.
 *
 * @param array  $event         Session-start event.
 * @param int    $entry_blog_id Editorial blog ID.
 * @param string $since         Inclusive UTC report-window start.
 * @param string $mature_before Inclusive UTC maturity cutoff.
 * @return bool Whether the entry session is eligible for the denominator.
 */
function extrachill_analytics_conversion_is_mature_entry_session( $event, $entry_blog_id, $since, $mature_before ) {
	$timestamp = (int) ( $event['ts'] ?? 0 );
	return $timestamp >= strtotime( $since )
		&& $timestamp <= strtotime( $mature_before )
		&& extrachill_analytics_conversion_is_editorial_entry( $event, $entry_blog_id );
}

/**
 * Whether an event is a collected destination route within platform scope.
 *
 * @param array<int,mixed>  $event              Session event.
 * @param array<int,string> $platform_id_to_key Platform blog IDs keyed to surface names.
 * @return bool Whether the event is a measured platform destination.
 */
function extrachill_analytics_conversion_is_measured_platform_event( $event, $platform_id_to_key ) {
	return isset( $platform_id_to_key[ (int) ( $event['blog_id'] ?? 0 ) ] );
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
