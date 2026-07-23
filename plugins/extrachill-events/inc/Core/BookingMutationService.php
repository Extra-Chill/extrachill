<?php
/**
 * Authorized booking intake, production, and deal mutations.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the domain-specific mutable booking documents. */
class BookingMutationService {

	/** @var BookingRepository */
	private $bookings;
	/** @var BookingActivityRepository */
	private $activity;
	/** @var VenueAuthorization */
	private $authorization;
	/** @var BookingHoldRepository */
	private $holds;
	/** @var bool */
	private $transaction_active = false;

	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?VenueAuthorization $authorization = null, ?BookingHoldRepository $holds = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->activity      = $activity ? $activity : new BookingActivityRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->holds         = $holds ? $holds : new BookingHoldRepository( $this->bookings, $this->activity, $this->authorization );
	}

	/** Correct one or more private intake facts as a single revision. */
	public function correct_intake( int $booking_id, int $expected_version, array $changes, int $actor_id ) {
		$allowed_fields = array( 'contact_name', 'contact_email', 'contact_phone', 'requested_space_key', 'requested_start_at', 'requested_end_at', 'intake' );
		$changes        = array_intersect_key( $changes, array_flip( $allowed_fields ) );
		if ( empty( $changes ) ) {
			return new \WP_Error( 'booking_intake_correction_required', __( 'At least one intake correction is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$normalized = array();
		foreach ( $changes as $field => $value ) {
			if ( 'contact_email' === $field ) {
				if ( null === $value || '' === $value ) {
					$value = null;
				} else {
					$value = sanitize_email( (string) $value );
					if ( '' === $value ) {
						return new \WP_Error( 'invalid_booking_contact_email', __( 'The contact email is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
					}
					$value = mb_substr( $value, 0, 255 );
				}
			} elseif ( in_array( $field, array( 'contact_name', 'contact_phone' ), true ) ) {
				$value = null === $value ? null : mb_substr( sanitize_text_field( (string) $value ), 0, 'contact_phone' === $field ? 64 : 255 );
				$value = '' === $value ? null : $value;
			} elseif ( 'requested_space_key' === $field ) {
				$value = null === $value ? null : mb_substr( sanitize_key( (string) $value ), 0, 64 );
				$value = '' === $value ? null : $value;
			} elseif ( in_array( $field, array( 'requested_start_at', 'requested_end_at' ), true ) ) {
				$value = $this->datetime( $value, $field );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
			} elseif ( 'intake' === $field ) {
				if ( ! is_array( $value ) ) {
					return new \WP_Error( 'invalid_booking_intake', __( 'Intake must be an object.', 'extrachill-events' ), array( 'status' => 400 ) );
				}
				$value = $this->encode( $value, 'intake' );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				$field = 'intake_payload';
			}
			$normalized[ $field ] = $value;
		}
		return $this->mutate(
			$booking_id,
			$expected_version,
			$actor_id,
			array( 'submitted', 'needs_info', 'under_review', 'negotiating' ),
			$normalized,
			'intake_corrected',
			function ( array $current, array $next ) use ( $changes ) {
				$start = $next['requested_start_at'];
				$end   = $next['requested_end_at'];
				if ( null !== $start && null !== $end && $end <= $start ) {
					return new \WP_Error( 'invalid_booking_date_range', __( 'The requested end must be later than the requested start.', 'extrachill-events' ), array( 'status' => 400 ) );
				}
				return array( 'changed_fields' => array_keys( $changes ) );
			}
		);
	}

	/** Replace the complete production document. */
	public function update_production( int $booking_id, int $expected_version, array $document, int $actor_id ) {
		$normalized = self::normalize_production_document( $document );
		return is_wp_error( $normalized ) ? $normalized : $this->replace_document( $booking_id, $expected_version, $actor_id, array( 'under_review', 'negotiating', 'held' ), 'production_payload', 'production', $normalized, 'production_updated', false );
	}

	/** Replace the complete draft deal document. */
	public function update_deal( int $booking_id, int $expected_version, array $document, int $actor_id ) {
		$normalized = self::normalize_deal_document( $document );
		return is_wp_error( $normalized ) ? $normalized : $this->replace_document( $booking_id, $expected_version, $actor_id, array( 'negotiating', 'held' ), 'deal_payload', 'deal', $normalized, 'deal_draft_updated', true );
	}

	/** Delegate scheduling to the hold-owned lock and conflict path. */
	public function select_performance( int $booking_id, int $expected_version, string $space_key, string $start_at, string $end_at, int $actor_id ) {
		return $this->holds->select_performance( $booking_id, $expected_version, $space_key, $start_at, $end_at, $actor_id );
	}

	/** Normalize the strict production replacement document. */
	public static function normalize_production_document( array $document ) {
		$fields = array( 'version', 'support_requirements', 'support_offers', 'production_notes' );
		if ( array_diff( $fields, array_keys( $document ) ) || array_diff( array_keys( $document ), $fields ) || 1 !== $document['version'] ) {
			return new \WP_Error( 'invalid_booking_production', __( 'The production document is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$output = array( 'version' => 1 );
		foreach ( array( 'support_requirements', 'support_offers' ) as $field ) {
			$items = $document[ $field ] ?? null;
			if ( ! is_array( $items ) || count( $items ) > 50 ) {
				return new \WP_Error(
					'invalid_booking_production',
					__( 'Production support lists must contain at most 50 items.', 'extrachill-events' ),
					array(
						'field'  => $field,
						'status' => 400,
					)
				);
			}
			$output[ $field ] = array();
			foreach ( $items as $item ) {
				if ( ! is_string( $item ) ) {
					return new \WP_Error(
						'invalid_booking_production',
						__( 'Production support items must be strings.', 'extrachill-events' ),
						array(
							'field'  => $field,
							'status' => 400,
						)
					);
				}
				$item = mb_substr( sanitize_text_field( $item ), 0, 500 );
				if ( '' === $item ) {
					return new \WP_Error(
						'invalid_booking_production',
						__( 'Production support items cannot be empty.', 'extrachill-events' ),
						array(
							'field'  => $field,
							'status' => 400,
						)
					);
				}
				$output[ $field ][] = $item;
			}
		}
		$notes = $document['production_notes'] ?? null;
		if ( null !== $notes && ! is_string( $notes ) ) {
			return new \WP_Error( 'invalid_booking_production', __( 'Production notes must be a string or null.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$notes                      = null === $notes ? null : mb_substr( sanitize_textarea_field( $notes ), 0, 10000 );
		$output['production_notes'] = '' === $notes ? null : $notes;
		return $output;
	}

	/** Normalize the strict complete draft-deal document. */
	public static function normalize_deal_document( array $document ) {
		$fields = array( 'version', 'type', 'guarantee_cents', 'revenue_share_basis_points', 'revenue_share_basis', 'currency', 'capacity', 'advance_ticket_price_cents', 'door_ticket_price_cents', 'ticket_fee_cents', 'tickets_on_sale_at', 'ticket_url', 'additional_terms' );
		if ( array_diff( $fields, array_keys( $document ) ) || array_diff( array_keys( $document ), $fields ) || 1 !== $document['version'] ) {
			return new \WP_Error( 'invalid_booking_deal', __( 'The complete version 1 deal document is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( ! is_string( $document['type'] ) || ! is_string( $document['revenue_share_basis'] ) || ! is_string( $document['currency'] ) ) {
			return new \WP_Error( 'invalid_booking_deal', __( 'The deal text fields are invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$type = mb_substr( sanitize_key( $document['type'] ), 0, 32 );
		if ( '' === $type || ! self::strict_integer( $document['guarantee_cents'], 0 ) || ! self::strict_integer( $document['revenue_share_basis_points'], 0, 10000 ) ) {
			return new \WP_Error( 'invalid_booking_deal', __( 'The deal financial terms are invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$basis = sanitize_key( $document['revenue_share_basis'] );
		if ( ! in_array( $basis, array( 'gross_ticket_sales', 'net_ticket_sales', 'door_receipts' ), true ) ) {
			return new \WP_Error( 'invalid_booking_deal', __( 'The revenue share basis is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$currency = strtoupper( sanitize_text_field( $document['currency'] ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return new \WP_Error( 'invalid_booking_deal', __( 'The deal currency is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		foreach ( array(
			'capacity'                   => 1,
			'advance_ticket_price_cents' => 0,
			'door_ticket_price_cents'    => 0,
			'ticket_fee_cents'           => 0,
		) as $field => $minimum ) {
			if ( null !== $document[ $field ] && ! self::strict_integer( $document[ $field ], $minimum ) ) {
				return new \WP_Error(
					'invalid_booking_deal',
					__( 'A deal ticket setting is invalid.', 'extrachill-events' ),
					array(
						'field'  => $field,
						'status' => 400,
					)
				);
			}
		}
		$on_sale = $document['tickets_on_sale_at'];
		if ( null !== $on_sale && ! self::valid_datetime( $on_sale ) ) {
			return new \WP_Error( 'invalid_booking_deal', __( 'The ticket on-sale time must be a UTC datetime.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$ticket_url = $document['ticket_url'];
		if ( null !== $ticket_url && ( ! is_string( $ticket_url ) || false === filter_var( $ticket_url, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $ticket_url ) ) ) {
			return new \WP_Error( 'invalid_booking_deal', __( 'The ticket URL must be a valid HTTP or HTTPS URL.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$terms = $document['additional_terms'];
		if ( null !== $terms && ! is_string( $terms ) ) {
			return new \WP_Error( 'invalid_booking_deal', __( 'Additional terms must be a string or null.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return array(
			'version'                    => 1,
			'type'                       => $type,
			'guarantee_cents'            => $document['guarantee_cents'],
			'revenue_share_basis_points' => $document['revenue_share_basis_points'],
			'revenue_share_basis'        => $basis,
			'currency'                   => $currency,
			'capacity'                   => $document['capacity'],
			'advance_ticket_price_cents' => $document['advance_ticket_price_cents'],
			'door_ticket_price_cents'    => $document['door_ticket_price_cents'],
			'ticket_fee_cents'           => $document['ticket_fee_cents'],
			'tickets_on_sale_at'         => $on_sale,
			'ticket_url'                 => $ticket_url,
			'additional_terms'           => null === $terms || '' === sanitize_textarea_field( $terms ) ? null : mb_substr( sanitize_textarea_field( $terms ), 0, 10000 ),
		);
	}

	/** Compare JSON-like documents without treating object key order as data. */
	public static function documents_equal( $left, $right ): bool {
		return self::canonical_document( $left ) === self::canonical_document( $right );
	}

	private function replace_document( int $booking_id, int $expected_version, int $actor_id, array $statuses, string $column, string $key, array $document, string $kind, bool $reject_confirmed ) {
		$encoded = $this->encode( $document, $key );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}
		return $this->mutate(
			$booking_id,
			$expected_version,
			$actor_id,
			$statuses,
			array( $column => $encoded ),
			$kind,
			function ( array $current ) use ( $reject_confirmed ) {
				return $reject_confirmed && null !== $current['confirmed_deal']
					? new \WP_Error( 'booking_deal_already_confirmed', __( 'Confirmed deal terms are frozen.', 'extrachill-events' ), array( 'status' => 409 ) )
					: array();
			}
		);
	}

	private function mutate( int $booking_id, int $expected_version, int $actor_id, array $statuses, array $changes, string $kind, callable $validate ) {
		$current = $this->bookings->get( $booking_id );
		if ( ! is_array( $current ) ) {
			return is_wp_error( $current ) ? $current : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
		}
		$allowed = $this->authorization->authorize( $actor_id, $current['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		$started = $this->begin_authorized( $current['venue_term_id'], $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$current = $this->bookings->get( $booking_id );
		if ( ! is_array( $current ) ) {
			return $this->rollback( is_wp_error( $current ) ? $current : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) ) );
		}
		if ( (int) $current['version'] !== $expected_version ) {
			return $this->rollback(
				new \WP_Error(
					'booking_version_conflict',
					__( 'The booking changed since it was read.', 'extrachill-events' ),
					array(
						'status'          => 409,
						'current_version' => $current['version'],
					)
				)
			);
		}
		if ( ! in_array( $current['status'], $statuses, true ) ) {
			return $this->rollback(
				new \WP_Error(
					'booking_mutation_status_forbidden',
					__( 'This booking mutation is not allowed in the current status.', 'extrachill-events' ),
					array(
						'status'         => 409,
						'booking_status' => $current['status'],
					)
				)
			);
		}
		$next = $current;
		foreach ( $changes as $column => $value ) {
			$key          = substr( $column, -8 ) === '_payload' ? substr( $column, 0, -8 ) : $column;
			$next[ $key ] = substr( $column, -8 ) === '_payload' ? json_decode( $value, true ) : $value;
		}
		$validation = $validate( $current, $next );
		if ( is_wp_error( $validation ) ) {
			return $this->rollback( $validation );
		}
		$changed = array();
		foreach ( $changes as $column => $value ) {
			$key = substr( $column, -8 ) === '_payload' ? substr( $column, 0, -8 ) : $column;
			if ( ! self::documents_equal( $current[ $key ], $next[ $key ] ) ) {
				$changed[ $column ] = $value;
			}
		}
		if ( empty( $changed ) ) {
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : $current;
		}
		if ( 'intake_corrected' === $kind ) {
			$validation['changed_fields'] = array_map(
				static function ( string $column ): string {
					return 'intake_payload' === $column ? 'intake' : $column;
				},
				array_keys( $changed )
			);
		}
		global $wpdb;
		$set    = array();
		$values = array();
		foreach ( $changed as $column => $value ) {
			$set[] = null === $value ? "{$column} = NULL" : "{$column} = %s";
			if ( null !== $value ) {
				$values[] = $value;
			}
		}
		$now      = gmdate( 'Y-m-d H:i:s' );
		$set[]    = 'version = version + 1';
		$set[]    = 'updated_at = %s';
		$values[] = $now;
		$values[] = $booking_id;
		$values[] = $expected_version;
		$table    = BookingSchema::bookings_table();
		$result   = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE id = %d AND version = %d', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal columns determine the prepared value count.
		if ( 1 !== $result ) {
			return $this->rollback( false === $result ? new \WP_Error( 'booking_mutation_update_failed', __( 'The booking mutation could not be saved.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) : new \WP_Error( 'booking_version_conflict', __( 'The booking changed since it was read.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		$before = array();
		$after  = array();
		foreach ( $changed as $column => $value ) {
			$key            = substr( $column, -8 ) === '_payload' ? substr( $column, 0, -8 ) : $column;
			$before[ $key ] = $current[ $key ];
			$after[ $key ]  = $next[ $key ];
		}
		$event = $this->activity->append(
			array(
				'booking_id' => $booking_id,
				'kind'       => $kind,
				'actor_type' => 'user',
				'actor_id'   => $actor_id,
				'payload'    => array_merge(
					(array) $validation,
					array(
						'before'  => $before,
						'after'   => $after,
						'version' => $expected_version + 1,
					)
				),
			)
		);
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $this->bookings->get( $booking_id );
	}

	private function begin_authorized( int $venue_id, int $actor_id ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate boundary.
			return new \WP_Error( 'booking_mutation_transaction_start_failed', __( 'The booking mutation transaction could not start.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		$this->transaction_active = true;
		$table                    = BookingSchema::memberships_table();
		$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $venue_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact venue authority lock.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback( new \WP_Error( 'booking_mutation_authorization_lock_failed', __( 'Venue booking authority could not be locked.', 'extrachill-events' ) ) );
		}
		$allowed = $this->authorization->authorize( $actor_id, $venue_id, VenueAuthorization::ACTION_ACCESS_VENUE );
		return true === $allowed ? true : $this->rollback( is_wp_error( $allowed ) ? $allowed : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) ) );
	}

	private function encode( array $data, string $field ) {
		$json = wp_json_encode(
			array(
				'version' => 1,
				'data'    => $data,
			)
		);
		return false === $json ? new \WP_Error(
			'booking_payload_encode_failed',
			__( 'Booking payload JSON encoding failed.', 'extrachill-events' ),
			array(
				'field'      => $field,
				'json_error' => json_last_error_msg(),
			)
		) : $json;
	}

	private function datetime( $value, string $field ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return self::valid_datetime( $value ) ? $value : new \WP_Error(
			'invalid_booking_datetime',
			__( 'Booking datetimes must use UTC Y-m-d H:i:s format.', 'extrachill-events' ),
			array(
				'field'  => $field,
				'status' => 400,
			)
		);
	}

	private static function valid_datetime( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	private static function strict_integer( $value, int $minimum, ?int $maximum = null ): bool {
		return is_int( $value ) && $value >= $minimum && ( null === $maximum || $value <= $maximum );
	}

	private static function canonical_document( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonical_document( $item );
		}
		return $value;
	}

	private function commit() {
		global $wpdb;
		$result                   = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate boundary.
		$this->transaction_active = false;
		return false === $result ? new \WP_Error( 'booking_mutation_transaction_commit_uncertain', __( 'The booking mutation transaction outcome could not be confirmed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) : true;
	}

	private function rollback( \WP_Error $cause ) {
		global $wpdb;
		if ( ! $this->transaction_active ) {
			return $cause;
		}
		$result                   = $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate boundary.
		$this->transaction_active = false;
		return false === $result ? new \WP_Error(
			'booking_mutation_transaction_rollback_failed',
			__( 'The booking mutation transaction could not be rolled back.', 'extrachill-events' ),
			array(
				'cause'          => $cause->get_error_code(),
				'database_error' => $wpdb->last_error,
			)
		) : $cause;
	}
}
