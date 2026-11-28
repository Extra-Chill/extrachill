<?php
/**
 * Keyed partner events ability (Extra-Chill/extrachill#1802).
 *
 * The partner key is the credential: the ability is callable without a user
 * session and authenticates inside the domain operation. It is not exposed on
 * /wp-abilities/v1 so the key only travels in the dedicated
 * `X-Extrachill-Partner-Key` header of GET /extrachill/v1/partner/events.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\PartnerVenueEvents;

defined( 'ABSPATH' ) || exit;

/** Registers extrachill/get-partner-venue-events. */
final class PartnerVenueEventsAbilities {

	public const ABILITY = 'extrachill/get-partner-venue-events';

	/** Hook registration. */
	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	/** Register the ability. */
	public function register(): void {
		$nullable_string = array( 'type' => array( 'string', 'null' ) );

		wp_register_ability(
			'extrachill/get-partner-venue-events',
			array(
				'label'               => 'Get partner venue events',
				'description'         => 'Upcoming events, ticket actions, and Schema.org JSON-LD for the one venue a partner key belongs to.',
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'partner_key' => array(
							'type'        => 'string',
							'description' => 'Venue partner key. Selects the venue; there is no venue input.',
						),
						'limit'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => PartnerVenueEvents::MAX_LIMIT,
							'default' => PartnerVenueEvents::DEFAULT_LIMIT,
						),
					),
					'required'             => array( 'partner_key' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'venue'        => array(
							'type'       => 'object',
							'properties' => array(
								'id'                => array( 'type' => 'integer' ),
								'name'              => array( 'type' => 'string' ),
								'slug'              => array( 'type' => 'string' ),
								'url'               => array( 'type' => 'string' ),
								'booking_embed_url' => $nullable_string,
							),
							'required'   => array( 'id', 'name', 'slug', 'url', 'booking_embed_url' ),
						),
						'events'       => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'         => array( 'type' => 'integer' ),
									'title'      => array( 'type' => 'string' ),
									'url'        => array( 'type' => 'string' ),
									'start'      => array( 'type' => 'string' ),
									'end'        => $nullable_string,
									'timezone'   => array( 'type' => 'string' ),
									'performers' => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
									'price'      => $nullable_string,
									'image'      => $nullable_string,
									'excerpt'    => $nullable_string,
									'status'     => array( 'type' => 'string' ),
									'ticket'     => array(
										'type'       => 'object',
										'properties' => array(
											'url'   => $nullable_string,
											'label' => array( 'type' => 'string' ),
											'kind'  => array(
												'type' => 'string',
												'enum' => array( 'checkout', 'external', 'info', 'none' ),
											),
										),
										'required'   => array( 'url', 'label', 'kind' ),
									),
									'schema'     => array( 'type' => array( 'object', 'null' ) ),
								),
								'required'   => array( 'id', 'title', 'url', 'start', 'end', 'timezone', 'performers', 'price', 'image', 'excerpt', 'status', 'ticket', 'schema' ),
							),
						),
						'generated_at' => array( 'type' => 'string' ),
						'cache_ttl'    => array( 'type' => 'integer' ),
					),
					'required'   => array( 'venue', 'events', 'generated_at', 'cache_ttl' ),
				),
				// The key is the credential; it is verified inside the operation.
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( array $input ) {
					return PartnerVenueEvents::for_key(
						(string) ( $input['partner_key'] ?? '' ),
						(int) ( $input['limit'] ?? PartnerVenueEvents::DEFAULT_LIMIT )
					);
				},
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}
}
