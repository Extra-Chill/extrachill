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

	public const META_KEY = '_extrachill_booking_config';
	public const VERSION  = 1;

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

	/** Validate and replace a venue's bounded booking config document. */
	public function save( int $venue_term_id, array $config ) {
		$venue = $this->venue( $venue_term_id );
		if ( is_wp_error( $venue ) ) {
			return $venue;
		}
		$normalized = $this->normalize( $config );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$result = update_term_meta( $venue_term_id, self::META_KEY, $normalized );
		if ( false === $result && get_term_meta( $venue_term_id, self::META_KEY, true ) !== $normalized ) {
			return new \WP_Error( 'booking_config_save_failed', __( 'The venue booking configuration could not be saved.', 'extrachill-events' ) );
		}
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

		return array(
			'version'                   => self::VERSION,
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
			$key  = mb_substr( sanitize_key( (string) ( $field['key'] ?? '' ) ), 0, 64 );
			$type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
			if ( '' === $key || isset( $seen[ $key ] ) || ! in_array( $type, $types, true ) ) {
				return new \WP_Error( 'invalid_booking_intake_field', __( 'Each intake field needs a unique normalized key and supported type.', 'extrachill-events' ) );
			}
			$seen[ $key ] = true;
			$normalized[] = array(
				'key'      => $key,
				'label'    => mb_substr( sanitize_text_field( (string) ( $field['label'] ?? $key ) ), 0, 191 ),
				'type'     => $type,
				'required' => ! empty( $field['required'] ),
				'options'  => array_values( array_slice( array_map( 'sanitize_text_field', (array) ( $field['options'] ?? array() ) ), 0, 100 ) ),
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
}
