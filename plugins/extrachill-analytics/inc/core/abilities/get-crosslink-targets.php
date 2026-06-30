<?php
/**
 * Get Crosslink Targets Ability
 *
 * THE crosslink ops-pass targeting instrument. It does not measure anything new
 * — it JOINS two instruments that already exist and answers the only question a
 * crosslink pass actually needs: *which blog-1 editorial articles are
 * simultaneously (a) proven journey entry points that visitors return through,
 * and (b) orphaned or thinly-linked in the internal link graph, so that adding
 * an internal link to a forward platform surface would do the most good?*
 *
 * The two instruments it joins (and deliberately does NOT reimplement):
 *
 *   1. `extrachill/get-conversion-map` (this plugin) — already ranks per-entry
 *      ARTICLE journeys: top blog-1 editorial pages by entry sessions, with
 *      same-session reach, return reach, returned %, and the WordPress category
 *      axis (song-meaning / music-history / etc.). The keystone finding it
 *      surfaces is that ~0% of article journeys reach any platform surface
 *      despite a meaningful return rate. We CALL that ability and reuse its
 *      per-article ranking verbatim — no re-querying the pageview funnel.
 *
 *   2. The Data Machine internal-linking link graph
 *      (`DataMachine\Abilities\InternalLinkingAbilities`) — already scans post
 *      content, builds an inbound/outbound link graph, and exposes orphans
 *      (zero inbound) and per-post inbound counts. ~85% of the catalog is
 *      orphaned. We CONSULT that graph read-only via a single
 *      `auditInternalLinks()` call and derive a `post_id => inbound_count`
 *      lookup from it. We do NOT duplicate, re-scan, or reimplement any
 *      link-graph logic — if the Data Machine link primitive is unavailable we
 *      degrade and say so in the note, we never roll our own.
 *
 * ORPHAN DEFINITION (read before comparing counts): the orphan figure this
 * ability surfaces (`link_graph.inbound_orphan_count`) is the link graph's
 * ZERO-INBOUND count — posts that nothing links TO. This is deliberately NOT
 * the same metric as `wp datamachine links diagnose`, whose `posts_without_links`
 * counts ZERO-OUTBOUND posts — posts that link to nothing. The two measure
 * OPPOSITE edges of the link graph; both are valid, but they are not comparable
 * counts and a difference between them is not "movement". This ability is the
 * canonical home for the zero-inbound number; `links diagnose` is canonical for
 * the zero-outbound number. The labels here state which is which so a reader
 * never conflates them.
 *
 * LAYER PURITY: this ability lives in extrachill-analytics and is allowed to
 * CONSULT the Data Machine link-graph class as a cross-plugin READ (a guarded,
 * static, side-effect-free call). It owns the JOIN and the targeting heuristic;
 * it owns none of the link-graph math. The funnel math stays in
 * get-conversion-map; the graph math stays in InternalLinkingAbilities.
 *
 * OUTPUT is a ranked, dry-run targeting list. Each row is a blog-1 article that
 * scored high on returning-journey volume AND is orphaned / low-inbound, tagged
 * with its category (so residual song-meaning / music-history SEO equity is
 * visible) and a suggested forward surface (events / community) to route the new
 * crosslink toward. This is precisely the list the crosslink hook
 * (data-machine#2727 + multisite#64) consumes — it does not itself insert any
 * link; it is the targeting pass, not the write pass.
 *
 * HONEST BY CONSTRUCTION: an empty or tiny list IS a finding (either the funnel
 * window holds no returning article journeys yet, or the catalog is already
 * well-linked). The note always carries the window, the join inputs, and any
 * degraded-input caveat so a short list is read as signal, never as a bug.
 *
 * @package ExtraChill\Analytics
 * @since 0.14.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-crosslink-targets ability.
 */
function extrachill_analytics_register_crosslink_targets_ability() {
	wp_register_ability(
		'extrachill/get-crosslink-targets',
		array(
			'label'               => __( 'Get Crosslink Targets', 'extrachill-analytics' ),
			'description'         => __( 'Ranked, dry-run crosslink ops-pass targeting list. JOINs the get-conversion-map per-article journey ranking with the Data Machine internal link graph: blog-1 articles that are simultaneously high on returning-journey volume AND orphaned / low-inbound, tagged with category and a suggested forward surface (events/community) to route a new internal link toward. Consults — never duplicates — the link-graph primitive.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'             => array(
						'type'        => 'integer',
						'description' => __( 'Look-back window (days) for the conversion-map funnel. Default 28.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'session_gap_mins' => array(
						'type'        => 'integer',
						'description' => __( 'Inactivity gap (minutes) that ends a session, passed through to get-conversion-map. Default 30.', 'extrachill-analytics' ),
						'default'     => 30,
					),
					'scan_articles'    => array(
						'type'        => 'integer',
						'description' => __( 'How many top entry articles to pull from get-conversion-map before the link-graph join. Default 100.', 'extrachill-analytics' ),
						'default'     => 100,
					),
					'limit'            => array(
						'type'        => 'integer',
						'description' => __( 'Maximum number of ranked crosslink targets to return after the join. Default 25.', 'extrachill-analytics' ),
						'default'     => 25,
					),
					'max_inbound'      => array(
						'type'        => 'integer',
						'description' => __( 'Inbound-link ceiling for a page to count as a crosslink target. 0 = orphans only; 2 = orphans plus thinly-linked pages. Default 2.', 'extrachill-analytics' ),
						'default'     => 2,
					),
					'min_returned'     => array(
						'type'        => 'integer',
						'description' => __( 'Minimum returned (2nd-session) journeys for an article to qualify. Default 1.', 'extrachill-analytics' ),
						'default'     => 1,
					),
					'force_audit'      => array(
						'type'        => 'boolean',
						'description' => __( 'Force a fresh link-graph audit instead of using the cached graph. Default false.', 'extrachill-analytics' ),
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Object with the ranked crosslink targets, the join inputs (conversion + link-graph), and the exact window. The link_graph block reports inbound_orphan_count (zero-INBOUND orphans, orphan_definition=zero_inbound) — NOT comparable to the zero-OUTBOUND posts_without_links count from `wp datamachine links diagnose`.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_crosslink_targets',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
			},
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
 * Execute callback for get-crosslink-targets ability.
 *
 * Strategy (two reads, joined in PHP):
 *   1. Reuse get-conversion-map for the per-article journey ranking. We do NOT
 *      recompute the funnel — we call the registered ability and take its
 *      `by_article` rows (which already carry post_id, title, slug, category-
 *      bearing data via the entry article, return rate, and returned counts).
 *   2. Consult the Data Machine link graph ONCE via auditInternalLinks() and
 *      derive a post_id => inbound_count lookup from its returned graph. This is
 *      a single cross-plugin static READ; if the class/method is unavailable we
 *      degrade (every page is treated as unknown-inbound and the note says so)
 *      rather than reimplementing the graph.
 *
 * The join keeps only articles that returning visitors actually re-enter
 * (min_returned) AND that are orphaned / low-inbound (<= max_inbound), ranks
 * them by a crosslink-opportunity score, and tags each with its category and a
 * suggested forward surface.
 *
 * @param array $input Input parameters.
 * @return array Ranked crosslink targeting list.
 */
function extrachill_analytics_ability_get_crosslink_targets( $input ) {
	$days             = isset( $input['days'] ) ? max( 1, (int) $input['days'] ) : 28;
	$session_gap_mins = isset( $input['session_gap_mins'] ) ? max( 1, (int) $input['session_gap_mins'] ) : 30;
	$scan_articles    = isset( $input['scan_articles'] ) ? max( 1, (int) $input['scan_articles'] ) : 100;
	$limit            = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 25;
	$max_inbound      = isset( $input['max_inbound'] ) ? max( 0, (int) $input['max_inbound'] ) : 2;
	$min_returned     = isset( $input['min_returned'] ) ? max( 0, (int) $input['min_returned'] ) : 1;
	$force_audit      = ! empty( $input['force_audit'] );

	// ── Read 1: the conversion-map per-article journey ranking (reused, not
	// recomputed). We pull a generous top-N so the link-graph join has room to
	// filter down to the genuine orphans.
	$conversion = extrachill_analytics_crosslink_conversion_read( $days, $session_gap_mins, $scan_articles );
	if ( is_wp_error( $conversion ) ) {
		return array(
			'targets'         => array(),
			'scanned'         => 0,
			'days'            => $days,
			'note'            => 'Could not read get-conversion-map (' . $conversion->get_error_message() . '). The crosslink-targets join requires the conversion instrument; nothing to rank.',
			'conversion_note' => '',
		);
	}

	$by_article    = (array) ( $conversion['by_article'] ?? array() );
	$entry_blog_id = (int) ( $conversion['entry_blog_id'] ?? 1 );

	// ── Read 2: consult the Data Machine link graph ONCE (cross-plugin READ).
	$graph = extrachill_analytics_crosslink_link_graph( $force_audit );

	$inbound_lookup   = $graph['inbound'];
	$orphan_set       = $graph['orphans'];
	$graph_available  = $graph['available'];
	$graph_total      = $graph['total_scanned'];
	$graph_orphan_cnt = $graph['orphan_count'];
	$graph_note       = $graph['note'];

	// ── The JOIN: keep articles that returning visitors re-enter AND that the
	// link graph says are orphaned / low-inbound.
	$targets = array();
	foreach ( $by_article as $article ) {
		$post_id  = (int) ( $article['post_id'] ?? 0 );
		$returned = (int) ( $article['returned_count'] ?? 0 );
		if ( $post_id <= 0 || $returned < $min_returned ) {
			continue;
		}

		// Inbound status from the consulted graph. When the graph is
		// unavailable we cannot establish orphan status, so we cannot assert a
		// page is a crosslink target — skip rather than guess.
		$is_orphan = isset( $orphan_set[ $post_id ] );
		$inbound   = $is_orphan ? 0 : ( $inbound_lookup[ $post_id ] ?? null );

		if ( ! $graph_available ) {
			continue;
		}

		// null inbound = the page wasn't in the graph's scanned scope (e.g.
		// non-post or out-of-window). We can't confirm it's low-inbound, skip.
		if ( null === $inbound ) {
			continue;
		}

		if ( $inbound > $max_inbound ) {
			continue;
		}

		$category    = extrachill_analytics_crosslink_primary_category( $article );
		$same_events = (float) ( $article['same_session']['events'] ?? 0 );
		$same_comm   = (float) ( $article['same_session']['community'] ?? 0 );
		$ret_events  = (float) ( $article['return']['events'] ?? 0 );
		$ret_comm    = (float) ( $article['return']['community'] ?? 0 );

		$surface = extrachill_analytics_crosslink_suggest_surface(
			$category,
			$same_events + $ret_events,
			$same_comm + $ret_comm
		);

		// Crosslink-opportunity score: returning-journey volume weighted up for
		// orphans (an orphan with returning traffic is the highest-value target,
		// exactly mirroring the link-graph opportunities scoring shape of
		// volume * 1/(inbound+1)).
		$score = round( $returned * ( 1 / ( $inbound + 1 ) ), 2 );

		$targets[] = array(
			'post_id'           => $post_id,
			'title'             => (string) ( $article['title'] ?? '(unknown)' ),
			'slug'              => (string) ( $article['slug'] ?? '' ),
			'category'          => $category,
			'entry_sessions'    => (int) ( $article['entry_sessions'] ?? 0 ),
			'returned'          => $returned,
			'returned_rate'     => (float) ( $article['returned_rate'] ?? 0 ),
			'reached_any'       => (int) ( $article['reached_any'] ?? 0 ),
			'inbound_links'     => (int) $inbound,
			'orphan'            => $is_orphan,
			'suggested_surface' => $surface,
			'score'             => $score,
		);
	}

	// Rank by crosslink-opportunity score, then returning volume as tiebreak.
	usort(
		$targets,
		static function ( $a, $b ) {
			if ( $a['score'] === $b['score'] ) {
				return $b['returned'] <=> $a['returned'];
			}
			return $b['score'] <=> $a['score'];
		}
	);

	$scanned = count( $by_article );
	$targets = array_slice( $targets, 0, $limit );

	$note = sprintf(
		'Crosslink ops-pass targeting list — the JOIN of two existing instruments, not a new measurement. Source A: extrachill/get-conversion-map per-article journey ranking (%d articles scanned, %d-day window). Source B: Data Machine internal link graph (consulted read-only via InternalLinkingAbilities::auditInternalLinks — %s). A target is a blog-1 article that returning visitors re-enter (returned >= %d) AND that the link graph reports orphaned or low-inbound (inbound <= %d). score = returned * 1/(inbound+1): an orphan with returning traffic ranks highest. suggested_surface routes the new internal link toward the forward platform surface (events/community) the article is closest to / weakest on. ORPHAN DEFINITION: "orphan" here means zero INBOUND links (nothing links TO the post), per the link-graph primitive. This is NOT the same metric as `wp datamachine links diagnose`, whose "posts_without_links" counts zero-OUTBOUND posts (the post links to nothing). The two count opposite edges of the link graph and are not comparable — a difference between them is not movement. This is a DRY-RUN list — it inserts no links; it is the targeting pass the crosslink hook consumes. An empty or short list is itself the finding (no returning article journeys in window, or the catalog is already well-linked) — not a bug.',
		$scanned,
		$days,
		$graph_note,
		$min_returned,
		$max_inbound
	);

	return array(
		'targets'          => $targets,
		'scanned'          => $scanned,
		'returned_targets' => count( $targets ),
		'entry_blog_id'    => $entry_blog_id,
		'link_graph'       => array(
			'available'             => $graph_available,
			'total_scanned'         => $graph_total,
			// Explicit, self-documenting orphan label: this is the link graph's
			// zero-INBOUND count (a post nothing links TO). It is NOT the same as
			// the zero-OUTBOUND "posts_without_links" figure reported by
			// `wp datamachine links diagnose` (a post that links to nothing). The
			// two measure opposite edges of the link graph and are not comparable.
			'inbound_orphan_count'  => $graph_orphan_cnt,
			'orphan_definition'     => 'zero_inbound',
			// Deprecated alias kept for back-compat with existing readers. Prefer
			// inbound_orphan_count — the bare name conflates with links diagnose's
			// zero-outbound count. @deprecated since 0.14.1
			'orphan_count'          => $graph_orphan_cnt,
		),
		'days'             => $days,
		'session_gap_mins' => $session_gap_mins,
		'max_inbound'      => $max_inbound,
		'min_returned'     => $min_returned,
		'period'           => $conversion['period'] ?? '',
		'as_of'            => $conversion['as_of'] ?? gmdate( 'Y-m-d H:i:s' ),
		'note'             => $note,
		'conversion_note'  => (string) ( $conversion['note'] ?? '' ),
	);
}

/**
 * Read the conversion-map per-article ranking by invoking the registered
 * get-conversion-map ability (preferred) or its execute callback directly.
 *
 * We reuse the existing instrument rather than recomputing the funnel. Calling
 * the ability keeps the permission/registration contract; falling back to the
 * callback keeps the join working in contexts where the ability registry hasn't
 * been populated (e.g. some early-boot CLI paths) without duplicating any
 * funnel logic.
 *
 * @param int $days             Look-back window in days.
 * @param int $session_gap_mins Session inactivity gap in minutes.
 * @param int $scan_articles    Number of top entry articles to pull.
 * @return array|WP_Error Conversion-map result, or WP_Error on failure.
 */
function extrachill_analytics_crosslink_conversion_read( $days, $session_gap_mins, $scan_articles ) {
	$args = array(
		'days'               => $days,
		'session_gap_mins'   => $session_gap_mins,
		'top_articles'       => $scan_articles,
		'min_entry_sessions' => 1,
	);

	if ( function_exists( 'wp_get_ability' ) ) {
		$ability = wp_get_ability( 'extrachill/get-conversion-map' );
		if ( $ability ) {
			$result = $ability->execute( $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( is_array( $result ) ) {
				return $result;
			}
		}
	}

	if ( function_exists( 'extrachill_analytics_ability_get_conversion_map' ) ) {
		return extrachill_analytics_ability_get_conversion_map( $args );
	}

	return new WP_Error(
		'conversion_map_unavailable',
		'extrachill/get-conversion-map ability is not available.'
	);
}

/**
 * Consult the Data Machine internal link graph read-only and derive a
 * post_id => inbound_count lookup plus the orphan set.
 *
 * This is the ONLY place the analytics plugin touches the link graph. It is a
 * guarded, static, side-effect-free cross-plugin READ. We never reimplement or
 * re-scan the graph: if the Data Machine class/method is unavailable we return
 * an "unavailable" shape and the caller degrades honestly.
 *
 * The inbound lookup is derived from the graph's `_all_links` edge list (the
 * same data the link primitive itself aggregates from) so we get an exact
 * per-post inbound count for every scanned post in a single call — no N+1
 * backlink lookups.
 *
 * @param bool $force_audit Force a fresh audit instead of the cached graph.
 * @return array{available:bool,inbound:array<int,int>,orphans:array<int,bool>,total_scanned:int,orphan_count:int,note:string}
 */
function extrachill_analytics_crosslink_link_graph( $force_audit ) {
	$unavailable = array(
		'available'     => false,
		'inbound'       => array(),
		'orphans'       => array(),
		'total_scanned' => 0,
		'orphan_count'  => 0,
		'note'          => 'link graph UNAVAILABLE — DataMachine\\Abilities\\InternalLinkingAbilities not loaded; orphan status could not be established, so no targets could be confirmed',
	);

	if ( ! class_exists( '\\DataMachine\\Abilities\\InternalLinkingAbilities' ) ) {
		return $unavailable;
	}

	$graph = \DataMachine\Abilities\InternalLinkingAbilities::auditInternalLinks(
		array(
			'post_type' => 'post',
			'force'     => $force_audit,
		)
	);

	if ( isset( $graph['error'] ) ) {
		$unavailable['note'] = 'link graph audit FAILED ('
			. (string) $graph['error']
			. ') — orphan status could not be established';
		return $unavailable;
	}

	$post_ids  = array_map( 'intval', (array) ( $graph['_post_ids'] ?? array() ) );
	$all_links = (array) ( $graph['_all_links'] ?? array() );

	// Derive exact inbound counts from the edge list — same source the graph
	// primitive aggregates from. Self-links are ignored, matching the primitive.
	$inbound = array();
	foreach ( $post_ids as $pid ) {
		$inbound[ $pid ] = 0;
	}
	foreach ( $all_links as $edge ) {
		$source_id = (int) ( $edge['source_id'] ?? 0 );
		$target_id = isset( $edge['target_id'] ) ? (int) $edge['target_id'] : 0;
		if ( $target_id <= 0 || $target_id === $source_id ) {
			continue;
		}
		if ( isset( $inbound[ $target_id ] ) ) {
			++$inbound[ $target_id ];
		}
	}

	// Orphan set from the primitive's own orphan list (authoritative — it is
	// computed the same way but is the contract surface).
	$orphans = array();
	foreach ( (array) ( $graph['orphaned_posts'] ?? array() ) as $orphan ) {
		$pid = (int) ( $orphan['post_id'] ?? 0 );
		if ( $pid > 0 ) {
			$orphans[ $pid ] = true;
		}
	}

	$total_scanned = (int) ( $graph['total_scanned'] ?? count( $post_ids ) );
	$orphan_count  = (int) ( $graph['orphaned_count'] ?? count( $orphans ) );
	$cached        = ! empty( $graph['cached'] );

	return array(
		'available'     => true,
		'inbound'       => $inbound,
		'orphans'       => $orphans,
		'total_scanned' => $total_scanned,
		'orphan_count'  => $orphan_count,
		'note'          => sprintf(
			'%d posts scanned, %d zero-inbound orphans (%s graph). NB: "orphan" here = zero INBOUND links; this differs from `wp datamachine links diagnose`, which reports zero-OUTBOUND posts — the two are not comparable counts.',
			$total_scanned,
			$orphan_count,
			$cached ? 'cached' : 'fresh'
		),
	);
}

/**
 * Resolve a single representative category label for an article result row.
 *
 * The conversion map attributes an entry session to every category of the entry
 * article but the per-article row itself does not carry the category list, so we
 * resolve the article's primary category here (reusing the same `category`
 * taxonomy axis the conversion map keys on). Falls back to an empty string.
 *
 * @param array $article Article result row (must contain post_id).
 * @return string Category name, or '' if none.
 */
function extrachill_analytics_crosslink_primary_category( $article ) {
	$post_id = (int) ( $article['post_id'] ?? 0 );
	if ( $post_id <= 0 ) {
		return '';
	}

	// Reuse the conversion-map helper that already resolves an article's
	// categories on the entry blog with correct blog-switching.
	if ( function_exists( 'extrachill_analytics_conversion_post_categories' ) ) {
		$cats = extrachill_analytics_conversion_post_categories( $post_id );
		if ( ! empty( $cats ) ) {
			// First category name (the map is term_id => name).
			$names = array_values( $cats );
			return (string) $names[0];
		}
	}

	return '';
}

/**
 * Suggest the forward platform surface to route a crosslink toward.
 *
 * Heuristic, deliberately simple and documented: route toward the surface the
 * article is WEAKEST on (lowest current reach), because that is where a new
 * crosslink has the most headroom to convert returning visitors. Category is a
 * light tiebreaker — show/festival/event-flavored categories lean events;
 * everything else leans community (the durable-stickiness surface). The
 * crosslink hook is free to override; this is a suggestion, not a mandate.
 *
 * @param string $category      Article primary category name.
 * @param float  $events_reach  Combined same+return events reach rate (0..1).
 * @param float  $community_reach Combined same+return community reach rate (0..1).
 * @return string 'events' or 'community'.
 */
function extrachill_analytics_crosslink_suggest_surface( $category, $events_reach, $community_reach ) {
	// Lowest current reach = most headroom. If reach is tied (commonly both 0
	// in the siloed-front-door state), fall back to the category lean.
	if ( $events_reach < $community_reach ) {
		return 'events';
	}
	if ( $community_reach < $events_reach ) {
		return 'community';
	}

	$cat             = strtolower( (string) $category );
	$events_flavored = array( 'show', 'shows', 'festival', 'festivals', 'event', 'events', 'concert', 'concerts', 'live', 'tour', 'tours' );
	foreach ( $events_flavored as $needle ) {
		if ( '' !== $cat && false !== strpos( $cat, $needle ) ) {
			return 'events';
		}
	}

	return 'community';
}
