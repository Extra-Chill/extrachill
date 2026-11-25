<?php
/**
 * Bounded, resumable OG card backfill for the current site.
 *
 * @package ExtraChillNetwork\OgCards
 */

namespace ExtraChillNetwork\OgCards;

/**
 * Queue missing/stale cards for upcoming published events.
 *
 * ## OPTIONS
 *
 * [--after=<id>]
 * : Resume after this post ID (default 0).
 *
 * [--limit=<count>]
 * : Maximum events inspected, 1–1000 (default 100).
 *
 * [--dry-run]
 * : Report candidates without queueing work.
 *
 * ## EXAMPLES
 *
 *     wp --url=https://events.extrachill.com extrachill og-cards backfill --dry-run
 *     wp --url=https://events.extrachill.com extrachill og-cards backfill --after=123 --limit=100
 */
\WP_CLI::add_command( 'extrachill og-cards backfill', function ( $args, $assoc_args ): void {
	global $wpdb;

	if ( ! function_exists( 'datamachine_get_event_dates' ) ) {
		\WP_CLI::error( 'Data Machine Events date API is required.' );
	}

	$after = max( 0, (int) ( $assoc_args['after'] ?? 0 ) );
	$limit = max( 1, min( 1000, (int) ( $assoc_args['limit'] ?? 100 ) ) );
	// Keyset pagination keeps memory and query cost bounded across 30k+ events.
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
		'data_machine_events',
		'publish',
		$after,
		$limit
	) );
	$candidates = 0;
	foreach ( $ids as $id ) {
		$after = (int) $id;
		$dates = datamachine_get_event_dates( $after );
		$end   = $dates ? ( $dates->end_datetime ?? $dates->start_datetime ?? '' ) : '';
		$end_time = $end ? date_create( $end, wp_timezone() ) : false;
		if ( ! $end_time || $end_time->getTimestamp() < time() || has_post_thumbnail( $after ) ) {
			continue;
		}
		$inspection = OgCardGenerationTask::inspect_for_post( get_post( $after ) );
		if ( null === $inspection['template_id'] || empty( $inspection['data'] ) || $inspection['is_current'] ) {
			continue;
		}
		++$candidates;
		if ( ! isset( $assoc_args['dry-run'] ) ) {
			queue_card_generation( $after );
		}
	}
	\WP_CLI::success( sprintf( 'Inspected %d; candidates %d; resume with --after=%d%s', count( $ids ), $candidates, $after, count( $ids ) < $limit ? ' (scan complete)' : '' ) );
} );
