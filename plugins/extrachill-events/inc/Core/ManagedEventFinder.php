<?php
/**
 * Paged lookup of published events inside a managed venue/promoter scope.
 *
 * Reads Data Machine Events' denormalized dates table (one row per event)
 * joined to the canonical posts table for status, so "upcoming" and "past"
 * use the same definitions as the calendar (UpcomingFilter): an event is
 * upcoming while it has not ended, past once it has.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Finds event IDs for ManagedEventScope criteria. */
class ManagedEventFinder {

	/**
	 * Find one page of event IDs.
	 *
	 * @param array $criteria {
	 *     @type bool   $unrestricted     Ignore team scope (all events).
	 *     @type int[]  $venue_ids        Managed venue term IDs.
	 *     @type int[]  $promoter_ids     Managed promoter term IDs.
	 *     @type string $when             upcoming, past, or any.
	 *     @type string $date_from        Inclusive Y-m-d lower bound on start, or ''.
	 *     @type string $date_to          Inclusive Y-m-d upper bound on start, or ''.
	 *     @type int    $venue_term_id    Narrow to one venue, or 0.
	 *     @type int    $promoter_term_id Narrow to one promoter, or 0.
	 *     @type string $now              Site-local Y-m-d H:i:s reference time.
	 *     @type int    $limit            Page size.
	 *     @type int    $offset           Rows to skip.
	 * }
	 * @return array{ids:int[],total:int}|\WP_Error
	 */
	public function find( array $criteria ) {
		global $wpdb;

		$venue_ids    = array_map( 'intval', (array) ( $criteria['venue_ids'] ?? array() ) );
		$promoter_ids = array_map( 'intval', (array) ( $criteria['promoter_ids'] ?? array() ) );
		$unrestricted = ! empty( $criteria['unrestricted'] );
		if ( ! $unrestricted && ! $venue_ids && ! $promoter_ids ) {
			return array(
				'ids'   => array(),
				'total' => 0,
			);
		}

		if ( ! class_exists( '\\DataMachineEvents\\Core\\EventDatesTable' ) ) {
			return new \WP_Error( 'managed_events_unavailable', __( 'Event dates are not available right now.', 'extrachill-events' ), array( 'status' => 503 ) );
		}

		$dates  = \DataMachineEvents\Core\EventDatesTable::table_name();
		$where  = array( 'p.post_type = %s', "p.post_status = 'publish'" );
		$params = array( ManagedEventScope::POST_TYPE );

		if ( ! $unrestricted ) {
			$scope = array();
			foreach ( array(
				'venue'    => $venue_ids,
				'promoter' => $promoter_ids,
			) as $taxonomy => $ids ) {
				if ( ! $ids ) {
					continue;
				}
				$scope[] = $this->term_exists_sql( count( $ids ) );
				$params  = array_merge( $params, array( $taxonomy ), $ids );
			}
			$where[] = '( ' . implode( ' OR ', $scope ) . ' )';
		}

		foreach ( array(
			'venue'    => (int) ( $criteria['venue_term_id'] ?? 0 ),
			'promoter' => (int) ( $criteria['promoter_term_id'] ?? 0 ),
		) as $taxonomy => $term_id ) {
			if ( $term_id > 0 ) {
				$where[]  = $this->term_exists_sql( 1 );
				$params[] = $taxonomy;
				$params[] = $term_id;
			}
		}

		$now  = (string) ( $criteria['now'] ?? '' );
		$when = (string) ( $criteria['when'] ?? 'upcoming' );
		if ( 'upcoming' === $when ) {
			$where[] = '( ed.start_datetime >= %s OR ed.end_datetime >= %s )';
			array_push( $params, $now, $now );
		} elseif ( 'past' === $when ) {
			$where[] = '( ed.start_datetime < %s AND ( ed.end_datetime < %s OR ed.end_datetime IS NULL ) )';
			array_push( $params, $now, $now );
		}

		if ( '' !== (string) ( $criteria['date_from'] ?? '' ) ) {
			$where[]  = 'ed.start_datetime >= %s';
			$params[] = $criteria['date_from'] . ' 00:00:00';
		}
		if ( '' !== (string) ( $criteria['date_to'] ?? '' ) ) {
			$where[]  = 'ed.start_datetime <= %s';
			$params[] = $criteria['date_to'] . ' 23:59:59';
		}

		$from  = "FROM {$wpdb->posts} p INNER JOIN {$dates} ed ON ed.post_id = p.ID WHERE " . implode( ' AND ', $where );
		$order = 'past' === $when ? 'DESC' : 'ASC';
		$limit = max( 1, (int) ( $criteria['limit'] ?? 20 ) );
		$skip  = max( 0, (int) ( $criteria['offset'] ?? 0 ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Clauses and placeholders are assembled above; table names are internal; every value is a placeholder; live management data.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", $params ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->read_error();
		}
		$ids = $total > $skip
			? $wpdb->get_col( $wpdb->prepare( "SELECT p.ID {$from} ORDER BY ed.start_datetime {$order}, p.ID ASC LIMIT %d OFFSET %d", array_merge( $params, array( $limit, $skip ) ) ) )
			: array();
		// phpcs:enable
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->read_error();
		}

		return array(
			'ids'   => array_map( 'intval', (array) $ids ),
			'total' => $total,
		);
	}

	/**
	 * EXISTS clause matching an event assigned one of N terms of a taxonomy.
	 *
	 * Placeholders: taxonomy, then N term IDs.
	 *
	 * @param int $count Number of term ID placeholders.
	 * @return string
	 */
	private function term_exists_sql( int $count ): string {
		global $wpdb;

		return "EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = p.ID AND tt.taxonomy = %s AND tt.term_id IN ( " . implode( ', ', array_fill( 0, $count, '%d' ) ) . ' ) )';
	}

	/** Uniform read failure. */
	private function read_error(): \WP_Error {
		return new \WP_Error( 'managed_events_read_failed', __( 'Your events could not be read.', 'extrachill-events' ), array( 'status' => 500 ) );
	}
}
