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
						'config'            => $this->config_schema( false, true ),
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

		wp_register_ability(
			'extrachill/get-venue-booking-guide',
			array(
				'label'               => __( 'Get Venue Booking Guide', 'extrachill-events' ),
				'description'         => __( 'Read current public and operator booking-guide entries for an authorized venue. Answers must use only returned entries; missing information is unavailable.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'venue_term_id' => $venue_property ),
					'required'             => array( 'venue_term_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->guide_context_schema(),
				'execute_callback'    => array( $this, 'get_guide' ),
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
			'extrachill/preview-booking-correspondence-template',
			array(
				'label'               => __( 'Preview Booking Correspondence Template', 'extrachill-events' ),
				'description'         => __( 'Render one authorized venue template with allowlisted plain-text variables.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'venue_term_id'             => $venue_property,
						'template'                  => array(
							'type' => 'string',
							'enum' => VenueBookingConfig::CORRESPONDENCE_TEMPLATES,
						),
						'expected_template_version' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'variables'                 => array(
							'type'                 => 'object',
							'properties'           => array_fill_keys(
								array_merge( VenueBookingConfig::CORRESPONDENCE_VARIABLES, array( 'message' ) ),
								array(
									'type'      => 'string',
									'maxLength' => 10000,
								)
							),
							'additionalProperties' => false,
						),
					),
					'required'             => array( 'venue_term_id', 'template', 'expected_template_version', 'variables' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->preview_schema(),
				'execute_callback'    => array( $this, 'preview_template' ),
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

	/**
	 * Read the narrow booking-guide grounding contract.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function get_guide( array $input ) {
		return $this->config->get_guide_context( absint( $input['venue_term_id'] ?? 0 ) );
	}

	public function update_config( array $input ) {
		return $this->config->update(
			absint( $input['venue_term_id'] ?? 0 ),
			(array) ( $input['config'] ?? array() ),
			(int) ( $input['expected_revision'] ?? -1 ),
			get_current_user_id()
		);
	}

	/** Render one authorized correspondence preview. */
	public function preview_template( array $input ) {
		return $this->config->preview(
			absint( $input['venue_term_id'] ?? 0 ),
			(string) ( $input['template'] ?? '' ),
			(int) ( $input['expected_template_version'] ?? 0 ),
			(array) ( $input['variables'] ?? array() )
		);
	}

	/** Return the complete settings schema, optionally with read metadata. */
	private function config_schema( bool $include_metadata, bool $accept_legacy = false ): array {
		$field_schema        = array(
			'type'                 => 'object',
			'properties'           => array(
				'key'          => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 64,
				),
				'label'        => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 191,
				),
				'type'         => array(
					'type' => 'string',
					'enum' => array( 'text', 'textarea', 'email', 'phone', 'number', 'select', 'checkbox', 'url', 'url_list' ),
				),
				'required'     => array( 'type' => 'boolean' ),
				'options'      => array(
					'type'     => 'array',
					'maxItems' => 100,
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 191,
					),
				),
				'visible_when' => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'field' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 64,
						),
						'value' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 191,
						),
					),
					'required'             => array( 'field', 'value' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'key', 'label', 'type', 'required', 'options' ),
			'additionalProperties' => false,
		);
		$presentation_schema = array(
			'type'                 => 'object',
			'properties'           => array_fill_keys(
				array( 'artist_name_label', 'contact_name_label', 'contact_email_label', 'contact_phone_label', 'message_label', 'message_help' ),
				array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 500,
				)
			),
			'required'             => array( 'artist_name_label', 'contact_name_label', 'contact_email_label', 'contact_phone_label', 'message_label', 'message_help' ),
			'additionalProperties' => false,
		);
		$properties          = array(
			'version'                   => array(
				'type' => 'integer',
				'enum' => array( VenueBookingConfig::VERSION ),
			),
			'enabled'                   => array( 'type' => 'boolean' ),
			'intake'                    => array(
				'type'                 => 'object',
				'properties'           => array(
					'version'      => array(
						'type' => 'integer',
						'enum' => array( 1 ),
					),
					'fields'       => array(
						'type'     => 'array',
						'maxItems' => 50,
						'items'    => $field_schema,
					),
					'presentation' => $presentation_schema,
				),
				'required'             => array( 'version', 'fields', 'presentation' ),
				'additionalProperties' => false,
			),
			'public_requirements'       => array(
				'type'     => 'array',
				'maxItems' => 20,
				'items'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 500,
				),
			),
			'consent'                   => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'       => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 64,
					),
					'version'  => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'label'    => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 500,
					),
					'required' => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'id', 'version', 'label', 'required' ),
				'additionalProperties' => false,
			),
			'booking_guide'             => $this->booking_guide_schema(),
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
					'maxLength' => 32,
				),
			),
			'marketing_triggers'        => $this->marketing_triggers_schema(),
			'hold_ttl_minutes'          => array(
				'type'    => 'integer',
				'minimum' => 5,
				'maximum' => VenueBookingConfig::HOLD_TTL_MAX_MINUTES,
			),
			'correspondence'            => $this->correspondence_schema(),
		);
		$required            = array( 'version', 'enabled', 'intake', 'public_requirements', 'consent', 'booking_guide', 'spaces', 'default_deal', 'ticket_provider_reference', 'marketing_channels', 'marketing_triggers', 'hold_ttl_minutes', 'correspondence' );
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

		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
		if ( ! $accept_legacy ) {
			return $schema;
		}
		$public_intake                                  = $schema;
		$public_intake['properties']['version']['enum'] = array( VenueBookingConfig::PUBLIC_INTAKE_VERSION );
		$public_intake['required']                      = array_values( array_diff( $public_intake['required'], array( 'booking_guide' ) ) );
		unset( $public_intake['properties']['booking_guide'] );
		$previous                                     = $public_intake;
		$previous['properties']['version']['enum']    = array( VenueBookingConfig::PREVIOUS_VERSION );
		$previous['required']                         = array_values( array_diff( $previous['required'], array( 'public_requirements', 'consent', 'marketing_triggers' ) ) );
		$previous['properties']['intake']['required'] = array_values( array_diff( $previous['properties']['intake']['required'], array( 'presentation' ) ) );
		unset( $previous['properties']['public_requirements'], $previous['properties']['consent'], $previous['properties']['marketing_triggers'] );
		$legacy                                  = $previous;
		$legacy['properties']['version']['enum'] = array( VenueBookingConfig::LEGACY_VERSION );
		$legacy['required']                      = array_values( array_diff( $legacy['required'], array( 'correspondence' ) ) );
		unset( $legacy['properties']['correspondence'] );
		return array( 'oneOf' => array( $legacy, $previous, $public_intake, $schema ) );
	}

	/** Return the versioned ordered booking-guide schema. */
	private function booking_guide_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'version' => array(
					'type' => 'integer',
					'enum' => array( VenueBookingConfig::BOOKING_GUIDE_VERSION ),
				),
				'entries' => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => $this->guide_entry_schema(),
				),
			),
			'required'             => array( 'version', 'entries' ),
			'additionalProperties' => false,
		);
	}

	/** Return one strict guide entry schema. */
	private function guide_entry_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'key'        => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 64,
				),
				'title'      => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 191,
				),
				'body'       => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 5000,
				),
				'visibility' => array(
					'type' => 'string',
					'enum' => array( 'public', 'operator' ),
				),
			),
			'required'             => array( 'key', 'title', 'body', 'visibility' ),
			'additionalProperties' => false,
		);
	}

	/** Return the narrow Roadie grounding contract. */
	private function guide_context_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'venue_term_id'   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'venue_name'      => array( 'type' => 'string' ),
				'config_revision' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'guide_version'   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'entries'         => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => $this->guide_entry_schema(),
				),
			),
			'required'             => array( 'venue_term_id', 'venue_name', 'config_revision', 'guide_version', 'entries' ),
			'additionalProperties' => false,
		);
	}

	/** Return the strict correspondence configuration schema. */
	private function correspondence_schema(): array {
		$template = array(
			'type'                 => 'object',
			'properties'           => array(
				'version' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'subject' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'body'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 10000,
				),
			),
			'required'             => array( 'version', 'subject', 'body' ),
			'additionalProperties' => false,
		);
		$policy   = array(
			'type'                 => 'object',
			'properties'           => array(
				'version'           => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'enabled'           => array( 'type' => 'boolean' ),
				'delay_minutes'     => array(
					'type'    => 'integer',
					'minimum' => 5,
					'maximum' => 10080,
				),
				'expected_statuses' => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'items'       => array(
						'type' => 'string',
						'enum' => \ExtraChillEvents\Core\BookingRepository::STATUSES,
					),
				),
			),
			'required'             => array( 'version', 'enabled', 'delay_minutes', 'expected_statuses' ),
			'additionalProperties' => false,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'version'           => array(
					'type' => 'integer',
					'enum' => array( VenueBookingConfig::CORRESPONDENCE_VERSION ),
				),
				'booking_address'   => array(
					'type'   => array( 'string', 'null' ),
					'format' => 'email',
				),
				'variables'         => array(
					'type'     => 'array',
					'maxItems' => 5,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'key'        => array(
								'type' => 'string',
								'enum' => array_merge( VenueBookingConfig::CORRESPONDENCE_VARIABLES, array( 'message' ) ),
							),
							'type'       => array(
								'type' => 'string',
								'enum' => array( 'string', 'text' ),
							),
							'max_length' => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 10000,
							),
						),
						'required'             => array( 'key', 'type', 'max_length' ),
						'additionalProperties' => false,
					),
				),
				'templates'         => array(
					'type'                 => 'object',
					'properties'           => array_fill_keys( VenueBookingConfig::CORRESPONDENCE_TEMPLATES, $template ),
					'required'             => VenueBookingConfig::CORRESPONDENCE_TEMPLATES,
					'additionalProperties' => false,
				),
				'reminder_policies' => array(
					'type'                 => 'object',
					'properties'           => array(
						'follow_up'     => $policy,
						'hold_expiring' => $policy,
					),
					'required'             => array( 'follow_up', 'hold_expiring' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'version', 'booking_address', 'variables', 'templates', 'reminder_policies' ),
			'additionalProperties' => false,
		);
	}

	/** Return the allowlisted preview response schema. */
	private function preview_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'template'         => array(
					'type' => 'string',
					'enum' => VenueBookingConfig::CORRESPONDENCE_TEMPLATES,
				),
				'template_version' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'config_revision'  => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'subject'          => array( 'type' => 'string' ),
				'body'             => array( 'type' => 'string' ),
			),
			'required'             => array( 'template', 'template_version', 'config_revision', 'subject', 'body' ),
			'additionalProperties' => false,
		);
	}

	/** Return the event-driven marketing configuration contract. */
	private function marketing_triggers_schema(): array {
		return array(
			'type'     => 'array',
			'maxItems' => 20,
			'items'    => array(
				'type'                 => 'object',
				'properties'           => array(
					'key'      => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 32,
					),
					'event'    => array(
						'type' => 'string',
						'enum' => array( 'event_converted' ),
					),
					'channels' => array(
						'type'     => 'array',
						'maxItems' => 20,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'key'           => array(
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 32,
								),
								'action'        => array(
									'type' => 'string',
									'enum' => array(
										VenueBookingConfig::SOCIAL_MARKETING_ACTION,
										VenueBookingConfig::NEWSLETTER_MARKETING_ACTION,
									),
								),
								'approval'      => array(
									'type' => 'string',
									'enum' => array( 'direct', 'required' ),
								),
								'delay_seconds' => array(
									'type'    => 'integer',
									'minimum' => 0,
									'maximum' => 31536000,
								),
								'social'        => array(
									'type'                 => array( 'object', 'null' ),
									'properties'           => array(
										'channels'   => array(
											'type'        => 'array',
											'maxItems'    => 6,
											'uniqueItems' => true,
											'items'       => array(
												'type' => 'string',
												'enum' => array( 'bluesky', 'facebook', 'instagram', 'pinterest', 'threads', 'twitter' ),
											),
										),
										'caption'    => array(
											'type'      => 'string',
											'maxLength' => 2200,
										),
										'media_kind' => array(
											'type' => 'string',
											'enum' => array( 'image', 'carousel', 'reel', 'story' ),
										),
										'asset_refs' => array(
											'type'        => 'array',
											'maxItems'    => 11,
											'uniqueItems' => true,
											'items'       => array(
												'type'    => 'integer',
												'minimum' => 1,
											),
										),
									),
									'required'             => array( 'channels', 'caption', 'media_kind', 'asset_refs' ),
									'additionalProperties' => false,
								),
								'newsletter'    => array(
									'type'                 => array( 'object', 'null' ),
									'properties'           => array(
										'policy' => array(
											'type' => 'string',
											'enum' => array( 'canonical-post-draft' ),
										),
									),
									'required'             => array( 'policy' ),
									'additionalProperties' => false,
								),
							),
							'required'             => array( 'key', 'action', 'approval', 'delay_seconds', 'social', 'newsletter' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'key', 'event', 'channels' ),
				'additionalProperties' => false,
			),
		);
	}
}
