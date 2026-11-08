<?php
/**
 * Commerce seller identity for Events-owned sellers (venues and promoters).
 *
 * Shop resolves non-artist sellers through this ability so venue and promoter
 * authority stays owned by the Events site.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\PromoterAuthorityRepository;
use ExtraChillEvents\Core\PromoterAuthoritySchema;
use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueMembershipRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the current-user commerce seller resolver. */
class CommerceSellerAbilities {

	/**
	 * Venue authorization policy.
	 *
	 * @var VenueAuthorization
	 */
	private $venues;

	/**
	 * Promoter authority reader, or null when promoter schema is unavailable.
	 *
	 * @var PromoterAuthorityRepository|null
	 */
	private $promoters;

	/**
	 * Construct the ability registrar.
	 *
	 * @param VenueAuthorization|null          $venues    Optional venue policy.
	 * @param PromoterAuthorityRepository|null $promoters Optional promoter reader.
	 * @param bool                             $register  Hook registration (false in tests).
	 */
	public function __construct( ?VenueAuthorization $venues = null, ?PromoterAuthorityRepository $promoters = null, bool $register = true ) {
		$this->venues    = $venues ? $venues : new VenueAuthorization();
		$this->promoters = $promoters;
		if ( ! $this->promoters && class_exists( PromoterAuthoritySchema::class ) && PromoterAuthoritySchema::is_ready() ) {
			$this->promoters = new PromoterAuthorityRepository();
		}
		if ( $register ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
		}
	}

	/** Register the read-only ability. */
	public function register(): void {
		wp_register_ability(
			'extrachill/get-commerce-seller',
			array(
				'label'               => __( 'Get Commerce Seller', 'extrachill-events' ),
				'description'         => __( 'Resolve a venue or promoter as a shop seller and report whether the current user may manage its finances.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'type' => array(
							'type' => 'string',
							'enum' => array( 'venue', 'promoter' ),
						),
						'id'   => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'type', 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'type'                => array( 'type' => 'string' ),
						'id'                  => array( 'type' => 'integer' ),
						'name'                => array( 'type' => 'string' ),
						'url'                 => array( 'type' => 'string' ),
						'can_manage_finances' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'type', 'id', 'name', 'url', 'can_manage_finances' ),
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

		wp_register_ability(
			'extrachill/list-commerce-sellers',
			array(
				'label'               => __( 'List Commerce Sellers', 'extrachill-events' ),
				'description'         => __( 'List venues and promoters whose finances the current user may manage.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'sellers' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'type'                => array(
										'type' => 'string',
										'enum' => array( 'venue', 'promoter' ),
									),
									'id'                  => array( 'type' => 'integer' ),
									'name'                => array( 'type' => 'string' ),
									'url'                 => array( 'type' => 'string' ),
									'can_manage_finances' => array(
										'type' => 'boolean',
										'enum' => array( true ),
									),
								),
								'required'             => array( 'type', 'id', 'name', 'url', 'can_manage_finances' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'sellers' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'execute_list' ),
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
	 * Require an authenticated user; finance authority is reported, not gated.
	 *
	 * @return true|\WP_Error
	 */
	public function authorize() {
		if ( get_current_user_id() < 1 ) {
			return new \WP_Error( 'commerce_seller_authentication_required', __( 'Authentication is required.', 'extrachill-events' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Resolve the seller identity and the current user's finance authority.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute( array $input ) {
		$allowed = $this->authorize();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$type = isset( $input['type'] ) ? (string) $input['type'] : '';
		$id   = isset( $input['id'] ) && is_int( $input['id'] ) ? $input['id'] : 0;
		if ( ! in_array( $type, array( 'venue', 'promoter' ), true ) || $id < 1 ) {
			return new \WP_Error( 'invalid_commerce_seller', __( 'A venue or promoter seller with a positive ID is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$events_blog_id = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'events' ) ) : 0;
		$switched       = $events_blog_id > 0 && get_current_blog_id() !== $events_blog_id;
		if ( $switched ) {
			switch_to_blog( $events_blog_id );
		}

		try {
			return $this->resolve( $type, $id, get_current_user_id() );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * List the current user's finance-authorized sellers on the Events site.
	 *
	 * @return array|\WP_Error
	 */
	public function execute_list() {
		$allowed = $this->authorize();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$events_blog_id = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'events' ) ) : 0;
		$switched       = $events_blog_id > 0 && get_current_blog_id() !== $events_blog_id;
		if ( $switched ) {
			switch_to_blog( $events_blog_id );
		}

		try {
			$user_id   = get_current_user_id();
			$venue_ids = ( new VenueMembershipRepository() )->list_active_venue_ids_for_user( $user_id );
			if ( is_wp_error( $venue_ids ) ) {
				return $venue_ids;
			}
			$memberships = $this->promoters ? $this->promoters->list_active_memberships_for_user( $user_id ) : array();
			if ( is_wp_error( $memberships ) ) {
				return $memberships;
			}
			$candidates = array(
				'venue'    => $venue_ids,
				'promoter' => array_column( $memberships, 'promoter_term_id' ),
			);
			$sellers    = array();
			foreach ( $candidates as $type => $ids ) {
				foreach ( array_unique( $ids ) as $id ) {
					$seller = $this->resolve( $type, (int) $id, $user_id );
					if ( is_wp_error( $seller ) ) {
						if ( 'commerce_seller_not_found' === $seller->get_error_code() ) {
							continue;
						}
						return $seller;
					}
					if ( $seller['can_manage_finances'] ) {
						$sellers[] = $seller;
					}
				}
			}
			usort( $sellers, static function ( array $a, array $b ): int {
				return array( strtolower( $a['name'] ), $a['type'], $a['id'] ) <=> array( strtolower( $b['name'] ), $b['type'], $b['id'] );
			} );
			return array( 'sellers' => $sellers );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Shop manager link for a venue or promoter the current user runs.
	 *
	 * Empty unless the shop rollout has reached the user and they hold finance
	 * authority for that seller, so the link never leads to a refusal.
	 *
	 * @param string $type Seller type: venue or promoter.
	 * @param int    $id   Seller term ID.
	 * @return string Manager URL with the seller preselected, or ''.
	 */
	public function shop_manager_url( string $type, int $id ): string {
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! function_exists( 'ec_get_shop_manager_url' ) ) {
			return '';
		}
		if ( ! function_exists( 'ec_feature_available' ) || ! ec_feature_available( 'shop', $user_id ) ) {
			return '';
		}

		$seller = $this->execute(
			array(
				'type' => $type,
				'id'   => $id,
			)
		);
		if ( is_wp_error( $seller ) || empty( $seller['can_manage_finances'] ) ) {
			return '';
		}

		return (string) ec_get_shop_manager_url(
			array(
				'type' => $seller['type'],
				'id'   => $seller['id'],
			)
		);
	}

	/**
	 * Resolve on the Events site.
	 *
	 * @param string $type    Seller type.
	 * @param int    $id      Seller term ID.
	 * @param int    $user_id Current user ID.
	 * @return array|\WP_Error
	 */
	private function resolve( string $type, int $id, int $user_id ) {
		$not_found = new \WP_Error( 'commerce_seller_not_found', __( 'Seller not found.', 'extrachill-events' ), array( 'status' => 404 ) );

		$term = get_term( $id, $type );
		if ( ! $term || is_wp_error( $term ) || $type !== $term->taxonomy ) {
			return $not_found;
		}

		if ( 'venue' === $type ) {
			$can = $this->venues->can( $user_id, $id, VenueAuthorization::ACTION_MANAGE_FINANCES );
		} else {
			if ( ! $this->promoters ) {
				return $not_found;
			}
			$organization = $this->promoters->get_organization( $id );
			if ( is_wp_error( $organization ) ) {
				return $organization;
			}
			if ( ! is_array( $organization ) || PromoterAuthorityRepository::STATUS_ACTIVE !== ( $organization['status'] ?? '' ) ) {
				return $not_found;
			}
			$membership = $this->promoters->get_active_membership( $id, $user_id );
			$can        = is_array( $membership ) && ! empty( $membership['is_owner'] );
		}

		$url = get_term_link( $term );

		return array(
			'type'                => $type,
			'id'                  => (int) $term->term_id,
			'name'                => sanitize_text_field( (string) $term->name ),
			'url'                 => is_wp_error( $url ) ? '' : esc_url_raw( $url ),
			'can_manage_finances' => (bool) $can,
		);
	}
}
