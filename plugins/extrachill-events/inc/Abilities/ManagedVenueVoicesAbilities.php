<?php
/**
 * Self-scoped public venue voice projection.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\VenueMembershipRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the bounded current-user venue representation contract. */
class ManagedVenueVoicesAbilities {

	/**
	 * Canonical membership reader.
	 *
	 * @var VenueMembershipRepository
	 */
	private $memberships;

	/**
	 * Construct the ability registrar.
	 *
	 * @param VenueMembershipRepository|null $memberships Optional membership reader.
	 */
	public function __construct( ?VenueMembershipRepository $memberships = null ) {
		$this->memberships = $memberships ? $memberships : new VenueMembershipRepository();
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	/** Register the REST-exposed read-only ability. */
	public function register(): void {
		wp_register_ability(
			'extrachill/get-managed-venue-voices',
			array(
				'label'               => __( 'Get Managed Venue Voices', 'extrachill-events' ),
				'description'         => __( 'Returns public venue identities represented by the current authenticated user.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'voices' => array(
							'type'  => 'array',
							'items' => $this->voice_schema(),
						),
					),
					'required'             => array( 'voices' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'authorize' ),
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

	/**
	 * Require an authenticated runtime identity and reject identity claims.
	 *
	 * @param array $input Ability input, which must be empty.
	 * @return true|\WP_Error Authorization result.
	 */
	public function authorize( array $input ) {
		if ( ! empty( $input ) ) {
			return new \WP_Error( 'managed_venue_voice_identity_claim_forbidden', __( 'Managed venue voices are scoped to the current user.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( get_current_user_id() < 1 ) {
			return new \WP_Error( 'managed_venue_voice_authentication_required', __( 'Authentication is required.', 'extrachill-events' ), array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Return active managed venues as a narrow public projection.
	 *
	 * @param array $input Ability input, which must be empty.
	 * @return array|\WP_Error Public voices or an error.
	 */
	public function execute( array $input ) {
		$allowed = $this->authorize( $input );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$user_id        = get_current_user_id();
		$events_blog_id = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'events' ) ) : 0;
		if ( $events_blog_id < 1 ) {
			return new \WP_Error( 'events_site_unresolved', __( 'Could not resolve the Events site.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$switched = get_current_blog_id() !== $events_blog_id;
		if ( $switched ) {
			switch_to_blog( $events_blog_id );
		}

		try {
			$venue_ids = $this->memberships->list_active_venue_ids_for_user( $user_id );
			if ( is_wp_error( $venue_ids ) ) {
				return $venue_ids;
			}

			$voices = array();
			foreach ( $venue_ids as $venue_id ) {
				$term = get_term( $venue_id, 'venue' );
				if ( ! $term || is_wp_error( $term ) || 'venue' !== $term->taxonomy ) {
					continue;
				}

				$url = get_term_link( $term );
				if ( is_wp_error( $url ) ) {
					continue;
				}

				$voices[] = array(
					'reference'   => 'venue:' . (int) $term->term_id,
					'term_id'     => (int) $term->term_id,
					'name'        => sanitize_text_field( (string) $term->name ),
					'slug'        => sanitize_title( (string) $term->slug ),
					'url'         => esc_url_raw( $url ),
					'description' => wp_strip_all_tags( (string) $term->description ),
				);
			}

			return array( 'voices' => $voices );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/** Return the exact public consumer contract for one venue voice. */
	private function voice_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'reference'   => array(
					'type'    => 'string',
					'pattern' => '^venue:[1-9][0-9]*$',
				),
				'term_id'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'url'         => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'description' => array( 'type' => 'string' ),
			),
			'required'             => array( 'reference', 'term_id', 'name', 'slug', 'url', 'description' ),
			'additionalProperties' => false,
		);
	}
}
