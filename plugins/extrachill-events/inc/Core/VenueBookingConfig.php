<?php
/**
 * Venue booking configuration service.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates one versioned termmeta document on canonical venue terms. */
class VenueBookingConfig {

	public const META_KEY         = '_extrachill_booking_config';
	public const HISTORY_META_KEY = '_extrachill_booking_config_history';
	public const VERSION          = 1;

	/** @var VenueAuthorization */
	private $authorization;

	public function __construct( ?VenueAuthorization $authorization = null ) {
		$this->authorization = $authorization;
	}

	/** Return validated config for a canonical venue term. */
	public function get( int $venue_term_id ) {
		$venue = $this->venue( $venue_term_id );
		if ( is_wp_error( $venue ) ) {
			return $venue;
		}
		$stored = get_term_meta( $venue_term_id, self::META_KEY, true );
		if ( '' === $stored || null === $stored ) {
			return $this->defaults();
		}
		if ( ! is_array( $stored ) ) {
			return new \WP_Error( 'invalid_booking_config_document', __( 'Stored venue booking configuration is malformed.', 'extrachill-events' ) );
		}
		return $this->normalize( $stored );
	}

	/** Atomically replace a venue config at one expected revision. */
	public function update( int $venue_term_id, array $config, int $expected_revision, int $actor_user_id ) {
		global $wpdb;

		$venue = $this->venue( $venue_term_id );
		if ( is_wp_error( $venue ) ) {
			return $venue;
		}
		if ( $expected_revision < 0 ) {
			return new \WP_Error( 'invalid_booking_config_revision', __( 'The expected configuration revision must be zero or greater.', 'extrachill-events' ) );
		}
		$normalized = $this->normalize( $config );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes authorization, revision, config, and audit writes.
			return new \WP_Error( 'booking_config_transaction_failed', __( 'The venue booking configuration transaction could not start.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}

		$memberships = BookingSchema::memberships_table();
		$wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$memberships} WHERE venue_term_id = %d AND user_id = %d FOR UPDATE", $venue_term_id, $actor_user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks the actor's exact venue scope before reauthorization.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback_error( 'booking_config_authorization_lock_failed', __( 'Venue configuration authority could not be locked.', 'extrachill-events' ) );
		}
		$authorization = $this->authorization ? $this->authorization : new VenueAuthorization();
		$allowed       = $authorization->authorize( $actor_user_id, $venue_term_id, VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( is_wp_error( $allowed ) ) {
			$this->rollback();
			return $allowed;
		}

		$locked_term = $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d FOR UPDATE", $venue_term_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes config revisions before term-meta writes.
		if ( '' !== (string) $wpdb->last_error || $venue_term_id !== (int) $locked_term ) {
			return $this->rollback_error( 'booking_config_lock_failed', __( 'The venue booking configuration could not be locked.', 'extrachill-events' ) );
		}

		$config_meta = $wpdb->get_row( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1 FOR UPDATE", $venue_term_id, self::META_KEY ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the committed config directly while holding the venue lock, bypassing shared metadata cache.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback_error( 'booking_config_read_failed', __( 'The venue booking configuration could not be read.', 'extrachill-events' ) );
		}
		$stored  = is_array( $config_meta ) ? maybe_unserialize( $config_meta['meta_value'] ) : '';
		$current = '' === $stored || null === $stored ? $this->defaults() : ( is_array( $stored ) ? $this->normalize( $stored ) : new \WP_Error( 'invalid_booking_config_document', __( 'Stored venue booking configuration is malformed.', 'extrachill-events' ) ) );
		if ( is_wp_error( $current ) ) {
			$this->rollback();
			return $current;
		}
		if ( $current['revision'] !== $expected_revision ) {
			$this->rollback();
			return new \WP_Error(
				'booking_config_revision_conflict',
				__( 'The venue booking configuration changed since it was read.', 'extrachill-events' ),
				array(
					'status'           => 409,
					'current_revision' => $current['revision'],
				)
			);
		}

		$changed_fields = $this->changed_fields( $current, $normalized );
		if ( empty( $changed_fields ) ) {
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases locks after a validated no-op replacement.
				wp_cache_delete( $venue_term_id, 'term_meta' );
				return new \WP_Error( 'booking_config_commit_uncertain', __( 'The venue booking configuration transaction outcome could not be confirmed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
			}
			wp_cache_delete( $venue_term_id, 'term_meta' );
			return $current;
		}
		$normalized['revision']           = $current['revision'] + 1;
		$normalized['updated_by_user_id'] = $actor_user_id;
		$normalized['updated_at']         = gmdate( 'Y-m-d H:i:s' );
		$serialized_config                = maybe_serialize( $normalized );
		$config_added                     = ! is_array( $config_meta );
		if ( $config_added ) {
			$result         = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private transactional term-meta write.
				$wpdb->termmeta,
				array(
					'term_id'    => $venue_term_id,
					'meta_key'   => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Canonical private config key.
					'meta_value' => $serialized_config, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Canonical serialized config document.
				),
				array( '%d', '%s', '%s' )
			);
			$config_meta_id = (int) $wpdb->insert_id;
		} else {
			$config_meta_id = (int) $config_meta['meta_id'];
			$result         = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private transactional term-meta write.
				$wpdb->termmeta,
				array( 'meta_value' => $serialized_config ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Canonical serialized config document.
				array( 'meta_id' => $config_meta_id ),
				array( '%s' ),
				array( '%d' )
			);
		}
		if ( false === $result || $result < 1 || $config_meta_id < 1 ) {
			return $this->rollback_error( 'booking_config_save_failed', __( 'The venue booking configuration could not be saved.', 'extrachill-events' ), $venue_term_id );
		}
		$verified_config = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_id = %d FOR UPDATE", $config_meta_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies the uncommitted row directly without publishing it through cache.
		if ( '' !== (string) $wpdb->last_error || $serialized_config !== $verified_config ) {
			return $this->rollback_error( 'booking_config_save_failed', __( 'The venue booking configuration could not be verified.', 'extrachill-events' ), $venue_term_id );
		}

		$audit            = array(
			'version'           => 1,
			'previous_revision' => $current['revision'],
			'revision'          => $normalized['revision'],
			'actor_user_id'     => $actor_user_id,
			'changed_fields'    => $changed_fields,
			'occurred_at'       => $normalized['updated_at'],
		);
		$serialized_audit = maybe_serialize( $audit );
		$audit_result     = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Audit must commit atomically with its configuration revision.
			$wpdb->termmeta,
			array(
				'term_id'    => $venue_term_id,
				'meta_key'   => self::HISTORY_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Canonical private audit key.
				'meta_value' => $serialized_audit, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Canonical serialized audit document.
			),
			array( '%d', '%s', '%s' )
		);
		$audit_meta_id    = (int) $wpdb->insert_id;
		if ( false === $audit_result || $audit_result < 1 || $audit_meta_id < 1 ) {
			return $this->rollback_error( 'booking_config_audit_failed', __( 'The venue booking configuration audit record could not be saved.', 'extrachill-events' ), $venue_term_id );
		}
		$verified_audit = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_id = %d FOR UPDATE", $audit_meta_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies durable audit persistence before commit.
		if ( '' !== (string) $wpdb->last_error || $serialized_audit !== $verified_audit ) {
			return $this->rollback_error( 'booking_config_audit_failed', __( 'The venue booking configuration audit record could not be verified.', 'extrachill-events' ), $venue_term_id );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits config and audit as one unit.
			wp_cache_delete( $venue_term_id, 'term_meta' );
			return new \WP_Error( 'booking_config_commit_uncertain', __( 'The venue booking configuration transaction outcome could not be confirmed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		wp_cache_delete( $venue_term_id, 'term_meta' );

		do_action( $config_added ? 'added_term_meta' : 'updated_term_meta', $config_meta_id, $venue_term_id, self::META_KEY, $normalized );
		do_action( 'added_term_meta', $audit_meta_id, $venue_term_id, self::HISTORY_META_KEY, $audit );
		do_action( 'extrachill_events_venue_booking_config_updated', $venue_term_id, $actor_user_id, $current, $normalized, $audit );
		return $normalized;
	}

	/** Normalize and validate the complete versioned contract. */
	public function normalize( array $config ) {
		$version = $config['version'] ?? self::VERSION;
		if ( ! is_int( $version ) || self::VERSION !== $version ) {
			return new \WP_Error( 'booking_config_version_unsupported', __( 'The venue booking configuration version is unsupported.', 'extrachill-events' ), array( 'version' => $version ) );
		}
		$intake_version = $config['intake']['version'] ?? 1;
		$deal_version   = $config['default_deal']['version'] ?? 1;
		if ( ! is_int( $intake_version ) || ! is_int( $deal_version ) || 1 !== $intake_version || 1 !== $deal_version ) {
			return new \WP_Error(
				'booking_config_section_version_unsupported',
				__( 'A venue booking configuration section version is unsupported.', 'extrachill-events' ),
				array(
					'intake_version' => $intake_version,
					'deal_version'   => $deal_version,
				)
			);
		}

		$basis_points = isset( $config['default_deal']['revenue_share_basis_points'] ) ? (int) $config['default_deal']['revenue_share_basis_points'] : 0;
		if ( $basis_points < 0 || $basis_points > 10000 ) {
			return new \WP_Error( 'invalid_booking_revenue_share', __( 'Revenue share basis points must be between 0 and 10000.', 'extrachill-events' ) );
		}
		$basis         = sanitize_key( (string) ( $config['default_deal']['revenue_share_basis'] ?? 'gross_ticket_sales' ) );
		$allowed_basis = array( 'gross_ticket_sales', 'net_ticket_sales', 'door_receipts' );
		if ( ! in_array( $basis, $allowed_basis, true ) ) {
			return new \WP_Error( 'invalid_booking_revenue_basis', __( 'The revenue share basis is invalid.', 'extrachill-events' ) );
		}
		$currency = strtoupper( sanitize_text_field( (string) ( $config['default_deal']['currency'] ?? 'USD' ) ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return new \WP_Error( 'invalid_booking_currency', __( 'Deal currency must be a three-letter uppercase code.', 'extrachill-events' ) );
		}

		$spaces = $this->normalize_spaces( $config['spaces'] ?? array() );
		if ( is_wp_error( $spaces ) ) {
			return $spaces;
		}
		$fields = $this->normalize_intake_fields( $config['intake']['fields'] ?? array() );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$channels = $this->normalize_channels( $config['marketing_channels'] ?? array() );
		if ( is_wp_error( $channels ) ) {
			return $channels;
		}
		$hold_ttl = isset( $config['hold_ttl_minutes'] ) ? (int) $config['hold_ttl_minutes'] : 1440;
		if ( $hold_ttl < 5 || $hold_ttl > 10080 ) {
			return new \WP_Error( 'invalid_booking_hold_ttl', __( 'Hold TTL must be between 5 minutes and 7 days.', 'extrachill-events' ) );
		}

		$revision = $config['revision'] ?? 0;
		if ( ! is_int( $revision ) || $revision < 0 ) {
			return new \WP_Error( 'invalid_booking_config_revision', __( 'The venue booking configuration revision is invalid.', 'extrachill-events' ) );
		}
		$updated_by_user_id = $config['updated_by_user_id'] ?? null;
		if ( null !== $updated_by_user_id && ( ! is_int( $updated_by_user_id ) || $updated_by_user_id < 1 ) ) {
			return new \WP_Error( 'invalid_booking_config_actor', __( 'The venue booking configuration actor is invalid.', 'extrachill-events' ) );
		}
		$updated_at = $config['updated_at'] ?? null;
		if ( null !== $updated_at && ( ! is_string( $updated_at ) || ! $this->valid_datetime( $updated_at ) ) ) {
			return new \WP_Error( 'invalid_booking_config_updated_at', __( 'The venue booking configuration timestamp is invalid.', 'extrachill-events' ) );
		}

		return array(
			'version'                   => self::VERSION,
			'revision'                  => $revision,
			'updated_by_user_id'        => $updated_by_user_id,
			'updated_at'                => $updated_at,
			'enabled'                   => ! empty( $config['enabled'] ),
			'intake'                    => array(
				'version' => 1,
				'fields'  => $fields,
			),
			'spaces'                    => $spaces,
			'default_deal'              => array(
				'version'                    => 1,
				'type'                       => mb_substr( sanitize_key( (string) ( $config['default_deal']['type'] ?? 'custom' ) ), 0, 32 ),
				'guarantee_cents'            => max( 0, (int) ( $config['default_deal']['guarantee_cents'] ?? 0 ) ),
				'revenue_share_basis_points' => $basis_points,
				'revenue_share_basis'        => $basis,
				'currency'                   => $currency,
			),
			'ticket_provider_reference' => $this->nullable_text( $config['ticket_provider_reference'] ?? null, 191 ),
			'marketing_channels'        => $channels,
			'hold_ttl_minutes'          => $hold_ttl,
		);
	}

	/** Default disabled venue contract. */
	public function defaults(): array {
		return array(
			'version'                   => self::VERSION,
			'revision'                  => 0,
			'updated_by_user_id'        => null,
			'updated_at'                => null,
			'enabled'                   => false,
			'intake'                    => array(
				'version' => 1,
				'fields'  => array(),
			),
			'spaces'                    => array(),
			'default_deal'              => array(
				'version'                    => 1,
				'type'                       => 'custom',
				'guarantee_cents'            => 0,
				'revenue_share_basis_points' => 0,
				'revenue_share_basis'        => 'gross_ticket_sales',
				'currency'                   => 'USD',
			),
			'ticket_provider_reference' => null,
			'marketing_channels'        => array(),
			'hold_ttl_minutes'          => 1440,
		);
	}

	private function venue( int $venue_term_id ) {
		$venue = $venue_term_id > 0 ? get_term( $venue_term_id, 'venue' ) : null;
		return $venue && ! is_wp_error( $venue ) && 'venue' === $venue->taxonomy
			? $venue
			: new \WP_Error( 'invalid_booking_config_venue', __( 'A valid Events venue term is required.', 'extrachill-events' ) );
	}

	private function normalize_spaces( $spaces ) {
		if ( ! is_array( $spaces ) || count( $spaces ) > 50 ) {
			return new \WP_Error( 'invalid_booking_spaces', __( 'Venue spaces must be an array of at most 50 items.', 'extrachill-events' ) );
		}
		$normalized = array();
		$seen       = array();
		$default    = false;
		foreach ( $spaces as $space ) {
			$key  = mb_substr( sanitize_key( (string) ( $space['key'] ?? '' ) ), 0, 64 );
			$name = mb_substr( sanitize_text_field( (string) ( $space['name'] ?? '' ) ), 0, 191 );
			if ( '' === $key || '' === $name || isset( $seen[ $key ] ) ) {
				return new \WP_Error( 'invalid_booking_space', __( 'Each venue space needs a unique normalized key and name.', 'extrachill-events' ) );
			}
			$is_default = ! empty( $space['is_default'] );
			if ( $is_default && $default ) {
				return new \WP_Error( 'multiple_default_booking_spaces', __( 'Only one venue space may be the default.', 'extrachill-events' ) );
			}
			$default      = $default || $is_default;
			$seen[ $key ] = true;
			$normalized[] = array(
				'key'        => $key,
				'name'       => $name,
				'is_default' => $is_default,
			);
		}
		if ( ! empty( $normalized ) && ! $default ) {
			$normalized[0]['is_default'] = true;
		}
		return $normalized;
	}

	private function normalize_intake_fields( $fields ) {
		if ( ! is_array( $fields ) || count( $fields ) > 50 ) {
			return new \WP_Error( 'invalid_booking_intake', __( 'Intake fields must be an array of at most 50 items.', 'extrachill-events' ) );
		}
		$normalized = array();
		$seen       = array();
		$types      = array( 'text', 'textarea', 'email', 'phone', 'number', 'select', 'checkbox', 'url' );
		foreach ( $fields as $field ) {
			$key   = mb_substr( sanitize_key( (string) ( $field['key'] ?? '' ) ), 0, 64 );
			$type  = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
			$label = mb_substr( sanitize_text_field( (string) ( $field['label'] ?? $key ) ), 0, 191 );
			if ( '' === $key || '' === $label || isset( $seen[ $key ] ) || ! in_array( $type, $types, true ) ) {
				return new \WP_Error( 'invalid_booking_intake_field', __( 'Each intake field needs a unique normalized key and supported type.', 'extrachill-events' ) );
			}
			$seen[ $key ] = true;
			$normalized[] = array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'required' => ! empty( $field['required'] ),
				'options'  => array_map(
					static function ( $option ): string {
						return mb_substr( sanitize_text_field( $option ), 0, 191 );
					},
					array_values( array_slice( (array) ( $field['options'] ?? array() ), 0, 100 ) )
				),
			);
		}
		return $normalized;
	}

	private function normalize_channels( $channels ) {
		if ( ! is_array( $channels ) || count( $channels ) > 20 ) {
			return new \WP_Error( 'invalid_booking_marketing_channels', __( 'Marketing channels must be an array of at most 20 keys.', 'extrachill-events' ) );
		}
		$normalized = array();
		foreach ( $channels as $channel ) {
			$key = mb_substr( sanitize_key( (string) $channel ), 0, 64 );
			if ( '' === $key || in_array( $key, $normalized, true ) ) {
				return new \WP_Error( 'invalid_booking_marketing_channel', __( 'Marketing channel keys must be unique after normalization.', 'extrachill-events' ) );
			}
			$normalized[] = $key;
		}
		return $normalized;
	}

	private function nullable_text( $value, int $length ): ?string {
		$value = sanitize_text_field( (string) $value );
		return '' === $value ? null : mb_substr( $value, 0, $length );
	}

	/** Return top-level settings changed by the replacement document. */
	private function changed_fields( array $current, array $next ): array {
		$fields  = array( 'enabled', 'intake', 'spaces', 'default_deal', 'ticket_provider_reference', 'marketing_channels', 'hold_ttl_minutes' );
		$changed = array();
		foreach ( $fields as $field ) {
			if ( $current[ $field ] !== $next[ $field ] ) {
				$changed[] = $field;
			}
		}
		return $changed;
	}

	private function valid_datetime( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	private function rollback_error( string $code, string $message, int $venue_term_id = 0 ): \WP_Error {
		global $wpdb;
		$database_error = $wpdb->last_error;
		$this->rollback();
		if ( $venue_term_id > 0 ) {
			wp_cache_delete( $venue_term_id, 'term_meta' );
		}
		return new \WP_Error( $code, $message, array( 'database_error' => $database_error ) );
	}

	private function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back config and audit together.
	}
}
