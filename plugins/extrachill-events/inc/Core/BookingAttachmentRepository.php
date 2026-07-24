<?php
/**
 * Private booking attachment metadata repository.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores opaque references and policy metadata, never byte paths or URLs. */
class BookingAttachmentRepository {

	/**
	 * Insert one active reference or return the exact idempotent winner.
	 *
	 * @param array $data Validated attachment data.
	 */
	public function create( array $data ) {
		global $wpdb;

		$existing = $this->find_idempotent( (int) $data['booking_id'], (string) $data['idempotency_key'] );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( is_array( $existing ) ) {
			return hash_equals( $existing['request_hash'], (string) $data['request_hash'] )
				? $existing
				: new \WP_Error( 'booking_attachment_idempotency_conflict', __( 'The attachment idempotency key was already used for different input.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			'public_id'              => wp_generate_uuid4(),
			'booking_id'             => (int) $data['booking_id'],
			'uploader_type'          => (string) $data['uploader_type'],
			'uploader_user_id'       => $data['uploader_user_id'],
			'uploader_reference'     => $data['uploader_reference'],
			'artist_term_id'         => $data['artist_term_id'],
			'artist_profile_id'      => $data['artist_profile_id'],
			'purpose'                => (string) $data['purpose'],
			'original_filename'      => (string) $data['original_filename'],
			'mime_type'              => (string) $data['mime_type'],
			'byte_size'              => (int) $data['byte_size'],
			'content_hash'           => (string) $data['content_hash'],
			'storage_reference'      => (string) $data['storage_reference'],
			'state'                  => 'active',
			'idempotency_key'        => (string) $data['idempotency_key'],
			'request_hash'           => (string) $data['request_hash'],
			'replaces_attachment_id' => $data['replaces_attachment_id'] ?? null,
			'retired_at'             => null,
			'purged_at'              => null,
			'created_at'             => $now,
			'updated_at'             => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private operational metadata write.
		if ( false === $wpdb->insert( BookingSchema::attachments_table(), $row ) ) {
			$winner = $this->find_idempotent( $row['booking_id'], $row['idempotency_key'] );
			if ( is_array( $winner ) ) {
				return hash_equals( $winner['request_hash'], $row['request_hash'] )
					? $winner
					: new \WP_Error( 'booking_attachment_idempotency_conflict', __( 'The attachment idempotency key was already used for different input.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			return is_wp_error( $winner )
				? $winner
				: new \WP_Error( 'booking_attachment_create_failed', __( 'The attachment metadata could not be saved.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return $this->get( (int) $wpdb->insert_id );
	}

	/**
	 * Read one attachment without accepting caller-supplied venue identity.
	 *
	 * @param int $id Attachment ID.
	 */
	public function get( int $id ) {
		global $wpdb;
		$table = BookingSchema::attachments_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_attachment_read_failed', __( 'The attachment metadata could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Reject an attachment reference that does not belong to the resolved booking.
	 *
	 * @param int $booking_id    Booking ID.
	 * @param int $attachment_id Attachment ID.
	 */
	public function get_for_booking( int $booking_id, int $attachment_id ) {
		$attachment = $this->get( $attachment_id );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		if ( ! is_array( $attachment ) || $booking_id !== $attachment['booking_id'] ) {
			return new \WP_Error( 'booking_attachment_not_found', __( 'The attachment was not found for this booking.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		return $attachment;
	}

	/**
	 * List non-purged references for one internally resolved booking.
	 *
	 * @param int $booking_id Booking ID.
	 */
	public function list_for_booking( int $booking_id ) {
		global $wpdb;
		$table = BookingSchema::attachments_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d AND state != 'purged' ORDER BY created_at DESC, id DESC", $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_attachment_list_failed', __( 'The booking attachments could not be listed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Find references to an object so reuse and physical deletion can be bounded.
	 *
	 * @param string $reference  Opaque storage reference.
	 * @param bool   $for_update Whether to lock matching rows.
	 */
	public function list_by_storage_reference( string $reference, bool $for_update = false ) {
		global $wpdb;
		$table = BookingSchema::attachments_table();
		$lock  = $for_update ? ' FOR UPDATE' : '';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE storage_reference = %s AND state != 'purged'{$lock}", $reference ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table and optional aggregate lock.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_attachment_reference_read_failed', __( 'Attachment references could not be read safely.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * List retired references old enough for domain retention review.
	 *
	 * @param string $cutoff UTC database datetime.
	 */
	public function list_retired_before( string $cutoff ) {
		global $wpdb;
		$table = BookingSchema::attachments_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE state IN ('replaced', 'deleted', 'abandoned', 'purging') AND retired_at < %s ORDER BY retired_at ASC LIMIT 250", $cutoff ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_attachment_cleanup_read_failed', __( 'Attachment cleanup candidates could not be read safely.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * List active replacement rows whose prior retirement may have crashed.
	 *
	 * @param string $cutoff   UTC database datetime.
	 * @param int    $after_id Exclusive stable keyset cursor.
	 */
	public function list_active_replacements_before( string $cutoff, int $after_id = 0 ) {
		global $wpdb;
		$table = BookingSchema::attachments_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE state = 'active' AND replaces_attachment_id IS NOT NULL AND created_at < %s AND id > %d ORDER BY id ASC LIMIT 251", $cutoff, $after_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit bounded keyset reconciliation candidates.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_attachment_reconciliation_read_failed', __( 'Incomplete attachment replacements could not be inspected.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		$rows      = array_map( array( $this, 'hydrate' ), (array) $rows );
		$items     = array_slice( $rows, 0, 250 );
		$truncated = count( $rows ) > 250;
		return array(
			'items'        => $items,
			'truncated'    => $truncated,
			'continuation' => $truncated && ! empty( $items ) ? $items[ count( $items ) - 1 ]['id'] : null,
		);
	}

	/**
	 * Apply a one-way lifecycle state transition.
	 *
	 * @param int    $id    Attachment ID.
	 * @param string $state Target state.
	 */
	public function retire( int $id, string $state ) {
		global $wpdb;
		if ( ! in_array( $state, array( 'replaced', 'deleted', 'abandoned', 'purging', 'purged' ), true ) ) {
			return new \WP_Error( 'invalid_booking_attachment_state', __( 'The attachment state is not supported.', 'extrachill-events' ) );
		}
		$current = $this->get( $id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! is_array( $current ) ) {
			return new \WP_Error( 'booking_attachment_not_found', __( 'The attachment was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		if ( $state === $current['state'] ) {
			return $current;
		}
		if ( 'active' !== $current['state'] && ! ( 'purging' === $current['state'] && 'purged' === $state ) && ! ( in_array( $current['state'], array( 'replaced', 'deleted', 'abandoned' ), true ) && 'purging' === $state ) ) {
			return new \WP_Error( 'booking_attachment_inactive', __( 'Only active attachments can be replaced or deleted.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$now     = gmdate( 'Y-m-d H:i:s' );
		$updates = array(
			'state'      => $state,
			'updated_at' => $now,
		);
		if ( 'purged' === $state ) {
			$updates['purged_at'] = $now;
		} else {
			$updates['retired_at'] = $now;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private operational metadata update.
		$updated = $wpdb->update(
			BookingSchema::attachments_table(),
			$updates,
			array(
				'id'    => $id,
				'state' => $current['state'],
			)
		);
		if ( false === $updated ) {
			return new \WP_Error( 'booking_attachment_update_failed', __( 'The attachment metadata could not be updated.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		if ( 0 === $updated ) {
			$latest = $this->get( $id );
			return is_array( $latest ) && $state === $latest['state']
				? $latest
				: new \WP_Error( 'booking_attachment_state_conflict', __( 'The attachment changed before the operation completed.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return $this->get( $id );
	}

	/**
	 * Restore a recoverable purging row when phase-two policy denies deletion.
	 *
	 * @param int    $id    Attachment ID.
	 * @param string $state Prior inactive state.
	 */
	public function cancel_purge( int $id, string $state ) {
		global $wpdb;
		if ( ! in_array( $state, array( 'replaced', 'deleted', 'abandoned' ), true ) ) {
			$state = 'deleted';
		}
		$current = $this->get( $id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! is_array( $current ) ) {
			return new \WP_Error( 'booking_attachment_not_found', __( 'The attachment was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		if ( $state === $current['state'] ) {
			return $current;
		}
		if ( 'purging' !== $current['state'] ) {
			return new \WP_Error( 'booking_attachment_state_conflict', __( 'The attachment is no longer in recoverable purging state.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restores policy-blocked cleanup state.
		$updated = $wpdb->update(
			BookingSchema::attachments_table(),
			array(
				'state'      => $state,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'    => $id,
				'state' => 'purging',
			)
		);
		if ( false === $updated ) {
			return new \WP_Error( 'booking_attachment_purge_cancel_failed', __( 'The attachment cleanup state could not be restored.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return 1 === $updated ? $this->get( $id ) : new \WP_Error( 'booking_attachment_state_conflict', __( 'The attachment cleanup state changed before it could be restored.', 'extrachill-events' ) );
	}

	/**
	 * Find one booking-scoped idempotent record.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $key        Idempotency key.
	 */
	private function find_idempotent( int $booking_id, string $key ) {
		global $wpdb;
		$table = BookingSchema::attachments_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d AND idempotency_key = %s LIMIT 1", $booking_id, $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_attachment_read_failed', __( 'Attachment idempotency could not be checked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Hydrate scalar attachment values.
	 *
	 * @param array $row Database row.
	 */
	public function hydrate( array $row ): array {
		foreach ( array( 'id', 'booking_id', 'uploader_user_id', 'artist_term_id', 'artist_profile_id', 'byte_size', 'replaces_attachment_id' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : null;
		}
		return $row;
	}
}
