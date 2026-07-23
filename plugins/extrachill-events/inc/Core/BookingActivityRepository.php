<?php
/**
 * Append-only booking activity repository.
 *
 * Queries use the current Events site's `$wpdb->prefix`; callers must preserve
 * Events-site route affinity.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores immutable operational activity records. */
class BookingActivityRepository {

	private const PAYLOAD_VERSION = 1;

	/** Append one activity record, returning an idempotent prior record when found. */
	public function append( array $data ) {
		global $wpdb;

		$booking_id = $this->positive_id( $data['booking_id'] ?? null, 'booking_id', false );
		if ( is_wp_error( $booking_id ) ) {
			return $booking_id;
		}
		$booking = ( new BookingRepository() )->get( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return new \WP_Error( 'booking_activity_booking_read_failed', __( 'The activity booking could not be verified.', 'extrachill-events' ), $booking->get_error_data() );
		}
		if ( null === $booking ) {
			return new \WP_Error( 'booking_activity_orphan', __( 'Activity cannot be appended to a missing booking.', 'extrachill-events' ) );
		}

		$kind = mb_substr( sanitize_key( (string) ( $data['kind'] ?? '' ) ), 0, 64 );
		if ( '' === $kind ) {
			return new \WP_Error( 'invalid_booking_activity_kind', __( 'Booking activity requires a kind.', 'extrachill-events' ) );
		}
		$idempotency_key = $this->nullable_text( $data['idempotency_key'] ?? null, 191 );
		if ( null !== $idempotency_key ) {
			$existing = $this->find_by_idempotency( $booking_id, $idempotency_key );
			if ( is_wp_error( $existing ) || is_array( $existing ) ) {
				return $existing;
			}
		}

		$payload = wp_json_encode(
			array(
				'version' => self::PAYLOAD_VERSION,
				'data'    => $data['payload'] ?? array(),
			)
		);
		if ( false === $payload ) {
			return new \WP_Error( 'booking_activity_payload_encode_failed', __( 'Activity payload JSON encoding failed.', 'extrachill-events' ), array( 'json_error' => json_last_error_msg() ) );
		}
		$occurred_at = $this->datetime( $data['occurred_at'] ?? gmdate( 'Y-m-d H:i:s' ) );
		if ( is_wp_error( $occurred_at ) ) {
			return $occurred_at;
		}
		$actor_id = $this->positive_id( $data['actor_id'] ?? null, 'actor_id', true );
		if ( is_wp_error( $actor_id ) ) {
			return $actor_id;
		}

		$row = array(
			'booking_id'      => $booking_id,
			'kind'            => $kind,
			'actor_type'      => mb_substr( sanitize_key( (string) ( $data['actor_type'] ?? 'system' ) ), 0, 32 ),
			'actor_id'        => $actor_id,
			'direction'       => $this->nullable_key( $data['direction'] ?? null, 16 ),
			'channel'         => $this->nullable_key( $data['channel'] ?? null, 32 ),
			'payload'         => $payload,
			'external_id'     => $this->nullable_text( $data['external_id'] ?? null, 191 ),
			'idempotency_key' => $idempotency_key,
			'occurred_at'     => $occurred_at,
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private append-only table write.
		if ( false === $wpdb->insert( BookingSchema::activity_table(), $row ) ) {
			if ( null !== $idempotency_key ) {
				$existing = $this->find_by_idempotency( $booking_id, $idempotency_key );
				if ( is_wp_error( $existing ) || is_array( $existing ) ) {
					return $existing;
				}
			}
			return new \WP_Error( 'booking_activity_write_failed', __( 'The booking activity could not be recorded.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return $this->get( (int) $wpdb->insert_id );
	}

	/** Get one activity record by ID. */
	public function get( int $id ) {
		global $wpdb;
		$id = $this->positive_id( $id, 'activity_id', false );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$table = BookingSchema::activity_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_activity_read_failed', __( 'The booking activity could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/** List recent activity for one booking. */
	public function list_for_booking( int $booking_id, int $limit = 100, int $offset = 0 ) {
		global $wpdb;
		$booking_id = $this->positive_id( $booking_id, 'booking_id', false );
		if ( is_wp_error( $booking_id ) ) {
			return $booking_id;
		}
		$table  = BookingSchema::activity_table();
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY occurred_at DESC, id DESC LIMIT %d OFFSET %d", $booking_id, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_activity_list_failed', __( 'Booking activity could not be listed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		$hydrated = array();
		foreach ( (array) $rows as $row ) {
			$item = $this->hydrate( $row );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$hydrated[] = $item;
		}
		return $hydrated;
	}

	/** Reconstruct and validate the complete activity-backed event conversion state. */
	public function event_conversion_state( int $booking_id, string $public_id ) {
		global $wpdb;
		$table = BookingSchema::activity_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d AND (kind IN ('event_conversion_started', 'event_conversion_failed', 'event_converted') OR idempotency_key LIKE %s) ORDER BY id ASC", $booking_id, 'event-conversion:' . $public_id . ':%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Complete narrow conversion history plus colliding keys, never a bounded recent list.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_event_conversion_state_read_failed', __( 'Booking event conversion state could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		$source   = 'extrachill-events-booking';
		$identity = hash( 'sha256', $source . "\0" . $public_id );
		$attempts = array();
		foreach ( (array) $rows as $row ) {
			$marker = $this->hydrate( $row );
			if ( is_wp_error( $marker ) ) {
				return $this->conversion_state_error( 'payload', $row['id'] ?? 0 );
			}
			$data    = $marker['payload']['data'];
			$attempt = $data['attempt'] ?? null;
			$kind    = $marker['kind'];
			$key     = sprintf( 'event-conversion:%s:%d:%s', $public_id, (int) $attempt, $kind );
			if ( ! is_int( $attempt ) || $attempt < 1 || ! in_array( $kind, array( 'event_conversion_started', 'event_conversion_failed', 'event_converted' ), true ) || $marker['idempotency_key'] !== $key || ( $data['source'] ?? null ) !== $source || ( $data['source_id'] ?? null ) !== $public_id || ( $data['source_identity'] ?? null ) !== $identity || isset( $attempts[ $attempt ][ $kind ] ) ) {
				return $this->conversion_state_error( 'marker', $marker['id'] );
			}
			if ( 'event_conversion_started' === $kind && ( ! is_int( $data['expected_version'] ?? null ) || $data['expected_version'] < 1 || null !== $marker['external_id'] ) ) {
				return $this->conversion_state_error( 'started', $marker['id'] );
			}
			if ( 'event_conversion_failed' === $kind && ( ! is_string( $data['upstream_code'] ?? null ) || '' === $data['upstream_code'] || ! array_key_exists( 'upstream_data', $data ) || ! is_bool( $data['retryable'] ?? null ) || null !== $marker['external_id'] ) ) {
				return $this->conversion_state_error( 'failed', $marker['id'] );
			}
			if ( 'event_converted' === $kind && ( ! is_int( $data['event_id'] ?? null ) || $data['event_id'] < 1 || (string) $data['event_id'] !== (string) $marker['external_id'] ) ) {
				return $this->conversion_state_error( 'completed', $marker['id'] );
			}
			$attempts[ $attempt ][ $kind ] = $marker;
		}
		if ( empty( $attempts ) ) {
			return array(
				'attempt'   => 0,
				'status'    => 'none',
				'pending'   => false,
				'started'   => null,
				'failed'    => null,
				'completed' => null,
			);
		}
		ksort( $attempts, SORT_NUMERIC );
		$expected_attempt     = 1;
		$latest               = null;
		$previous_terminal_id = 0;
		foreach ( $attempts as $attempt => $markers ) {
			$terminals = (int) isset( $markers['event_conversion_failed'] ) + (int) isset( $markers['event_converted'] );
			$start_id  = (int) ( $markers['event_conversion_started']['id'] ?? 0 );
			$terminal  = $markers['event_conversion_failed'] ?? ( $markers['event_converted'] ?? null );
			if ( $attempt !== $expected_attempt || ! isset( $markers['event_conversion_started'] ) || $terminals > 1 || ( null !== $latest && 'failed' !== $latest['status'] ) || $start_id <= $previous_terminal_id || ( is_array( $terminal ) && (int) $terminal['id'] <= $start_id ) ) {
				return $this->conversion_state_error( 'sequence', $markers['event_conversion_started']['id'] ?? 0 );
			}
			$expected_version = $markers['event_conversion_started']['payload']['data']['expected_version'];
			if ( isset( $markers['event_conversion_failed'] ) && ( $markers['event_conversion_failed']['payload']['data']['booking_version'] ?? null ) !== $expected_version ) {
				return $this->conversion_state_error( 'failed_version', $markers['event_conversion_failed']['id'] );
			}
			if ( isset( $markers['event_converted'] ) && ( $markers['event_converted']['payload']['data']['version'] ?? null ) !== $expected_version + 1 ) {
				return $this->conversion_state_error( 'completed_version', $markers['event_converted']['id'] );
			}
			$latest               = array(
				'attempt'   => $attempt,
				'markers'   => $markers,
				'terminals' => $terminals,
				'status'    => isset( $markers['event_conversion_failed'] ) ? 'failed' : ( isset( $markers['event_converted'] ) ? 'completed' : 'pending' ),
			);
			$previous_terminal_id = is_array( $terminal ) ? (int) $terminal['id'] : 0;
			++$expected_attempt;
		}
		$markers   = $latest['markers'];
		$completed = $markers['event_converted'] ?? null;
		$failed    = $markers['event_conversion_failed'] ?? null;
		return array(
			'attempt'   => $latest['attempt'],
			'status'    => $completed ? 'completed' : ( $failed ? 'failed' : 'pending' ),
			'pending'   => ! $completed && ! $failed,
			'started'   => $markers['event_conversion_started'],
			'failed'    => $failed,
			'completed' => $completed,
		);
	}

	private function conversion_state_error( string $detail, int $activity_id ): \WP_Error {
		return new \WP_Error(
			'booking_event_conversion_state_invalid',
			__( 'Booking event conversion activity is malformed or inconsistent.', 'extrachill-events' ),
			array(
				'status'      => 409,
				'repairable'  => true,
				'detail'      => $detail,
				'activity_id' => $activity_id,
			)
		);
	}

	/** Find an existing booking-scoped idempotent activity. */
	private function find_by_idempotency( int $booking_id, string $key ) {
		global $wpdb;
		$table = BookingSchema::activity_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d AND idempotency_key = %s LIMIT 1", $booking_id, $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_activity_read_failed', __( 'Booking activity idempotency could not be checked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/** Hydrate one validated activity row. */
	public function hydrate( array $row ) {
		$decoded = json_decode( (string) ( $row['payload'] ?? '' ), true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) || ! array_key_exists( 'version', $decoded ) || ! array_key_exists( 'data', $decoded ) ) {
			return new \WP_Error( 'booking_activity_payload_invalid_json', __( 'Stored activity payload JSON is malformed.', 'extrachill-events' ), array( 'json_error' => json_last_error_msg() ) );
		}
		if ( ! is_int( $decoded['version'] ) || self::PAYLOAD_VERSION !== $decoded['version'] ) {
			return new \WP_Error( 'booking_activity_payload_version_unsupported', __( 'Stored activity payload version is unsupported.', 'extrachill-events' ), array( 'version' => $decoded['version'] ) );
		}
		$row['id']         = (int) $row['id'];
		$row['booking_id'] = (int) $row['booking_id'];
		$row['actor_id']   = isset( $row['actor_id'] ) ? (int) $row['actor_id'] : null;
		$row['payload']    = $decoded;
		return $row;
	}

	private function positive_id( $value, string $field, bool $nullable ) {
		if ( null === $value || '' === $value || 0 === $value || '0' === $value ) {
			return $nullable ? null : new \WP_Error( 'invalid_booking_activity_id', __( 'A positive identifier is required.', 'extrachill-events' ), array( 'field' => $field ) );
		}
		if ( ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) || (int) $value < 1 ) {
			return new \WP_Error( 'invalid_booking_activity_id', __( 'Identifiers must be positive integers.', 'extrachill-events' ), array( 'field' => $field ) );
		}
		return (int) $value;
	}

	private function nullable_key( $value, int $length ): ?string {
		$value = mb_substr( sanitize_key( (string) $value ), 0, $length );
		return '' === $value ? null : $value;
	}

	private function nullable_text( $value, int $length ): ?string {
		$value = sanitize_text_field( (string) $value );
		return '' === $value ? null : mb_substr( $value, 0, $length );
	}

	private function datetime( $value ) {
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $value, new \DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d H:i:s' ) === $value
			? $date->format( 'Y-m-d H:i:s' )
			: new \WP_Error( 'invalid_booking_activity_datetime', __( 'Activity timestamps must use UTC Y-m-d H:i:s format.', 'extrachill-events' ) );
	}
}
