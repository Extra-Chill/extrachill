<?php
/**
 * Venue booking configuration abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueBookingConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers venue-scoped configuration read and replacement operations. */
class VenueBookingConfigAbilities {

	private static bool $registered = false;

	/** @var VenueAuthorization */
	private $authorization;

	/** @var VenueBookingConfig */
	private $config;

	public function __construct( ?VenueBookingConfig $config = null, ?VenueAuthorization $authorization = null ) {
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->config        = $config ? $config : new VenueBookingConfig( $this->authorization );
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register get and update contracts. */
	public function register(): void {
		$venue_property = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Events-site venue term ID.', 'extrachill-events' ),
		);

		wp_register_ability(
			'extrachill/get-venue-booking-config',
			array(
				'label'               => __( 'Get Venue Booking Configuration', 'extrachill-events' ),
				'description'         => __( 'Read the complete booking configuration for an authorized venue.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'venue_term_id' => $venue_property ),
					'required'             => array( 'venue_term_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->config_schema( true ),
				'execute_callback'    => array( $this, 'get_config' ),
				'permission_callback' => array( $this, 'can_access_venue' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/update-venue-booking-config',
			array(
				'label'               => __( 'Update Venue Booking Configuration', 'extrachill-events' ),
				'description'         => __( 'Atomically replace the complete booking configuration at an expected revision.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'venue_term_id'     => $venue_property,
						'expected_revision' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'config'            => $this->config_schema( false ),
					),
					'required'             => array( 'venue_term_id', 'expected_revision', 'config' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->config_schema( true ),
				'execute_callback'    => array( $this, 'update_config' ),
				'permission_callback' => array( $this, 'can_access_venue' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => false,
						'destructive' => true,
					),
				),
			)
		);
	}

	/** Authorize the WordPress feature gate and exact active venue scope. */
	public function can_access_venue( array $input ) {
		return $this->authorization->authorize(
			get_current_user_id(),
			absint( $input['venue_term_id'] ?? 0 ),
			VenueAuthorization::ACTION_ACCESS_VENUE
		);
	}

	public function get_config( array $input ) {
		return $this->config->get( absint( $input['venue_term_id'] ?? 0 ) );
	}

	public function update_config( array $input ) {
		return $this->config->update(
			absint( $input['venue_term_id'] ?? 0 ),
			(array) ( $input['config'] ?? array() ),
			(int) ( $input['expected_revision'] ?? -1 ),
			get_current_user_id()
		);
	}

	/** Return the complete settings schema, optionally with read metadata. */
	private function config_schema( bool $include_metadata ): array {
		$field_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'key'      => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 64,
				),
				'label'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 191,
				),
				'type'     => array(
					'type' => 'string',
					'enum' => array( 'text', 'textarea', 'email', 'phone', 'number', 'select', 'checkbox', 'url' ),
				),
				'required' => array( 'type' => 'boolean' ),
				'options'  => array(
					'type'     => 'array',
					'maxItems' => 100,
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 191,
					),
				),
			),
			'required'             => array( 'key', 'label', 'type', 'required', 'options' ),
			'additionalProperties' => false,
		);
		$properties   = array(
			'version'                   => array(
				'type' => 'integer',
				'enum' => array( VenueBookingConfig::VERSION ),
			),
			'enabled'                   => array( 'type' => 'boolean' ),
			'intake'                    => array(
				'type'                 => 'object',
				'properties'           => array(
					'version' => array(
						'type' => 'integer',
						'enum' => array( 1 ),
					),
					'fields'  => array(
						'type'     => 'array',
						'maxItems' => 50,
						'items'    => $field_schema,
					),
				),
				'required'             => array( 'version', 'fields' ),
				'additionalProperties' => false,
			),
			'spaces'                    => array(
				'type'     => 'array',
				'maxItems' => 50,
				'items'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'key'        => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 64,
						),
						'name'       => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 191,
						),
						'is_default' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'key', 'name', 'is_default' ),
					'additionalProperties' => false,
				),
			),
			'default_deal'              => array(
				'type'                 => 'object',
				'properties'           => array(
					'version'                    => array(
						'type' => 'integer',
						'enum' => array( 1 ),
					),
					'type'                       => array(
						'type'      => 'string',
						'maxLength' => 32,
					),
					'guarantee_cents'            => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'revenue_share_basis_points' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 10000,
					),
					'revenue_share_basis'        => array(
						'type' => 'string',
						'enum' => array( 'gross_ticket_sales', 'net_ticket_sales', 'door_receipts' ),
					),
					'currency'                   => array(
						'type'    => 'string',
						'pattern' => '^[A-Z]{3}$',
					),
				),
				'required'             => array( 'version', 'type', 'guarantee_cents', 'revenue_share_basis_points', 'revenue_share_basis', 'currency' ),
				'additionalProperties' => false,
			),
			'ticket_provider_reference' => array(
				'type'      => array( 'string', 'null' ),
				'maxLength' => 191,
			),
			'marketing_channels'        => array(
				'type'        => 'array',
				'maxItems'    => 20,
				'uniqueItems' => true,
				'items'       => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 64,
				),
			),
			'hold_ttl_minutes'          => array(
				'type'    => 'integer',
				'minimum' => 5,
				'maximum' => 10080,
			),
		);
		$required     = array( 'version', 'enabled', 'intake', 'spaces', 'default_deal', 'ticket_provider_reference', 'marketing_channels', 'hold_ttl_minutes' );
		if ( $include_metadata ) {
			$properties['revision']           = array(
				'type'    => 'integer',
				'minimum' => 0,
			);
			$properties['updated_by_user_id'] = array(
				'type'    => array( 'integer', 'null' ),
				'minimum' => 1,
			);
			$properties['updated_at']         = array( 'type' => array( 'string', 'null' ) );
			$required[]                       = 'revision';
			$required[]                       = 'updated_by_user_id';
			$required[]                       = 'updated_at';
		}

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}
}
