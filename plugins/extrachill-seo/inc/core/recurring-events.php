<?php
/**
 * Rolling indexable window for recurring artist/venue shows.
 *
 * No series entity or persisted ranks: dates roll forward on every request.
 * Taxonomies have no primary marker; the lowest artist term ID is the stable
 * primary artist (and lowest venue ID resolves malformed multi-venue events).
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Blocks\Calendar\Query\UpcomingFilter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @return int Number of upcoming occurrences to keep indexable. */
function ec_seo_recurring_event_limit(): int {
	return max( 1, (int) apply_filters( 'extrachill_seo_recurring_event_limit', 4 ) );
}

/**
 * Build the shared set-based ranking. Missing dates/terms never enter a group.
 * Actual posts status is authoritative; the date table status can lag edits.
 *
 * @return string SQL selecting post_id and occurrence_rank.
 */
function ec_seo_recurring_event_ranks_sql(): string {
	global $wpdb;

	if ( ! class_exists( EventDatesTable::class ) || ! class_exists( UpcomingFilter::class ) ) {
		return '';
	}

	$dates    = EventDatesTable::table_name();
	$upcoming = UpcomingFilter::upcoming_where( current_time( 'mysql' ) );

	// Internal table names and the date API fragment only; no request SQL.
	return "SELECT ed.post_id,
		ROW_NUMBER() OVER (
			PARTITION BY artists.term_id, venues.term_id
			ORDER BY ed.start_datetime ASC, ed.post_id ASC
		) AS occurrence_rank
		FROM {$dates} ed
		INNER JOIN {$wpdb->posts} p ON p.ID = ed.post_id
		INNER JOIN (
			SELECT tr.object_id, MIN(tt.term_id) AS term_id
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE tt.taxonomy = 'artist' GROUP BY tr.object_id
		) artists ON artists.object_id = ed.post_id
		INNER JOIN (
			SELECT tr.object_id, MIN(tt.term_id) AS term_id
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE tt.taxonomy = 'venue' GROUP BY tr.object_id
		) venues ON venues.object_id = ed.post_id
		WHERE p.post_type = 'data_machine_events' AND p.post_status = 'publish'
		AND {$upcoming}";
}

/**
 * Rank an event inside its upcoming artist/venue group.
 *
 * @param array<int,array{post_id:int,start_datetime:string,end_datetime?:string|null}> $occurrences Group rows.
 * @param int    $post_id Event ID.
 * @param string $now     MySQL datetime.
 * @return array{rank:int,total:int}|null Null when the event is not upcoming.
 */
function ec_seo_recurring_event_rank( array $occurrences, int $post_id, string $now ): ?array {
	$upcoming = array_values(
		array_filter(
			$occurrences,
			static fn( $row ) => $row['start_datetime'] >= $now || ( ! empty( $row['end_datetime'] ) && $row['end_datetime'] >= $now )
		)
	);
	usort( $upcoming, static fn( $a, $b ) => array( $a['start_datetime'], (int) $a['post_id'] ) <=> array( $b['start_datetime'], (int) $b['post_id'] ) );

	foreach ( $upcoming as $index => $row ) {
		if ( (int) $row['post_id'] === $post_id ) {
			return array(
				'rank'  => $index + 1,
				'total' => count( $upcoming ),
			);
		}
	}
	return null;
}

/**
 * Lowest-ID term of a taxonomy, matching the sitemap SQL's MIN(term_id).
 *
 * @param int    $post_id  Event ID.
 * @param string $taxonomy Taxonomy.
 * @return \WP_Term|null
 */
function ec_seo_recurring_event_term( int $post_id, string $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return null;
	}
	usort( $terms, static fn( $a, $b ) => (int) $a->term_id <=> (int) $b->term_id );
	return $terms[0];
}

/**
 * Resolve this event's current group, used by robots and the template layer.
 *
 * @param int $post_id Event ID.
 * @return array{rank:int,total:int,artist:\WP_Term,venue:\WP_Term}|null
 */
function ec_seo_recurring_event_group( int $post_id ): ?array {
	global $wpdb;
	static $cache = array();

	if ( array_key_exists( $post_id, $cache ) ) {
		return $cache[ $post_id ];
	}
	$cache[ $post_id ] = null;
	$artist            = ec_seo_recurring_event_term( $post_id, 'artist' );
	$venue             = ec_seo_recurring_event_term( $post_id, 'venue' );
	if ( ! $artist || ! $venue || ! class_exists( EventDatesTable::class ) || ! class_exists( UpcomingFilter::class ) ) {
		return null;
	}

	$now      = current_time( 'mysql' );
	$dates    = EventDatesTable::table_name();
	$upcoming = UpcomingFilter::upcoming_where( $now );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table names and prepared date API fragment.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ed.post_id, ed.start_datetime, ed.end_datetime FROM {$dates} ed
			INNER JOIN {$wpdb->posts} p ON p.ID = ed.post_id AND p.post_type = 'data_machine_events' AND p.post_status = 'publish'
			INNER JOIN {$wpdb->term_relationships} ar ON ar.object_id = ed.post_id
			INNER JOIN {$wpdb->term_taxonomy} att ON att.term_taxonomy_id = ar.term_taxonomy_id AND att.taxonomy = 'artist' AND att.term_id = %d
			INNER JOIN {$wpdb->term_relationships} vr ON vr.object_id = ed.post_id
			INNER JOIN {$wpdb->term_taxonomy} vtt ON vtt.term_taxonomy_id = vr.term_taxonomy_id AND vtt.taxonomy = 'venue' AND vtt.term_id = %d
			WHERE {$upcoming}
			AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} ar2 INNER JOIN {$wpdb->term_taxonomy} att2 ON att2.term_taxonomy_id = ar2.term_taxonomy_id WHERE ar2.object_id = ed.post_id AND att2.taxonomy = 'artist' AND att2.term_id < %d)
			AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} vr2 INNER JOIN {$wpdb->term_taxonomy} vtt2 ON vtt2.term_taxonomy_id = vr2.term_taxonomy_id WHERE vr2.object_id = ed.post_id AND vtt2.taxonomy = 'venue' AND vtt2.term_id < %d)",
			$artist->term_id,
			$venue->term_id,
			$artist->term_id,
			$venue->term_id
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$rank = ec_seo_recurring_event_rank( is_array( $rows ) ? $rows : array(), $post_id, $now );
	if ( $rank ) {
		$cache[ $post_id ] = $rank + array(
			'artist' => $artist,
			'venue'  => $venue,
		);
	}
	return $cache[ $post_id ];
}

/**
 * @param array|null $group Current rank/count.
 * @return bool Whether this occurrence lies beyond the rolling window.
 */
function ec_seo_recurring_event_noindex( ?array $group ): bool {
	return null !== $group && (int) $group['rank'] > ec_seo_recurring_event_limit();
}

/** @param array $robots Existing robots directives. @return array */
function ec_seo_recurring_event_robots( $robots ) {
	if ( is_singular( 'data_machine_events' ) && ec_seo_recurring_event_noindex( ec_seo_recurring_event_group( (int) get_queried_object_id() ) ) ) {
		unset( $robots['index'], $robots['nofollow'] );
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}
	return $robots;
}
add_filter( 'wp_robots', __NAMESPACE__ . '\\ec_seo_recurring_event_robots', 20 );

/** @param array $args Query args shared by sitemap URLs and page counts. @param string $post_type Post type. @return array */
function ec_seo_recurring_event_sitemap_args( $args, $post_type ) {
	if ( 'data_machine_events' === $post_type ) {
		$args['ec_seo_recurring_window'] = true;
		$args['suppress_filters']        = false;
		if ( ! has_filter( 'posts_where', __NAMESPACE__ . '\\ec_seo_recurring_event_sitemap_where' ) ) {
			add_filter( 'posts_where', __NAMESPACE__ . '\\ec_seo_recurring_event_sitemap_where', 10, 2 );
		}
	}
	return $args;
}
add_filter( 'wp_sitemaps_posts_query_args', __NAMESPACE__ . '\\ec_seo_recurring_event_sitemap_args', 10, 2 );

/**
 * Set-based exclusion for every sitemap page and the page count.
 *
 * @param string    $where Existing WHERE clause.
 * @param \WP_Query $query Query.
 * @return string
 */
function ec_seo_recurring_event_sitemap_where( $where, $query ) {
	global $wpdb;
	if ( ! $query->get( 'ec_seo_recurring_window' ) || 'data_machine_events' !== $query->get( 'post_type' ) ) {
		return $where;
	}
	$sql = ec_seo_recurring_event_ranks_sql();
	if ( '' !== $sql ) {
		$limit  = ec_seo_recurring_event_limit();
		$where .= " AND {$wpdb->posts}.ID NOT IN (SELECT post_id FROM ({$sql}) ec_recurring WHERE occurrence_rank > {$limit})";
	}
	return $where;
}
