<?php
/**
 * Venue show-updates subscriber export (extrachill#1724).
 *
 * - extrachill/venue-export-show-updates-subscribers: the venue's own
 *   email audience for its weekly show updates, for the venue team to take
 *   with them (mirrors extrachill/artist-export-subscribers). Each row
 *   carries the subscriber's personal signed unsubscribe link.
 *
 * The audience is read through the venue show-updates producer, so only
 * fans who opted in to this venue *and* still allow notification email are
 * returned. Same permission as listing the venue's team members.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\TeamCapabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the venue show-updates subscriber export. */
class VenueShowUpdatesAbilities {

	/**
	 * Whether the registration hook was added.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/** Hook registration once. */
	public function __construct() {
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register the export contract. */
	public function register(): void {
		wp_register_ability(
			'extrachill/venue-export-show-updates-subscribers',
			array(
				'label'               => __( 'Export Venue Show-Updates Subscribers', 'extrachill-events' ),
				'description'         => __( 'Export the fans who opted in to one venue\'s weekly show updates, for the venue team\'s own email list.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'venue_term_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Events-site venue term ID.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'venue_term_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'subscribers' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'email'           => array( 'type' => 'string' ),
									'display_name'    => array( 'type' => 'string' ),
									'unsubscribe_url' => array( 'type' => 'string' ),
								),
							),
						),
						'venue_name'  => array( 'type' => 'string' ),
						'export_date' => array( 'type' => 'string' ),
						'total'       => array( 'type' => 'integer' ),
					),
					'required'   => array( 'subscribers', 'venue_name', 'export_date', 'total' ),
				),
				'execute_callback'    => array( $this, 'export_subscribers' ),
				'permission_callback' => array( $this, 'can_export' ),
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
	 * Venue team managers (and site admins, via the team policy) may export.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public function can_export( array $input ) {
		return TeamCapabilities::authorize( 'ec_manage_venue_members', absint( $input['venue_term_id'] ?? 0 ) );
	}

	/**
	 * Export one venue's show-updates audience.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function export_subscribers( array $input ) {
		$venue_term_id = absint( $input['venue_term_id'] ?? 0 );
		$allowed       = TeamCapabilities::authorize( 'ec_manage_venue_members', $venue_term_id );
		if ( true !== $allowed ) {
			return $allowed;
		}

		$venue = get_term( $venue_term_id, 'venue' );
		if ( ! $venue instanceof \WP_Term ) {
			return new \WP_Error( 'venue_not_found', __( 'That venue could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		return self::export_for_venue( $venue );
	}

	/**
	 * Build the export rows for an already-authorized venue.
	 *
	 * @param \WP_Term $venue Venue term.
	 * @return array|\WP_Error
	 */
	public static function export_for_venue( \WP_Term $venue ) {
		if ( ! function_exists( 'extrachill_users_entity_subscription_recipients' ) || ! function_exists( 'extrachill_events_local_scene_digest_unsubscribe_url' ) ) {
			return new \WP_Error( 'dependency_missing', __( 'Venue show updates are unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}

		$user_ids = extrachill_users_entity_subscription_recipients( EXTRACHILL_EVENTS_VENUE_UPDATES_PRODUCER, EXTRACHILL_EVENTS_VENUE_UPDATES_ENTITY, EXTRACHILL_EVENTS_VENUE_UPDATES_TAXONOMY, $venue->slug, 'email' );
		if ( is_wp_error( $user_ids ) ) {
			return $user_ids;
		}

		$rows = array();
		foreach ( (array) $user_ids as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( ! $user instanceof \WP_User || '' === (string) $user->user_email ) {
				continue;
			}
			$rows[] = array(
				'email'           => (string) $user->user_email,
				'display_name'    => (string) $user->display_name,
				'unsubscribe_url' => extrachill_events_local_scene_digest_unsubscribe_url( (int) $user->ID, $venue->slug, null, 'venue' ),
			);
		}

		return array(
			'subscribers' => $rows,
			'venue_name'  => (string) $venue->name,
			'export_date' => current_time( 'Y-m-d' ),
			'total'       => count( $rows ),
		);
	}
}
