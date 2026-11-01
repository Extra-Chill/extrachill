<?php
/**
 * Private booking attachment delivery correlation repository.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores authorization and transport state without handoffs or byte references. */
class BookingAttachmentDeliveryRepository {

	public const OUTCOMES = array( 'completed', 'failed', 'interrupted', 'partial' );

	/**
	 * Persist one Events-owned correlation before a handoff is issued.
	 *
	 * @param string $correlation_id Opaque delivery correlation.
	 * @param int    $booking_id     Booking ID.
	 * @param int    $attachment_id  Attachment ID.
	 * @param int    $actor_id       Authorized actor ID.
	 * @param int    $expected_bytes Authorized full response length.
	 */
	public function issue( string $correlation_id, int $booking_id, int $attachment_id, int $actor_id, int $expected_bytes ) {
		global $wpdb;
		if ( ! wp_is_uuid( $correlation_id, 4 ) || $booking_id < 1 || $attachment_id < 1 || $actor_id < 1 || $expected_bytes < 1 ) {
			return new \WP_Error( 'booking_attachment_delivery_invalid', __( 'The attachment delivery correlation is invalid.', 'extrachill-events' ) );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			'correlation_id' => $correlation_id,
			'booking_id'     => $booking_id,
			'attachment_id'  => $attachment_id,
			'actor_id'       => $actor_id,
			'expected_bytes' => $expected_bytes,
			'state'          => 'issued',
			'outcome'        => null,
			'bytes_sent'     => null,
			'issued_at'      => $now,
			'consumed_at'    => null,
			'terminal_at'    => null,
			'updated_at'     => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private operational ledger write.
		if ( false === $wpdb->insert( BookingSchema::attachment_deliveries_table(), $row ) ) {
			return new \WP_Error( 'booking_attachment_delivery_issue_failed', __( 'The attachment delivery correlation could not be saved.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return $this->get( $correlation_id );
	}

	/**
	 * Read one exact correlation, optionally locking it.
	 *
	 * @param string $correlation_id Opaque delivery correlation.
	 * @param bool   $for_update     Whether to lock the row.
	 */
	public function get( string $correlation_id, bool $for_update = false ) {
		global $wpdb;
		$table = BookingSchema::attachment_deliveries_table();
		$lock  = $for_update ? ' FOR UPDATE' : '';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE correlation_id = %s LIMIT 1{$lock}", $correlation_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_attachment_delivery_read_failed', __( 'The attachment delivery correlation could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Mark the exact issued correlation consumed once.
	 *
	 * @param array $delivery Stored delivery row.
	 */
	public function consume( array $delivery ) {
		global $wpdb;
		if ( 'issued' !== $delivery['state'] ) {
			return new \WP_Error( 'booking_attachment_delivery_replayed', __( 'The attachment delivery correlation was already consumed.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact compare-and-swap consumption boundary.
		$updated = $wpdb->update(
			BookingSchema::attachment_deliveries_table(),
			array(
				'state'       => 'consumed',
				'consumed_at' => $now,
				'updated_at'  => $now,
			),
			array(
				'correlation_id' => $delivery['correlation_id'],
				'state'          => 'issued',
			)
		);
		return 1 === $updated
			? $this->get( $delivery['correlation_id'] )
			: new \WP_Error(
				'booking_attachment_delivery_replayed',
				__( 'The attachment delivery correlation was already consumed.', 'extrachill-events' ),
				array(
					'status'         => 409,
					'database_error' => $wpdb->last_error,
				)
			);
	}

	/**
	 * Apply one immutable terminal result, returning exact duplicates idempotently.
	 *
	 * @param array  $delivery   Stored delivery row.
	 * @param string $outcome    Terminal outcome.
	 * @param int    $bytes_sent Emitted byte count.
	 */
	public function complete( array $delivery, string $outcome, int $bytes_sent ) {
		global $wpdb;
		if ( ! in_array( $outcome, self::OUTCOMES, true ) || $bytes_sent < 0 ) {
			return new \WP_Error( 'booking_attachment_delivery_outcome_invalid', __( 'The attachment delivery outcome is invalid.', 'extrachill-events' ) );
		}
		if ( ( 'completed' === $outcome && $bytes_sent !== $delivery['expected_bytes'] ) || ( 'partial' === $outcome && ( $bytes_sent < 1 || $bytes_sent >= $delivery['expected_bytes'] ) ) ) {
			return new \WP_Error( 'booking_attachment_delivery_bytes_incomplete', __( 'The attachment delivery byte count does not match its terminal outcome.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( 'terminal' === $delivery['state'] ) {
			return $delivery['outcome'] === $outcome && $delivery['bytes_sent'] === $bytes_sent
				? $delivery
				: new \WP_Error( 'booking_attachment_delivery_outcome_conflict', __( 'The attachment delivery already has a different terminal outcome.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( 'consumed' !== $delivery['state'] ) {
			return new \WP_Error( 'booking_attachment_delivery_not_consumed', __( 'The attachment delivery was not consumed before its outcome.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact immutable terminal compare-and-swap.
		$updated = $wpdb->update(
			BookingSchema::attachment_deliveries_table(),
			array(
				'state'       => 'terminal',
				'outcome'     => $outcome,
				'bytes_sent'  => $bytes_sent,
				'terminal_at' => $now,
				'updated_at'  => $now,
			),
			array(
				'correlation_id' => $delivery['correlation_id'],
				'state'          => 'consumed',
			)
		);
		if ( 1 === $updated ) {
			return $this->get( $delivery['correlation_id'] );
		}
		$current = $this->get( $delivery['correlation_id'] );
		return is_array( $current ) && 'terminal' === $current['state'] && $current['outcome'] === $outcome && $current['bytes_sent'] === $bytes_sent
			? $current
			: new \WP_Error(
				'booking_attachment_delivery_outcome_conflict',
				__( 'The attachment delivery outcome changed concurrently.', 'extrachill-events' ),
				array(
					'status'         => 409,
					'database_error' => $wpdb->last_error,
				)
			);
	}

	/**
	 * Mark an old issued/consumed row interrupted during explicit reconciliation.
	 *
	 * @param array $delivery Stored delivery row.
	 */
	public function interrupt_stale( array $delivery ) {
		global $wpdb;
		if ( ! in_array( $delivery['state'], array( 'issued', 'consumed' ), true ) ) {
			return new \WP_Error( 'booking_attachment_delivery_reconciliation_conflict', __( 'The attachment delivery is no longer stale.', 'extrachill-events' ) );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit stale-delivery reconciliation.
		$updated = $wpdb->update(
			BookingSchema::attachment_deliveries_table(),
			array(
				'state'       => 'terminal',
				'outcome'     => 'interrupted',
				'bytes_sent'  => 0,
				'terminal_at' => $now,
				'updated_at'  => $now,
			),
			array(
				'correlation_id' => $delivery['correlation_id'],
				'state'          => $delivery['state'],
			)
		);
		return 1 === $updated
			? $this->get( $delivery['correlation_id'] )
			: new \WP_Error( 'booking_attachment_delivery_reconciliation_conflict', __( 'The attachment delivery changed during reconciliation.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
	}

	/**
	 * Return a bounded newest-first booking page for operator diagnostics.
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $limit      Maximum rows.
	 */
	public function list_for_booking( int $booking_id, int $limit = 100 ) {
		global $wpdb;
		$table = BookingSchema::attachment_deliveries_table();
		$limit = max( 1, min( 100, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY issued_at DESC, id DESC LIMIT %d", $booking_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded private diagnostics page.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_attachment_delivery_read_failed', __( 'Attachment delivery diagnostics could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Return bounded stale nonterminal rows for explicit reconciliation.
	 *
	 * @param string $cutoff UTC database cutoff.
	 */
	public function list_stale( string $cutoff ) {
		global $wpdb;
		$table = BookingSchema::attachment_deliveries_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE state IN ('issued', 'consumed') AND updated_at < %s ORDER BY id ASC LIMIT 250", $cutoff ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded reconciliation candidates.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_attachment_delivery_read_failed', __( 'Stale attachment deliveries could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Return bounded terminal rows eligible for explicit retention cleanup.
	 *
	 * @param string $cutoff UTC database cutoff.
	 */
	public function list_terminal_before( string $cutoff ) {
		global $wpdb;
		$table = BookingSchema::attachment_deliveries_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE state = 'terminal' AND terminal_at < %s ORDER BY id ASC LIMIT 250", $cutoff ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded retention candidates.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_attachment_delivery_read_failed', __( 'Attachment delivery retention candidates could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Delete one exact terminal row after policy and authorization checks.
	 *
	 * @param array $delivery Stored delivery row.
	 */
	public function delete_terminal( array $delivery ) {
		global $wpdb;
		if ( 'terminal' !== $delivery['state'] ) {
			return new \WP_Error( 'booking_attachment_delivery_retention_conflict', __( 'Only terminal delivery correlations can be retained out.', 'extrachill-events' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit policy-gated operational retention.
		$deleted = $wpdb->delete(
			BookingSchema::attachment_deliveries_table(),
			array(
				'correlation_id' => $delivery['correlation_id'],
				'state'          => 'terminal',
			)
		);
		return 1 === $deleted ? true : new \WP_Error( 'booking_attachment_delivery_retention_conflict', __( 'The delivery correlation changed before retention cleanup.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
	}

	/**
	 * Confirm one caller-provided tuple matches the immutable delivery binding.
	 *
	 * @param mixed $delivery      Stored delivery or null.
	 * @param int   $booking_id    Booking ID.
	 * @param int   $attachment_id Attachment ID.
	 * @param int   $actor_id      Actor ID.
	 */
	public function matches( $delivery, int $booking_id, int $attachment_id, int $actor_id ): bool {
		return is_array( $delivery ) && $delivery['booking_id'] === $booking_id && $delivery['attachment_id'] === $attachment_id && $delivery['actor_id'] === $actor_id;
	}

	/** Return a generic binding error without disclosing which field mismatched. */
	public function invalid_binding(): \WP_Error {
		return new \WP_Error( 'booking_attachment_delivery_invalid', __( 'The attachment delivery correlation is invalid.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/**
	 * Present only transport-safe correlation diagnostics.
	 *
	 * @param array $delivery Stored delivery row.
	 */
	public function present( array $delivery ): array {
		return array(
			'correlation_id' => $delivery['correlation_id'],
			'state'          => $delivery['state'],
			'outcome'        => $delivery['outcome'],
			'bytes_sent'     => $delivery['bytes_sent'],
			'issued_at'      => $delivery['issued_at'],
			'consumed_at'    => $delivery['consumed_at'],
			'terminal_at'    => $delivery['terminal_at'],
		);
	}

	/**
	 * Hydrate scalar delivery values.
	 *
	 * @param array $row Database row.
	 */
	public function hydrate( array $row ): array {
		foreach ( array( 'id', 'booking_id', 'attachment_id', 'actor_id', 'expected_bytes', 'bytes_sent' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : null;
		}
		return $row;
	}
}
