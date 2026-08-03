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

	public const META_KEY                    = '_extrachill_booking_config';
	public const HISTORY_META_KEY            = '_extrachill_booking_config_history';
	public const VERSION                     = 4;
	public const PUBLIC_INTAKE_VERSION       = 3;
	public const PREVIOUS_VERSION            = 2;
	public const LEGACY_VERSION              = 1;
	public const BOOKING_GUIDE_VERSION       = 1;
	public const CORRESPONDENCE_VERSION      = 1;
	public const TEMPLATE_VERSION            = 1;
	public const REMINDER_POLICY_VERSION     = 1;
	public const CORRESPONDENCE_TEMPLATES    = array( 'operator_message', 'follow_up', 'hold_expiring', 'inquiry_receipt', 'date_filled' );
	public const CORRESPONDENCE_VARIABLES    = array( 'artist_name', 'booking_id', 'contact_name', 'requested_date', 'venue_name' );
	public const CONSENT_VERSION             = 1;
	public const HOLD_TTL_MAX_MINUTES        = 20160;
	public const SOCIAL_MARKETING_ACTION     = 'datamachine-socials/cross-post';
	public const NEWSLETTER_MARKETING_ACTION = 'extrachill-newsletter/canonical-post-campaign';

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
		$normalized = $this->normalize( $stored );
		if ( ! is_wp_error( $normalized ) && array_key_exists( 'cc_address', (array) ( $stored['correspondence'] ?? array() ) ) ) {
			$repaired = $stored;
			unset( $repaired['correspondence']['cc_address'] );
			update_term_meta( $venue_term_id, self::META_KEY, $repaired, $stored );
		}
		return $normalized;
	}

	/**
	 * Return the validated fields safe for the public inquiry block.
	 *
	 * @param int $venue_term_id Canonical venue term ID.
	 * @return array|\WP_Error
	 */
	public function get_public_projection( int $venue_term_id ) {
		$venue = $this->venue( $venue_term_id );
		if ( is_wp_error( $venue ) ) {
			return $venue;
		}

		$stored = get_term_meta( $venue_term_id, self::META_KEY, true );
		if ( ! is_array( $stored ) || ! in_array( $stored['version'] ?? null, array( self::PUBLIC_INTAKE_VERSION, self::VERSION ), true ) ) {
			return new \WP_Error( 'invalid_booking_public_config', __( 'The public venue booking configuration is unavailable.', 'extrachill-events' ) );
		}

		$revision = $stored['revision'] ?? null;
		if ( ! is_array( $stored['intake'] ?? null ) || 1 !== ( $stored['intake']['version'] ?? null ) ) {
			return new \WP_Error( 'invalid_booking_public_config', __( 'The public venue booking configuration is unavailable.', 'extrachill-events' ) );
		}
		if ( ! is_int( $revision ) || $revision < 0 ) {
			return new \WP_Error( 'invalid_booking_public_config', __( 'The public venue booking configuration is unavailable.', 'extrachill-events' ) );
		}

		$fields        = $this->normalize_intake_fields( $stored['intake']['fields'] ?? null );
		$presentation  = $this->normalize_intake_presentation( $stored['intake']['presentation'] ?? array() );
		$requirements  = $this->normalize_public_requirements( $stored['public_requirements'] ?? null );
		$consent       = $this->normalize_consent( $stored['consent'] ?? null );
		$spaces        = $this->normalize_spaces( $stored['spaces'] ?? null );
		$booking_guide = $this->normalize_booking_guide( $stored['booking_guide'] ?? array() );
		foreach ( array( $fields, $presentation, $requirements, $consent, $spaces, $booking_guide ) as $section ) {
			if ( is_wp_error( $section ) ) {
				return $section;
			}
		}

		return array(
			'enabled'             => ! empty( $stored['enabled'] ),
			'revision'            => $revision,
			'fields'              => $fields,
			'presentation'        => $presentation,
			'public_requirements' => $requirements,
			'consent'             => $consent,
			'spaces'              => $spaces,
			'booking_guide'       => array(
				'version' => self::BOOKING_GUIDE_VERSION,
				'entries' => array_values(
					array_filter(
						$booking_guide['entries'],
						static function ( array $entry ): bool {
							return 'public' === $entry['visibility'];
						}
					)
				),
			),
		);
	}

	/**
	 * Return only guide data and venue context for an authorized operator.
	 *
	 * @param int $venue_term_id Canonical venue term ID.
	 * @return array|\WP_Error
	 */
	public function get_guide_context( int $venue_term_id ) {
		$venue = $this->venue( $venue_term_id );
		if ( is_wp_error( $venue ) ) {
			return $venue;
		}
		$config = $this->get( $venue_term_id );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		return array(
			'venue_term_id'   => $venue_term_id,
			'venue_name'      => $venue->name,
			'config_revision' => $config['revision'],
			'guide_version'   => $config['booking_guide']['version'],
			'entries'         => $config['booking_guide']['entries'],
		);
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
		$versions = $this->validate_correspondence_versions( $current['correspondence'], $normalized['correspondence'] );
		if ( is_wp_error( $versions ) ) {
			$this->rollback();
			return $versions;
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
		$version = $config['version'] ?? self::LEGACY_VERSION;
		if ( ! is_int( $version ) || ! in_array( $version, array( self::LEGACY_VERSION, self::PREVIOUS_VERSION, self::PUBLIC_INTAKE_VERSION, self::VERSION ), true ) ) {
			return new \WP_Error( 'booking_config_version_unsupported', __( 'The venue booking configuration version is unsupported.', 'extrachill-events' ), array( 'version' => $version ) );
		}
		$version_three_fields = array( 'public_requirements', 'consent', 'marketing_triggers' );
		if ( $version < self::PUBLIC_INTAKE_VERSION && array_intersect( $version_three_fields, array_keys( $config ) ) ) {
			return new \WP_Error( 'booking_config_version_field_invalid', __( 'Public intake and marketing settings require venue booking configuration version 3.', 'extrachill-events' ), array( 'version' => $version ) );
		}
		if ( $version >= self::PUBLIC_INTAKE_VERSION && ! array_key_exists( 'marketing_triggers', $config ) ) {
			return new \WP_Error( 'booking_config_version_field_invalid', __( 'Venue booking configuration version 3 requires marketing triggers.', 'extrachill-events' ), array( 'version' => $version ) );
		}
		if ( $version < self::VERSION && array_key_exists( 'booking_guide', $config ) ) {
			return new \WP_Error( 'booking_config_version_field_invalid', __( 'Booking guide settings require venue booking configuration version 4.', 'extrachill-events' ), array( 'version' => $version ) );
		}
		if ( self::VERSION === $version && ! array_key_exists( 'booking_guide', $config ) ) {
			return new \WP_Error( 'booking_config_version_field_invalid', __( 'Venue booking configuration version 4 requires a booking guide.', 'extrachill-events' ), array( 'version' => $version ) );
		}
		$intake_version         = $config['intake']['version'] ?? 1;
		$deal_version           = $config['default_deal']['version'] ?? 1;
		$correspondence_version = $config['correspondence']['version'] ?? self::CORRESPONDENCE_VERSION;
		if ( ! is_int( $intake_version ) || ! is_int( $deal_version ) || ! is_int( $correspondence_version ) || 1 !== $intake_version || 1 !== $deal_version || self::CORRESPONDENCE_VERSION !== $correspondence_version ) {
			return new \WP_Error(
				'booking_config_section_version_unsupported',
				__( 'A venue booking configuration section version is unsupported.', 'extrachill-events' ),
				array(
					'intake_version'         => $intake_version,
					'deal_version'           => $deal_version,
					'correspondence_version' => $correspondence_version,
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
		$requirements = $this->normalize_public_requirements( $config['public_requirements'] ?? array() );
		if ( is_wp_error( $requirements ) ) {
			return $requirements;
		}
		$consent = $this->normalize_consent( $config['consent'] ?? array() );
		if ( is_wp_error( $consent ) ) {
			return $consent;
		}
		$booking_guide = $this->normalize_booking_guide( $config['booking_guide'] ?? array() );
		if ( is_wp_error( $booking_guide ) ) {
			return $booking_guide;
		}
		$channels = $this->normalize_channels( $config['marketing_channels'] ?? array() );
		if ( is_wp_error( $channels ) ) {
			return $channels;
		}
		$triggers = $this->normalize_marketing_triggers( $version >= self::PUBLIC_INTAKE_VERSION ? $config['marketing_triggers'] : array(), $channels );
		if ( is_wp_error( $triggers ) ) {
			return $triggers;
		}
		$correspondence = $this->normalize_correspondence( $config['correspondence'] ?? array() );
		if ( is_wp_error( $correspondence ) ) {
			return $correspondence;
		}
		$hold_ttl = isset( $config['hold_ttl_minutes'] ) ? (int) $config['hold_ttl_minutes'] : 1440;
		if ( $hold_ttl < 5 || $hold_ttl > self::HOLD_TTL_MAX_MINUTES ) {
			return new \WP_Error( 'invalid_booking_hold_ttl', __( 'Hold TTL must be between 5 minutes and 14 days.', 'extrachill-events' ) );
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
				'version'      => 1,
				'fields'       => $fields,
				'presentation' => $this->normalize_intake_presentation( $config['intake']['presentation'] ?? array() ),
			),
			'public_requirements'       => $requirements,
			'consent'                   => $consent,
			'booking_guide'             => $booking_guide,
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
			'marketing_triggers'        => $triggers,
			'hold_ttl_minutes'          => $hold_ttl,
			'correspondence'            => $correspondence,
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
				'version'      => 1,
				'fields'       => array(),
				'presentation' => $this->normalize_intake_presentation( array() ),
			),
			'public_requirements'       => array(),
			'consent'                   => array(
				'id'       => 'booking-privacy',
				'version'  => self::CONSENT_VERSION,
				'label'    => __( 'I agree that this venue may use these details to review and respond to my booking inquiry.', 'extrachill-events' ),
				'required' => true,
			),
			'booking_guide'             => array(
				'version' => self::BOOKING_GUIDE_VERSION,
				'entries' => array(),
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
			'marketing_triggers'        => array(),
			'hold_ttl_minutes'          => 1440,
			'correspondence'            => array(
				'version'           => self::CORRESPONDENCE_VERSION,
				'booking_address'   => null,
				'cc_address'        => null,
				'from_name'         => 'Extra Chill Bookings',
				'footer'            => 'Powered by Extra Chill',
				'variables'         => $this->variable_schema(),
				'templates'         => array(
					'operator_message' => array(
						'version' => self::TEMPLATE_VERSION,
						'subject' => 'Booking update for {{artist_name}}',
						'body'    => "A message from the Extra Chill booking team:\n\n{{message}}",
					),
					'follow_up'        => array(
						'version' => self::TEMPLATE_VERSION,
						'subject' => 'Following up: {{artist_name}} at {{venue_name}}',
						'body'    => "Following up on your booking inquiry for {{venue_name}}:\n\n{{message}}",
					),
					'hold_expiring'    => array(
						'version' => self::TEMPLATE_VERSION,
						'subject' => 'Booking hold update for {{artist_name}}',
						'body'    => "A reminder about your booking hold at {{venue_name}}:\n\n{{message}}",
					),
					'inquiry_receipt'  => array(
						'version' => self::TEMPLATE_VERSION,
						'subject' => 'Booking inquiry received: {{artist_name}} at {{venue_name}} - {{requested_date}}',
						'body'    => "Hello {{contact_name}},\n\n{{message}}",
					),
					'date_filled'      => array(
						'version' => self::TEMPLATE_VERSION,
						'subject' => 'Booking date update: {{artist_name}} at {{venue_name}} - {{requested_date}}',
						'body'    => "Hello {{contact_name}},\n\n{{message}}",
					),
				),
				'reminder_policies' => array(
					'follow_up'     => array(
						'version'           => self::REMINDER_POLICY_VERSION,
						'enabled'           => false,
						'delay_minutes'     => 2880,
						'expected_statuses' => array( 'submitted', 'under_review', 'needs_info' ),
					),
					'hold_expiring' => array(
						'version'           => self::REMINDER_POLICY_VERSION,
						'enabled'           => false,
						'delay_minutes'     => 60,
						'expected_statuses' => array( 'held' ),
					),
				),
			),
		);
	}

	/** Resolve a configured template and policy with one bounded rendering pass. */
	public function prepare_correspondence( int $venue_term_id, string $template_key, ?int $expected_template_version, array $variables ) {
		$config = $this->get( $venue_term_id );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$template_key = sanitize_key( $template_key );
		$template     = $config['correspondence']['templates'][ $template_key ] ?? null;
		if ( ! is_array( $template ) ) {
			return new \WP_Error( 'booking_correspondence_template_invalid', __( 'The correspondence template is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( null !== $expected_template_version && $expected_template_version !== $template['version'] ) {
			return new \WP_Error(
				'booking_correspondence_template_version_conflict',
				__( 'The correspondence template changed since it was read.', 'extrachill-events' ),
				array(
					'status'          => 409,
					'current_version' => $template['version'],
				)
			);
		}
		$normalized = $this->normalize_preview_variables( $variables );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$render = static function ( string $source ) use ( $normalized ): string {
			return (string) preg_replace_callback(
				'/\{\{([a-z_]+)\}\}/',
				static function ( array $placeholder_match ) use ( $normalized ): string {
					return $normalized[ $placeholder_match[1] ] ?? '';
				},
				$source
			);
		};
		$result = array(
			'template'         => $template_key,
			'template_version' => $template['version'],
			'config_revision'  => $config['revision'],
			'subject'          => mb_substr( sanitize_text_field( $render( $template['subject'] ) ), 0, 200 ),
			'body'             => $render( $template['body'] ) . "\n\n" . $config['correspondence']['footer'],
			'booking_address'  => $config['correspondence']['booking_address'],
			'from_name'        => $config['correspondence']['from_name'],
			'reminder_policy'  => $config['correspondence']['reminder_policies'][ $template_key ] ?? null,
		);
		return $result;
	}

	/** Render the same allowlisted output used by actual delivery. */
	public function preview( int $venue_term_id, string $template_key, int $expected_template_version, array $variables ) {
		$result = $this->prepare_correspondence( $venue_term_id, $template_key, $expected_template_version, $variables );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		unset( $result['booking_address'], $result['from_name'], $result['reminder_policy'] );
		return $result;
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
		$types      = array( 'text', 'textarea', 'email', 'phone', 'number', 'select', 'checkbox', 'url', 'url_list' );
		foreach ( $fields as $field ) {
			$key   = mb_substr( sanitize_key( (string) ( $field['key'] ?? '' ) ), 0, 64 );
			$type  = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
			$label = mb_substr( sanitize_text_field( (string) ( $field['label'] ?? $key ) ), 0, 191 );
			if ( '' === $key || '' === $label || isset( $seen[ $key ] ) || ! in_array( $type, $types, true ) ) {
				return new \WP_Error( 'invalid_booking_intake_field', __( 'Each intake field needs a unique normalized key and supported type.', 'extrachill-events' ) );
			}
			$seen[ $key ] = true;
			$visible_when = null;
			if ( null !== ( $field['visible_when'] ?? null ) ) {
				$condition       = is_array( $field['visible_when'] ) ? $field['visible_when'] : array();
				$condition_field = mb_substr( sanitize_key( (string) ( $condition['field'] ?? '' ) ), 0, 64 );
				$condition_value = mb_substr( sanitize_text_field( (string) ( $condition['value'] ?? '' ) ), 0, 191 );
				if ( '' === $condition_field || '' === $condition_value || ! isset( $seen[ $condition_field ] ) || $condition_field === $key ) {
					return new \WP_Error( 'invalid_booking_intake_condition', __( 'Conditional intake fields must depend on an earlier configured field and value.', 'extrachill-events' ) );
				}
				$visible_when = array(
					'field' => $condition_field,
					'value' => $condition_value,
				);
			}
			$normalized[] = array(
				'key'          => $key,
				'label'        => $label,
				'type'         => $type,
				'required'     => ! empty( $field['required'] ),
				'options'      => array_map(
					static function ( $option ): string {
						return mb_substr( sanitize_text_field( $option ), 0, 191 );
					},
					array_values( array_slice( (array) ( $field['options'] ?? array() ), 0, 100 ) )
				),
				'visible_when' => $visible_when,
			);
		}
		return $normalized;
	}

	/**
	 * Normalize configurable labels for the stable built-in inquiry fields.
	 *
	 * @param mixed $presentation Proposed presentation values.
	 */
	private function normalize_intake_presentation( $presentation ): array {
		$defaults     = array(
			'artist_name_label'   => __( 'Artist or project name', 'extrachill-events' ),
			'contact_name_label'  => __( 'Contact name', 'extrachill-events' ),
			'contact_email_label' => __( 'Contact email', 'extrachill-events' ),
			'contact_phone_label' => __( 'Contact phone', 'extrachill-events' ),
			'message_label'       => __( 'Additional performance details', 'extrachill-events' ),
			'message_help'        => __( 'Share routing, scheduling, and anything else the venue should know.', 'extrachill-events' ),
		);
		$presentation = is_array( $presentation ) ? $presentation : array();
		foreach ( $defaults as $key => $default ) {
			$value            = mb_substr( sanitize_text_field( (string) ( $presentation[ $key ] ?? $default ) ), 0, 500 );
			$defaults[ $key ] = '' === $value ? $default : $value;
		}
		return $defaults;
	}

	/** Normalize public, non-operational requirements shown before inquiry intake. */
	private function normalize_public_requirements( $requirements ) {
		if ( ! is_array( $requirements ) || count( $requirements ) > 20 ) {
			return new \WP_Error( 'invalid_booking_public_requirements', __( 'Public booking requirements must contain at most 20 items.', 'extrachill-events' ) );
		}
		$normalized = array();
		foreach ( $requirements as $requirement ) {
			$value = mb_substr( sanitize_text_field( (string) $requirement ), 0, 500 );
			if ( '' === $value ) {
				return new \WP_Error( 'invalid_booking_public_requirement', __( 'Public booking requirements must be plain text.', 'extrachill-events' ) );
			}
			$normalized[] = $value;
		}
		return $normalized;
	}

	/**
	 * Normalize ordered public and operator booking-guide entries.
	 *
	 * @param mixed $guide Proposed booking guide.
	 * @return array|\WP_Error
	 */
	private function normalize_booking_guide( $guide ) {
		if ( empty( $guide ) ) {
			return array(
				'version' => self::BOOKING_GUIDE_VERSION,
				'entries' => array(),
			);
		}
		if ( ! is_array( $guide ) || self::BOOKING_GUIDE_VERSION !== ( $guide['version'] ?? null ) || ! is_array( $guide['entries'] ?? null ) || count( $guide['entries'] ) > 50 ) {
			return new \WP_Error( 'invalid_booking_guide', __( 'The booking guide must use a supported version and contain at most 50 entries.', 'extrachill-events' ) );
		}

		$entries = array();
		$seen    = array();
		foreach ( $guide['entries'] as $entry ) {
			$key        = mb_substr( sanitize_key( (string) ( $entry['key'] ?? '' ) ), 0, 64 );
			$title      = mb_substr( sanitize_text_field( (string) ( $entry['title'] ?? '' ) ), 0, 191 );
			$body       = mb_substr( sanitize_textarea_field( (string) ( $entry['body'] ?? '' ) ), 0, 5000 );
			$visibility = sanitize_key( (string) ( $entry['visibility'] ?? '' ) );
			if ( '' === $key || '' === $title || '' === $body || isset( $seen[ $key ] ) || ! in_array( $visibility, array( 'public', 'operator' ), true ) ) {
				return new \WP_Error( 'invalid_booking_guide_entry', __( 'Each booking guide entry needs a unique key, title, answer, and supported visibility.', 'extrachill-events' ) );
			}
			$seen[ $key ] = true;
			$entries[]    = compact( 'key', 'title', 'body', 'visibility' );
		}

		return array(
			'version' => self::BOOKING_GUIDE_VERSION,
			'entries' => $entries,
		);
	}

	/** Normalize the versioned public consent descriptor. */
	private function normalize_consent( $consent ) {
		$defaults = $this->defaults()['consent'];
		$consent  = is_array( $consent ) ? array_merge( $defaults, $consent ) : array();
		$id       = mb_substr( sanitize_key( (string) ( $consent['id'] ?? '' ) ), 0, 64 );
		$version  = $consent['version'] ?? null;
		$label    = mb_substr( sanitize_text_field( (string) ( $consent['label'] ?? '' ) ), 0, 500 );
		if ( '' === $id || ! is_int( $version ) || $version < 1 || '' === $label ) {
			return new \WP_Error( 'invalid_booking_consent', __( 'Booking consent requires a stable identifier, version, and public label.', 'extrachill-events' ) );
		}
		return array(
			'id'       => $id,
			'version'  => $version,
			'label'    => $label,
			'required' => ! empty( $consent['required'] ),
		);
	}

	private function normalize_channels( $channels ) {
		if ( ! is_array( $channels ) || count( $channels ) > 20 ) {
			return new \WP_Error( 'invalid_booking_marketing_channels', __( 'Marketing channels must be an array of at most 20 keys.', 'extrachill-events' ) );
		}
		$normalized = array();
		foreach ( $channels as $channel ) {
			$key = mb_substr( sanitize_key( (string) $channel ), 0, 32 );
			if ( '' === $key || in_array( $key, $normalized, true ) ) {
				return new \WP_Error( 'invalid_booking_marketing_channel', __( 'Marketing channel keys must be unique after normalization.', 'extrachill-events' ) );
			}
			$normalized[] = $key;
		}
		return $normalized;
	}

	/**
	 * Normalize event-driven references to owner-registered delegated actions.
	 *
	 * @param mixed $triggers         Proposed trigger configuration.
	 * @param array $enabled_channels Enabled venue channel keys.
	 * @return array|\WP_Error Normalized triggers.
	 */
	private function normalize_marketing_triggers( $triggers, array $enabled_channels ) {
		if ( ! is_array( $triggers ) || count( $triggers ) > 20 ) {
			return new \WP_Error( 'invalid_booking_marketing_triggers', __( 'Marketing triggers must be an array of at most 20 items.', 'extrachill-events' ) );
		}
		$normalized    = array();
		$seen          = array();
		$used_channels = array();
		foreach ( $triggers as $trigger ) {
			$key = mb_substr( sanitize_key( (string) ( $trigger['key'] ?? '' ) ), 0, 32 );
			if ( '' === $key || isset( $seen[ $key ] ) || 'event_converted' !== ( $trigger['event'] ?? '' ) || ! is_array( $trigger['channels'] ?? null ) || count( $trigger['channels'] ) > 20 ) {
				return new \WP_Error( 'invalid_booking_marketing_trigger', __( 'Each marketing trigger needs a unique key, a supported event, and bounded channels.', 'extrachill-events' ) );
			}
			$trigger_channels = array();
			$channel_seen     = array();
			foreach ( $trigger['channels'] as $channel ) {
				if ( ! is_array( $channel ) || array_diff( array_keys( $channel ), array( 'key', 'action', 'approval', 'delay_seconds', 'social', 'newsletter' ) ) ) {
					return new \WP_Error( 'invalid_booking_marketing_trigger_channel', __( 'Marketing trigger channels contain unsupported fields.', 'extrachill-events' ) );
				}
				$channel_key = mb_substr( sanitize_key( (string) ( $channel['key'] ?? '' ) ), 0, 32 );
				$action      = is_string( $channel['action'] ?? null ) ? $channel['action'] : '';
				$approval    = sanitize_key( (string) ( $channel['approval'] ?? 'required' ) );
				$delay       = $channel['delay_seconds'] ?? 0;
				if ( '' === $channel_key || isset( $channel_seen[ $channel_key ] ) || isset( $used_channels[ $channel_key ] ) || ! in_array( $channel_key, $enabled_channels, true ) || ! in_array( $action, array( self::SOCIAL_MARKETING_ACTION, self::NEWSLETTER_MARKETING_ACTION ), true ) || ! in_array( $approval, array( 'direct', 'required' ), true ) || ! is_int( $delay ) || $delay < 0 || $delay > 31536000 ) {
					return new \WP_Error( 'invalid_booking_marketing_trigger_channel', __( 'Marketing trigger channels must use an enabled channel, supported owner action, approval policy, and valid delay.', 'extrachill-events' ) );
				}
				$social     = null;
				$newsletter = null;
				if ( self::SOCIAL_MARKETING_ACTION === $action ) {
					if ( null !== ( $channel['newsletter'] ?? null ) ) {
						return new \WP_Error( 'invalid_booking_marketing_social', __( 'Social marketing cannot include newsletter policy.', 'extrachill-events' ) );
					}
					$raw = is_array( $channel['social'] ?? null ) ? $channel['social'] : array();
					if ( array_diff( array_keys( $raw ), array( 'channels', 'caption', 'media_kind', 'asset_refs' ) ) || ! is_array( $raw['channels'] ?? null ) || ! array_is_list( $raw['channels'] ) || ! is_array( $raw['asset_refs'] ?? null ) || ! array_is_list( $raw['asset_refs'] ) ) {
						return new \WP_Error( 'invalid_booking_marketing_social', __( 'The delegated social policy is invalid.', 'extrachill-events' ) );
					}
					$platforms  = array_values( array_unique( $raw['channels'] ) );
					$allowed    = array( 'bluesky', 'facebook', 'instagram', 'pinterest', 'threads', 'twitter' );
					$media_kind = is_string( $raw['media_kind'] ?? null ) ? $raw['media_kind'] : '';
					$caption    = is_string( $raw['caption'] ?? null ) ? $raw['caption'] : '';
					$assets     = $raw['asset_refs'];
					if ( empty( $platforms ) || count( $platforms ) > 6 || array_diff( $platforms, $allowed ) || ! in_array( $media_kind, array( 'image', 'carousel', 'reel', 'story' ), true ) || sanitize_textarea_field( $caption ) !== $caption || mb_strlen( $caption ) > 2200 || count( $assets ) > 11 || count( $assets ) !== count( array_unique( $assets ) ) ) {
						return new \WP_Error( 'invalid_booking_marketing_social', __( 'The delegated social policy is invalid.', 'extrachill-events' ) );
					}
					$asset_count = count( $assets );
					if ( ( 'image' === $media_kind && 1 !== $asset_count ) || ( 'carousel' === $media_kind && ( $asset_count < 2 || $asset_count > 10 ) ) || ( 'reel' === $media_kind && ( $asset_count < 1 || $asset_count > 2 ) ) || ( 'story' === $media_kind && 1 !== $asset_count ) ) {
						return new \WP_Error( 'invalid_booking_marketing_assets', __( 'Marketing assets do not match the selected media kind.', 'extrachill-events' ) );
					}
					if ( in_array( $media_kind, array( 'reel', 'story' ), true ) && array_diff( $platforms, array( 'instagram' ) ) ) {
						return new \WP_Error( 'invalid_booking_marketing_social', __( 'The selected channels do not support this media kind.', 'extrachill-events' ) );
					}
					if ( 'carousel' === $media_kind && ( array_diff( $platforms, array( 'instagram', 'twitter' ) ) || ( in_array( 'twitter', $platforms, true ) && $asset_count > 4 ) ) ) {
						return new \WP_Error( 'invalid_booking_marketing_social', __( 'The selected channels do not support this carousel.', 'extrachill-events' ) );
					}
					foreach ( $assets as $asset_ref ) {
						if ( ! is_int( $asset_ref ) || $asset_ref < 1 ) {
							return new \WP_Error( 'invalid_booking_marketing_assets', __( 'Marketing assets must be positive attachment references.', 'extrachill-events' ) );
						}
					}
					sort( $platforms );
					$social = array(
						'channels'   => $platforms,
						'caption'    => $caption,
						'media_kind' => $media_kind,
						'asset_refs' => $assets,
					);
				} else {
					if ( null !== ( $channel['social'] ?? null ) ) {
						return new \WP_Error( 'invalid_booking_marketing_newsletter', __( 'Newsletter marketing cannot include social policy.', 'extrachill-events' ) );
					}
					$raw = is_array( $channel['newsletter'] ?? null ) ? $channel['newsletter'] : array();
					if ( array( 'policy' ) !== array_keys( $raw ) || 'canonical-post-draft' !== ( $raw['policy'] ?? null ) ) {
						return new \WP_Error( 'invalid_booking_marketing_newsletter', __( 'The delegated newsletter policy is invalid.', 'extrachill-events' ) );
					}
					$newsletter = array( 'policy' => 'canonical-post-draft' );
				}
				$channel_seen[ $channel_key ]  = true;
				$used_channels[ $channel_key ] = true;
				$trigger_channels[]            = array(
					'key'           => $channel_key,
					'action'        => $action,
					'approval'      => $approval,
					'delay_seconds' => $delay,
					'social'        => $social,
					'newsletter'    => $newsletter,
				);
			}
			$seen[ $key ] = true;
			$normalized[] = array(
				'key'      => $key,
				'event'    => 'event_converted',
				'channels' => $trigger_channels,
			);
		}
		return $normalized;
	}

	/** Normalize the complete correspondence configuration section. */
	private function normalize_correspondence( $correspondence ) {
		if ( ! is_array( $correspondence ) ) {
			return new \WP_Error( 'invalid_booking_correspondence', __( 'Booking correspondence configuration must be an object.', 'extrachill-events' ) );
		}
		$defaults = $this->defaults()['correspondence'];
		$address  = sanitize_email( (string) ( $correspondence['booking_address'] ?? '' ) );
		if ( '' !== (string) ( $correspondence['booking_address'] ?? '' ) && '' === $address ) {
			return new \WP_Error( 'invalid_booking_correspondence_address', __( 'The booking correspondence address is invalid.', 'extrachill-events' ) );
		}
		$from_name = sanitize_text_field( (string) ( $correspondence['from_name'] ?? $defaults['from_name'] ) );
		$footer    = sanitize_textarea_field( (string) ( $correspondence['footer'] ?? $defaults['footer'] ) );
		if ( '' === $from_name || mb_strlen( $from_name ) > 100 || preg_match( '/[\r\n]/', $from_name ) || '' === $footer || mb_strlen( $footer ) > 500 ) {
			return new \WP_Error( 'invalid_booking_correspondence_identity', __( 'The booking correspondence sender or footer is invalid.', 'extrachill-events' ) );
		}
		$templates       = array();
		$provided        = is_array( $correspondence['templates'] ?? null ) ? $correspondence['templates'] : array();
		$legacy_subjects = array(
			'follow_up'       => 'Following up on booking {{booking_id}}',
			'inquiry_receipt' => 'Booking inquiry received at {{venue_name}} [{{booking_id}}]',
			'date_filled'     => 'Booking date update from {{venue_name}}',
		);
		foreach ( self::CORRESPONDENCE_TEMPLATES as $key ) {
			$template = is_array( $provided[ $key ] ?? null ) ? $provided[ $key ] : $defaults['templates'][ $key ];
			$version  = $template['version'] ?? self::TEMPLATE_VERSION;
			$subject  = trim( (string) ( $template['subject'] ?? '' ) );
			$body     = trim( (string) ( $template['body'] ?? '' ) );
			if ( ( $legacy_subjects[ $key ] ?? null ) === $subject ) {
				$subject = $defaults['templates'][ $key ]['subject'];
			}
			if ( ! is_int( $version ) || $version < 1 || $version > 1000000 || '' === $subject || mb_strlen( $subject ) > 200 || preg_match( '/[\r\n]/', $subject ) || '' === $body || mb_strlen( $body ) > 10000 ) {
				return new \WP_Error( 'invalid_booking_correspondence_template', __( 'A booking correspondence template is invalid.', 'extrachill-events' ), array( 'template' => $key ) );
			}
			$placeholders         = $this->template_placeholders( $subject . "\n" . $body );
			$without_placeholders = preg_replace( '/\{\{[a-z_]+\}\}/', '', $subject . "\n" . $body );
			if ( array_diff( $placeholders, array_merge( self::CORRESPONDENCE_VARIABLES, array( 'message' ) ) ) || false !== strpos( (string) $without_placeholders, '{{' ) || false !== strpos( (string) $without_placeholders, '}}' ) ) {
				return new \WP_Error( 'invalid_booking_correspondence_variable', __( 'A template uses an unsupported correspondence variable.', 'extrachill-events' ), array( 'template' => $key ) );
			}
			$templates[ $key ] = array(
				'version' => $version,
				'subject' => sanitize_text_field( $subject ),
				'body'    => sanitize_textarea_field( $body ),
			);
		}
		if ( array_diff( array_keys( $provided ), self::CORRESPONDENCE_TEMPLATES ) ) {
			return new \WP_Error( 'invalid_booking_correspondence_template', __( 'The booking correspondence template key is unsupported.', 'extrachill-events' ) );
		}

		$policies          = array();
		$provided_policies = is_array( $correspondence['reminder_policies'] ?? null ) ? $correspondence['reminder_policies'] : array();
		foreach ( array( 'follow_up', 'hold_expiring' ) as $key ) {
			$policy   = is_array( $provided_policies[ $key ] ?? null ) ? $provided_policies[ $key ] : $defaults['reminder_policies'][ $key ];
			$version  = $policy['version'] ?? self::REMINDER_POLICY_VERSION;
			$delay    = (int) ( $policy['delay_minutes'] ?? 0 );
			$statuses = array_values( array_unique( array_map( 'sanitize_key', (array) ( $policy['expected_statuses'] ?? array() ) ) ) );
			if ( ! is_int( $version ) || $version < 1 || $version > 1000000 || $delay < 5 || $delay > 10080 || empty( $statuses ) || array_diff( $statuses, BookingRepository::STATUSES ) ) {
				return new \WP_Error( 'invalid_booking_reminder_policy', __( 'A booking reminder policy is invalid.', 'extrachill-events' ), array( 'policy' => $key ) );
			}
			$policies[ $key ] = array(
				'version'           => $version,
				'enabled'           => ! empty( $policy['enabled'] ),
				'delay_minutes'     => $delay,
				'expected_statuses' => $statuses,
			);
		}
		if ( array_diff( array_keys( $provided_policies ), array( 'follow_up', 'hold_expiring' ) ) ) {
			return new \WP_Error( 'invalid_booking_reminder_policy', __( 'The booking reminder policy key is unsupported.', 'extrachill-events' ) );
		}
		return array(
			'version'           => self::CORRESPONDENCE_VERSION,
			'booking_address'   => '' === $address ? null : $address,
			'cc_address'        => null,
			'from_name'         => $from_name,
			'footer'            => $footer,
			'variables'         => $this->variable_schema(),
			'templates'         => $templates,
			'reminder_policies' => $policies,
		);
	}

	/** Return the immutable allowlist exposed to configuration consumers. */
	private function variable_schema(): array {
		return array(
			array(
				'key'        => 'artist_name',
				'type'       => 'string',
				'max_length' => 255,
			),
			array(
				'key'        => 'booking_id',
				'type'       => 'string',
				'max_length' => 36,
			),
			array(
				'key'        => 'contact_name',
				'type'       => 'string',
				'max_length' => 255,
			),
			array(
				'key'        => 'requested_date',
				'type'       => 'string',
				'max_length' => 32,
			),
			array(
				'key'        => 'venue_name',
				'type'       => 'string',
				'max_length' => 255,
			),
			array(
				'key'        => 'message',
				'type'       => 'text',
				'max_length' => 10000,
			),
		);
	}

	/** Normalize caller-provided preview values without recursive expansion. */
	private function normalize_preview_variables( array $variables ) {
		$schema  = array_column( $this->variable_schema(), null, 'key' );
		$unknown = array_diff( array_keys( $variables ), array_keys( $schema ) );
		if ( $unknown ) {
			return new \WP_Error( 'invalid_booking_correspondence_variable', __( 'The preview contains an unsupported correspondence variable.', 'extrachill-events' ), array( 'variables' => array_values( $unknown ) ) );
		}
		$normalized = array();
		foreach ( $schema as $key => $definition ) {
			$value              = (string) ( $variables[ $key ] ?? '' );
			$normalized[ $key ] = 'text' === $definition['type'] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			if ( 'text' !== $definition['type'] ) {
				$normalized[ $key ] = (string) preg_replace( '/[\r\n]+/', ' ', $normalized[ $key ] );
			}
			$normalized[ $key ] = mb_substr( $normalized[ $key ], 0, $definition['max_length'] );
		}
		return $normalized;
	}

	/** Extract normalized placeholders from a template. */
	private function template_placeholders( string $template ): array {
		preg_match_all( '/\{\{([a-z_]+)\}\}/', $template, $matches );
		return array_values( array_unique( $matches[1] ?? array() ) );
	}

	/** Require content and policy changes to advance exactly one item version. */
	private function validate_correspondence_versions( array $current, array $next ) {
		foreach ( array( 'templates', 'reminder_policies' ) as $section ) {
			foreach ( $next[ $section ] as $key => $item ) {
				$prior         = $current[ $section ][ $key ];
				$prior_content = $prior;
				$next_content  = $item;
				unset( $prior_content['version'], $next_content['version'] );
				$expected = $prior_content === $next_content ? $prior['version'] : $prior['version'] + 1;
				if ( $item['version'] !== $expected ) {
					return new \WP_Error(
						'booking_correspondence_item_version_conflict',
						__( 'A correspondence template or reminder policy has a stale version.', 'extrachill-events' ),
						array(
							'status'           => 409,
							'section'          => $section,
							'item'             => $key,
							'current_version'  => $prior['version'],
							'expected_version' => $expected,
						)
					);
				}
			}
		}
		return true;
	}
	private function nullable_text( $value, int $length ): ?string {
		$value = sanitize_text_field( (string) $value );
		return '' === $value ? null : mb_substr( $value, 0, $length );
	}

	/** Return top-level settings changed by the replacement document. */
	private function changed_fields( array $current, array $next ): array {
		$fields  = array( 'enabled', 'intake', 'public_requirements', 'consent', 'booking_guide', 'spaces', 'default_deal', 'ticket_provider_reference', 'marketing_channels', 'marketing_triggers', 'hold_ttl_minutes', 'correspondence' );
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
