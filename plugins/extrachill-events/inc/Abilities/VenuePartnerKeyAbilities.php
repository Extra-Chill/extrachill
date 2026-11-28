<?php
/**
 * Partner credential lifecycle abilities; no public REST exposure of credentials.
 *
 * @package ExtraChillEvents\Abilities
 */
namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\VenuePartnerKey;

defined( 'ABSPATH' ) || exit;

final class VenuePartnerKeyAbilities {
	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		foreach ( array( 'issue', 'rotate', 'revoke', 'status' ) as $operation ) {
			// abilities-docs: extrachill/issue-venue-partner-key, extrachill/rotate-venue-partner-key, extrachill/revoke-venue-partner-key, extrachill/status-venue-partner-key.
			wp_register_ability( 'extrachill/' . $operation . '-venue-partner-key', array(
				'label'               => ucfirst( $operation ) . ' venue partner key',
				'description'         => 'Manage one venue credential. Requires manage_members on that venue or administrator authority.',
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'venue_term_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'venue_term_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				// Authorization is inside the domain operation after switching to the events site.
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( array $input ) use ( $operation ) {
					return VenuePartnerKey::manage( (int) $input['venue_term_id'], $operation );
				},
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => 'status' === $operation,
						'idempotent'  => in_array( $operation, array( 'status', 'revoke' ), true ),
						'destructive' => in_array( $operation, array( 'rotate', 'revoke' ), true ),
					),
				),
			) );
		}
	}
}
