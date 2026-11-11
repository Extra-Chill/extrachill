<?php
/**
 * Door access ability (extrachill#1244).
 *
 * `extrachill/grant-door-access` lets an Extra Chill team member, a site
 * administrator, or an active member of a venue/promoter team give a person
 * check-in access from chat: resolve or create their account from an email
 * and add them to the team as a non-owner. Business rules live in
 * DoorAccessService.
 *
 * Deliberately `show_in_rest: true`: this is the single intentionally exposed
 * door-staff surface, so the team-seat MCP tools and the core
 * /wp-abilities/v1 runner can call it. The hidden primitives it composes
 * (`extrachill/resolve-account-by-email`, owner-only membership writes) stay
 * hidden; the permission callback below is the boundary.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\DoorAccessService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/Core/DoorAccessService.php';

/** Registers the door access grant. */
class DoorAccessAbilities {

	private static bool $registered = false;

	/** @var DoorAccessService */
	private $service;

	/**
	 * Build the ability group.
	 *
	 * @param DoorAccessService|null $service Door access rules.
	 */
	public function __construct( ?DoorAccessService $service = null ) {
		$this->service = $service ? $service : new DoorAccessService();
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register extrachill/grant-door-access. */
	public function register(): void {
		$membership = array(
			'type'       => 'object',
			'properties' => array(
				'status'             => array( 'type' => 'string' ),
				'is_owner'           => array( 'type' => 'boolean' ),
				'created'            => array( 'type' => 'boolean' ),
				'version'            => array( 'type' => 'integer' ),
				'created_by_user_id' => array( 'type' => 'integer' ),
			),
		);

		wp_register_ability(
			'extrachill/grant-door-access',
			array(
				'label'               => __( 'Grant Door Access', 'extrachill-events' ),
				'description'         => __( 'Give a person check-in access: resolve or create their account from an email (or attach an existing username) and add them to the event\'s venue team, or its promoter team, as a non-owner member. Never grants ownership; an existing member is left unchanged. Returns the account login and email to sanity-check, a claim link for new or unclaimed accounts, and near-match accounts when the email has no exact account.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'email'            => array(
							'type'        => 'string',
							'description' => __( 'Email of the person getting door access.', 'extrachill-events' ),
						),
						'event_id'         => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Event post ID. Resolves to the event\'s venue team, or its promoter team with team_type=promoter.', 'extrachill-events' ),
						),
						'venue_term_id'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Venue term ID, instead of event_id.', 'extrachill-events' ),
						),
						'promoter_term_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Promoter term ID, instead of event_id.', 'extrachill-events' ),
						),
						'team_type'        => array(
							'type'        => 'string',
							'enum'        => array( 'venue', 'promoter' ),
							'description' => __( 'Which of the event\'s teams to join. Default venue.', 'extrachill-events' ),
						),
						'user_login'       => array(
							'type'        => 'string',
							'description' => __( 'Attach this existing account (by username) instead of resolving the email, e.g. when the person already registered under a different email.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'email' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'user_id'         => array( 'type' => 'integer' ),
						'user_login'      => array( 'type' => 'string' ),
						'email'           => array( 'type' => 'string' ),
						'created_account' => array( 'type' => 'boolean' ),
						'membership'      => $membership,
						'claim_url'       => array( 'type' => array( 'string', 'null' ) ),
						'claim_throttled' => array( 'type' => 'boolean' ),
						'team_type'       => array(
							'type' => 'string',
							'enum' => array( 'venue', 'promoter' ),
						),
						'team_term_id'    => array( 'type' => 'integer' ),
						'near_matches'    => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'user_id'    => array( 'type' => 'integer' ),
									'user_login' => array( 'type' => 'string' ),
									'email'      => array( 'type' => 'string' ),
									'registered' => array( 'type' => 'string' ),
									'distance'   => array( 'type' => 'integer' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'grant' ),
				'permission_callback' => array( $this, 'can_grant' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);
	}

	/**
	 * Authorize the request against its resolved team.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public function can_grant( $input = array() ) {
		return $this->service->can_grant( get_current_user_id(), is_array( $input ) ? $input : array() );
	}

	/**
	 * Grant door access.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function grant( $input = array() ) {
		return $this->service->grant( get_current_user_id(), is_array( $input ) ? $input : array() );
	}
}
