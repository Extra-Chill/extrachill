<?php
/**
 * Venue email-sharing audience ability.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\VenueAuthorization;

defined( 'ABSPATH' ) || exit;

/** Registers the verified-owner venue email audience surface. */
class VenueEmailSharingAbilities {

	/** Whether ability registration has already been hooked. */
	private static bool $registered = false;

	/**
	 * Venue authorization service.
	 *
	 * @var VenueAuthorization
	 */
	private $authorization;

	/**
	 * Build the ability with the canonical venue authorization service.
	 *
	 * @param VenueAuthorization|null $authorization Venue authorization service.
	 */
	public function __construct( ?VenueAuthorization $authorization = null ) {
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register the private current-email listing contract. */
	public function register(): void {
		wp_register_ability(
			'extrachill/list-venue-email-subscribers',
			array(
				'label'               => __( 'List Venue Email Subscribers', 'extrachill-events' ),
				'description'         => __( 'List current account emails explicitly shared with one managed venue.', 'extrachill-events' ),
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
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'venue_term_id' => array( 'type' => 'integer' ),
						'total'         => array( 'type' => 'integer' ),
						'subscribers'   => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'user_id' => array( 'type' => 'integer' ),
									'email'   => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'can_manage_venue' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}

	/**
	 * Require verified owner authority for the exact venue.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public function can_manage_venue( array $input ) {
		return $this->authorization->authorize( get_current_user_id(), absint( $input['venue_term_id'] ?? 0 ), VenueAuthorization::ACTION_MANAGE_MEMBERS );
	}

	/**
	 * Resolve consenting IDs and map only valid current account emails.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute( array $input ) {
		$venue_term_id = absint( $input['venue_term_id'] ?? 0 );
		$authorized    = $this->can_manage_venue( $input );
		if ( true !== $authorized ) {
			return $authorized;
		}

		$venue = get_term( $venue_term_id, 'venue' );
		if ( ! is_object( $venue ) || 'venue' !== ( $venue->taxonomy ?? '' ) || empty( $venue->slug ) || ! function_exists( 'extrachill_users_entity_subscription_recipients' ) ) {
			return new \WP_Error( 'venue_email_subscribers_unavailable', __( 'Venue email subscribers are unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}

		$user_ids = extrachill_users_entity_subscription_recipients(
			EXTRACHILL_EVENTS_VENUE_EMAIL_SHARING_PRODUCER,
			'venue-email-sharing',
			'venue',
			$venue->slug,
			'email'
		);
		if ( is_wp_error( $user_ids ) ) {
			return $user_ids;
		}

		$subscribers = array();
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', (array) $user_ids ) ) ) ) as $user_id ) {
			$user  = get_userdata( $user_id );
			$email = $user instanceof \WP_User ? sanitize_email( $user->user_email ) : '';
			if ( '' === $email || ! is_email( $email ) ) {
				continue;
			}
			$subscribers[] = array(
				'user_id' => $user_id,
				'email'   => $email,
			);
		}

		return array(
			'venue_term_id' => $venue_term_id,
			'total'         => count( $subscribers ),
			'subscribers'   => $subscribers,
		);
	}
}
