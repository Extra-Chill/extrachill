<?php
/**
 * Private venue booking repository.
 *
 * Queries are intentionally scoped by the current site's `$wpdb->prefix`.
 * Callers must invoke this repository on the Events-site route; identity
 * validation switches only while reading canonical artist records.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provides bounded access to operational booking records. */
class BookingRepository {

	private const PAYLOAD_VERSION = 1;

	/** Create a booking without an event handoff. */
	public function create( array $data ) {
		global $wpdb;

		$venue_term_id = $this->positive_id( $data['venue_term_id'] ?? null, 'venue_term_id', false );
		if ( is_wp_error( $venue_term_id ) ) {
			return $venue_term_id;
		}
		$venue = get_term( $venue_term_id, 'venue' );
		if ( ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error( 'invalid_booking_venue', __( 'A valid Events venue term is required.', 'extrachill-events' ) );
		}

		$identity = $this->validate_identity( $data );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$artist_name_input = sanitize_text_field( (string) ( $data['artist_name'] ?? '' ) );
		$artist_name       = $this->required_text( '' !== $artist_name_input ? $artist_name_input : $identity['artist_name'], 'artist_name', 255 );
		if ( is_wp_error( $artist_name ) ) {
			return $artist_name;
		}

		$ids = array();
		foreach ( array( 'submitter_user_id', 'assignee_user_id' ) as $field ) {
			$ids[ $field ] = $this->positive_id( $data[ $field ] ?? null, $field, true );
			if ( is_wp_error( $ids[ $field ] ) ) {
				return $ids[ $field ];
			}
		}

		$start = $this->datetime( $data['requested_start_at'] ?? null, 'requested_start_at' );
		$end   = $this->datetime( $data['requested_end_at'] ?? null, 'requested_end_at' );
		$dates = $this->validate_date_range( $start, $end );
		if ( is_wp_error( $dates ) ) {
			return $dates;
		}

		$intake = $this->encode_payload( $data['intake'] ?? array(), 'intake' );
		if ( is_wp_error( $intake ) ) {
			return $intake;
		}
		$deal = null;
		if ( array_key_exists( 'deal', $data ) ) {
			$deal = $this->encode_payload( $data['deal'], 'deal' );
			if ( is_wp_error( $deal ) ) {
				return $deal;
			}
		}

		$status = $this->status( $data['status'] ?? 'inquiry' );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			'public_id'          => wp_generate_uuid4(),
			'venue_term_id'      => $venue_term_id,
			'artist_term_id'     => $identity['artist_term_id'],
			'artist_profile_id'  => $identity['artist_profile_id'],
			'artist_name'        => $artist_name,
			'submitter_user_id'  => $ids['submitter_user_id'],
			'contact_name'       => $this->nullable_text( $data['contact_name'] ?? null, 255 ),
			'contact_email'      => $this->nullable_email( $data['contact_email'] ?? null ),
			'contact_phone'      => $this->nullable_text( $data['contact_phone'] ?? null, 64 ),
			'space_key'          => $this->nullable_key( $data['space_key'] ?? null, 64 ),
			'status'             => $status,
			'version'            => 1,
			'assignee_user_id'   => $ids['assignee_user_id'],
			'requested_start_at' => $start,
			'requested_end_at'   => $end,
			'intake_payload'     => $intake,
			'deal_payload'       => $deal,
			'event_id'           => null,
			'created_at'         => $now,
			'updated_at'         => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private operational table write.
		if ( false === $wpdb->insert( BookingSchema::bookings_table(), $row ) ) {
			return new \WP_Error( 'booking_create_failed', __( 'The booking could not be created.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return $this->get( (int) $wpdb->insert_id );
	}

	/** Get a booking by numeric ID or UUID public reference. */
	public function get( $identifier ) {
		global $wpdb;
		$table = BookingSchema::bookings_table();
		if ( is_int( $identifier ) || ( is_string( $identifier ) && ctype_digit( $identifier ) ) ) {
			$id = $this->positive_id( $identifier, 'booking_id', false );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted current-prefix table.
		} else {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s LIMIT 1", (string) $identifier ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted current-prefix table.
		}
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query prepared above.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_read_failed', __( 'The booking could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/** List bookings for one venue with bounded optional filters. */
	public function list( array $filters ) {
		global $wpdb;

		$venue_term_id = $this->positive_id( $filters['venue_term_id'] ?? null, 'venue_term_id', false );
		if ( is_wp_error( $venue_term_id ) ) {
			return $venue_term_id;
		}
		$table  = BookingSchema::bookings_table();
		$where  = array( 'venue_term_id = %d' );
		$values = array( $venue_term_id );

		if ( isset( $filters['status'] ) && '' !== $filters['status'] ) {
			$status = $this->status( $filters['status'] );
			if ( is_wp_error( $status ) ) {
				return $status;
			}
			$where[]  = 'status = %s';
			$values[] = $status;
		}
		foreach ( array( 'artist_term_id', 'artist_profile_id', 'assignee_user_id' ) as $field ) {
			if ( ! array_key_exists( $field, $filters ) || null === $filters[ $field ] || '' === $filters[ $field ] ) {
				continue;
			}
			$id = $this->positive_id( $filters[ $field ], $field, false );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$where[]  = "{$field} = %d";
			$values[] = $id;
		}
		$date_filters = array(
			'requested_start_at' => '>=',
			'requested_end_at'   => '<=',
		);
		foreach ( $date_filters as $field => $operator ) {
			if ( empty( $filters[ $field ] ) ) {
				continue;
			}
			$date = $this->datetime( $filters[ $field ], $field );
			if ( is_wp_error( $date ) ) {
				return $date;
			}
			$where[]  = "{$field} {$operator} %s";
			$values[] = $date;
		}

		$limit    = max( 1, min( 100, absint( $filters['limit'] ?? 50 ) ) );
		$offset   = max( 0, absint( $filters['offset'] ?? 0 ) );
		$values[] = $limit;
		$values[] = $offset;
		$query    = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted current-prefix table and fixed clauses.
		$rows     = $wpdb->get_results( $wpdb->prepare( $query, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic values are prepared.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_list_failed', __( 'Bookings could not be listed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
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

	/** Conditionally update mutable booking fields at an expected version. */
	public function update( int $id, array $changes, int $expected_version ) {
		global $wpdb;

		$id       = $this->positive_id( $id, 'booking_id', false );
		$expected = $this->positive_id( $expected_version, 'expected_version', false );
		if ( is_wp_error( $id ) || is_wp_error( $expected ) ) {
			return is_wp_error( $id ) ? $id : $expected;
		}
		$current = $this->get( $id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( null === $current ) {
			return new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
		}

		if ( array_key_exists( 'artist_term_id', $changes ) || array_key_exists( 'artist_profile_id', $changes ) ) {
			$identity = $this->validate_identity( array_merge( $current, $changes ) );
			if ( is_wp_error( $identity ) ) {
				return $identity;
			}
			$changes['artist_term_id']    = $identity['artist_term_id'];
			$changes['artist_profile_id'] = $identity['artist_profile_id'];
		}

		$allowed = array( 'artist_term_id', 'artist_profile_id', 'artist_name', 'contact_name', 'contact_email', 'contact_phone', 'space_key', 'status', 'assignee_user_id', 'requested_start_at', 'requested_end_at', 'intake', 'deal' );
		$changes = array_intersect_key( $changes, array_flip( $allowed ) );
		if ( empty( $changes ) ) {
			return new \WP_Error( 'empty_booking_update', __( 'No supported booking fields were supplied.', 'extrachill-events' ) );
		}

		$normalized = array();
		foreach ( $changes as $key => $value ) {
			if ( 'intake' === $key || 'deal' === $key ) {
				$value = $this->encode_payload( $value, $key );
			} elseif ( in_array( $key, array( 'artist_term_id', 'artist_profile_id', 'assignee_user_id' ), true ) ) {
				$value = $this->positive_id( $value, $key, true );
			} elseif ( in_array( $key, array( 'requested_start_at', 'requested_end_at' ), true ) ) {
				$value = $this->datetime( $value, $key );
			} elseif ( 'artist_name' === $key ) {
				$value = $this->required_text( $value, $key, 255 );
			} elseif ( 'contact_name' === $key ) {
				$value = $this->nullable_text( $value, 255 );
			} elseif ( 'contact_email' === $key ) {
				$value = $this->nullable_email( $value );
			} elseif ( 'contact_phone' === $key ) {
				$value = $this->nullable_text( $value, 64 );
			} elseif ( 'space_key' === $key ) {
				$value = $this->nullable_key( $value, 64 );
			} elseif ( 'status' === $key ) {
				$value = $this->status( $value );
			}
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$normalized[ 'intake' === $key || 'deal' === $key ? $key . '_payload' : $key ] = $value;
		}

		$start = array_key_exists( 'requested_start_at', $normalized ) ? $normalized['requested_start_at'] : $current['requested_start_at'];
		$end   = array_key_exists( 'requested_end_at', $normalized ) ? $normalized['requested_end_at'] : $current['requested_end_at'];
		$dates = $this->validate_date_range( $start, $end );
		if ( is_wp_error( $dates ) ) {
			return $dates;
		}

		$result = $this->conditional_update( $id, $expected, $normalized, '' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->get( $id );
	}

	/** Atomically claim one site-local Data Machine event handoff. */
	public function claim_event( int $id, int $event_id, int $expected_version ) {
		$id       = $this->positive_id( $id, 'booking_id', false );
		$event_id = $this->positive_id( $event_id, 'event_id', false );
		$expected = $this->positive_id( $expected_version, 'expected_version', false );
		foreach ( array( $id, $event_id, $expected ) as $validated ) {
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
		}

		$current = $this->get( $id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( null === $current ) {
			return new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
		}
		if ( $event_id === $current['event_id'] ) {
			return $current;
		}
		if ( null !== $current['event_id'] ) {
			return new \WP_Error( 'booking_event_already_linked', __( 'The booking is already linked to a different event.', 'extrachill-events' ), array( 'event_id' => $current['event_id'] ) );
		}

		$event     = get_post( $event_id );
		$post_type = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';
		if ( ! $event || $post_type !== $event->post_type ) {
			return new \WP_Error( 'invalid_booking_event', __( 'A valid site-local event is required.', 'extrachill-events' ) );
		}

		$result = $this->conditional_update( $id, $expected, array( 'event_id' => $event_id ), ' AND event_id IS NULL' );
		if ( is_wp_error( $result ) && 'booking_version_conflict' === $result->get_error_code() ) {
			$latest = $this->get( $id );
			if ( is_wp_error( $latest ) ) {
				return $latest;
			}
			if ( is_array( $latest ) && $event_id === $latest['event_id'] ) {
				return $latest;
			}
			if ( is_array( $latest ) && null !== $latest['event_id'] ) {
				return new \WP_Error( 'booking_event_already_linked', __( 'The booking is already linked to a different event.', 'extrachill-events' ), array( 'event_id' => $latest['event_id'] ) );
			}
		}
		return is_wp_error( $result ) ? $result : $this->get( $id );
	}

	/** Execute an optimistic update and distinguish disappearance from conflict. */
	private function conditional_update( int $id, int $expected, array $changes, string $extra_where ) {
		global $wpdb;

		$set    = array();
		$values = array();
		foreach ( $changes as $column => $value ) {
			if ( null === $value ) {
				$set[] = "{$column} = NULL";
			} elseif ( is_int( $value ) ) {
				$set[]    = "{$column} = %d";
				$values[] = $value;
			} else {
				$set[]    = "{$column} = %s";
				$values[] = $value;
			}
		}
		$set[]    = 'version = version + 1';
		$set[]    = 'updated_at = %s';
		$values[] = gmdate( 'Y-m-d H:i:s' );
		$values[] = $id;
		$values[] = $expected;
		$table    = BookingSchema::bookings_table();
		$query    = "UPDATE {$table} SET " . implode( ', ', $set ) . " WHERE id = %d AND version = %d{$extra_where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Columns and suffix are internal constants.
		$result   = $wpdb->query( $wpdb->prepare( $query, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are prepared.
		if ( false === $result ) {
			return new \WP_Error( 'booking_update_failed', __( 'The booking could not be updated.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		if ( 0 === $result ) {
			$current = $this->get( $id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			return null === $current
				? new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) )
				: new \WP_Error(
					'booking_version_conflict',
					__( 'The booking changed since it was read.', 'extrachill-events' ),
					array(
						'status'          => 409,
						'current_version' => $current['version'],
					)
				);
		}
		return true;
	}

	/** Validate canonical/profile identities and their bidirectional binding. */
	private function validate_identity( array $data ) {
		$term_id    = $this->positive_id( $data['artist_term_id'] ?? null, 'artist_term_id', true );
		$profile_id = $this->positive_id( $data['artist_profile_id'] ?? null, 'artist_profile_id', true );
		if ( is_wp_error( $term_id ) || is_wp_error( $profile_id ) ) {
			return is_wp_error( $term_id ) ? $term_id : $profile_id;
		}
		$name            = '';
		$profile_term_id = null;

		if ( $profile_id ) {
			$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
			if ( $artist_blog_id < 1 ) {
				return new \WP_Error( 'artist_platform_unresolved', __( 'The Artist Platform could not be resolved.', 'extrachill-events' ) );
			}
			switch_to_blog( $artist_blog_id );
			try {
				$profile         = get_post( $profile_id );
				$profile_term_id = $profile ? $this->positive_id( get_post_meta( $profile_id, '_artist_term_id', true ), 'profile_artist_term_id', true ) : null;
			} finally {
				restore_current_blog();
			}
			if ( ! $profile || 'artist_profile' !== $profile->post_type || 'publish' !== $profile->post_status ) {
				return new \WP_Error( 'invalid_booking_artist_profile', __( 'The artist profile must exist and be published.', 'extrachill-events' ) );
			}
			if ( is_wp_error( $profile_term_id ) ) {
				return $profile_term_id;
			}
			$name = (string) $profile->post_title;
			if ( null === $term_id && $profile_term_id ) {
				$term_id = $profile_term_id;
			}
		}

		$bound_profile_id = null;
		if ( $term_id ) {
			$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
			if ( $main_blog_id < 1 ) {
				return new \WP_Error( 'artist_site_unresolved', __( 'The canonical artist site could not be resolved.', 'extrachill-events' ) );
			}
			switch_to_blog( $main_blog_id );
			try {
				$term             = get_term( $term_id, 'artist' );
				$bound_profile_id = $term && ! is_wp_error( $term ) ? $this->positive_id( get_term_meta( $term_id, '_artist_profile_id', true ), 'term_artist_profile_id', true ) : null;
			} finally {
				restore_current_blog();
			}
			if ( ! $term || is_wp_error( $term ) || 'artist' !== $term->taxonomy ) {
				return new \WP_Error( 'invalid_booking_artist_term', __( 'The canonical artist term is invalid.', 'extrachill-events' ) );
			}
			if ( is_wp_error( $bound_profile_id ) ) {
				return $bound_profile_id;
			}
			$name = (string) $term->name;
		}

		if ( $profile_id && $term_id && ( $profile_term_id !== $term_id || $bound_profile_id !== $profile_id ) ) {
			return new \WP_Error( 'booking_artist_identity_mismatch', __( 'The artist term and profile bindings do not agree.', 'extrachill-events' ) );
		}
		return array(
			'artist_term_id'    => $term_id,
			'artist_profile_id' => $profile_id,
			'artist_name'       => $name,
		);
	}

	/** Encode flexible data without silently losing invalid structures. */
	private function encode_payload( $data, string $field ) {
		$encoded = wp_json_encode(
			array(
				'version' => self::PAYLOAD_VERSION,
				'data'    => $data,
			)
		);
		if ( false === $encoded ) {
			return new \WP_Error(
				'booking_payload_encode_failed',
				__( 'Booking payload JSON encoding failed.', 'extrachill-events' ),
				array(
					'field'      => $field,
					'json_error' => json_last_error_msg(),
				)
			);
		}
		return $encoded;
	}

	/** Hydrate scalar IDs and validated JSON envelopes. */
	public function hydrate( array $row ) {
		foreach ( array( 'id', 'venue_term_id', 'artist_term_id', 'artist_profile_id', 'submitter_user_id', 'version', 'assignee_user_id', 'event_id' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : null;
		}
		foreach ( array(
			'intake' => false,
			'deal'   => true,
		) as $key => $nullable ) {
			$decoded = $this->decode_payload( $row[ $key . '_payload' ] ?? null, $key, $nullable );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}
			$row[ $key ] = $decoded;
			unset( $row[ $key . '_payload' ] );
		}
		return $row;
	}

	private function decode_payload( $json, string $field, bool $nullable ) {
		if ( null === $json && $nullable ) {
			return null;
		}
		$decoded = json_decode( (string) $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) || ! array_key_exists( 'version', $decoded ) || ! array_key_exists( 'data', $decoded ) ) {
			return new \WP_Error(
				'booking_payload_invalid_json',
				__( 'Stored booking payload JSON is malformed.', 'extrachill-events' ),
				array(
					'field'      => $field,
					'json_error' => json_last_error_msg(),
				)
			);
		}
		if ( ! is_int( $decoded['version'] ) || self::PAYLOAD_VERSION !== $decoded['version'] ) {
			return new \WP_Error(
				'booking_payload_version_unsupported',
				__( 'Stored booking payload version is unsupported.', 'extrachill-events' ),
				array(
					'field'   => $field,
					'version' => $decoded['version'],
				)
			);
		}
		return $decoded;
	}

	private function positive_id( $value, string $field, bool $nullable ) {
		if ( null === $value || '' === $value || 0 === $value || '0' === $value ) {
			return $nullable ? null : new \WP_Error( 'invalid_booking_id', __( 'A positive identifier is required.', 'extrachill-events' ), array( 'field' => $field ) );
		}
		if ( ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) || (int) $value < 1 ) {
			return new \WP_Error( 'invalid_booking_id', __( 'Identifiers must be positive integers.', 'extrachill-events' ), array( 'field' => $field ) );
		}
		return (int) $value;
	}

	private function required_text( $value, string $field, int $length ) {
		$value = sanitize_text_field( (string) $value );
		return '' === $value ? new \WP_Error( 'missing_booking_field', __( 'A required booking field is missing.', 'extrachill-events' ), array( 'field' => $field ) ) : mb_substr( $value, 0, $length );
	}

	private function nullable_text( $value, int $length ): ?string {
		$value = sanitize_text_field( (string) $value );
		return '' === $value ? null : mb_substr( $value, 0, $length );
	}

	private function nullable_email( $value ): ?string {
		$value = sanitize_email( (string) $value );
		return '' === $value ? null : mb_substr( $value, 0, 255 );
	}

	private function nullable_key( $value, int $length ): ?string {
		$value = mb_substr( sanitize_key( (string) $value ), 0, $length );
		return '' === $value ? null : $value;
	}

	private function status( $value ) {
		$value = mb_substr( sanitize_key( (string) $value ), 0, 32 );
		return '' === $value ? new \WP_Error( 'invalid_booking_status', __( 'A valid booking status is required.', 'extrachill-events' ) ) : $value;
	}

	private function datetime( $value, string $field ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			return new \WP_Error( 'invalid_booking_datetime', __( 'Booking datetimes must use UTC Y-m-d H:i:s format.', 'extrachill-events' ), array( 'field' => $field ) );
		}
		return $date->format( 'Y-m-d H:i:s' );
	}

	private function validate_date_range( $start, $end ) {
		if ( is_wp_error( $start ) || is_wp_error( $end ) ) {
			return is_wp_error( $start ) ? $start : $end;
		}
		if ( null !== $start && null !== $end && $end < $start ) {
			return new \WP_Error( 'invalid_booking_date_range', __( 'The requested end must not precede the requested start.', 'extrachill-events' ) );
		}
		return true;
	}
}
