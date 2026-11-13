<?php
/**
 * Which events a user manages through their venue and promoter teams.
 *
 * The set-based companion to extrachill_events_user_can_manage_event(): that
 * function answers "may this user manage this one event?"; this class
 * answers "which events?" so a promoter or venue manager can find them
 * without knowing IDs first (extrachill#1301).
 *
 * Authority has two sources, composed differently with an agent's
 * execution principal:
 *
 *  - Team authority (domain): an active venue-team membership or an active
 *    promoter-team membership. This is the same membership the door list,
 *    guest list, and perk abilities already trust. Members are usually
 *    plain accounts without any editorial capability, so no WordPress
 *    capability can be required of them; under an agent principal the
 *    ceiling must still admit `read` and represent this exact user.
 *  - Role authority (capability): `edit_others_posts`, the capability Data
 *    Machine Events' own write abilities require. Holders already edit
 *    every event, so they may list all events. This branch composes the
 *    execution-principal ceiling (TeamAuthorization::user_can()), so an
 *    agent acting for an editor or administrator without
 *    `edit_others_posts` in its ceiling falls back to team authority only.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/TeamAuthorization.php';

/** Resolves a user's managed venue and promoter scope. */
final class ManagedEventScope {

	/** Capability that grants authority over every event. */
	public const UNRESTRICTED_CAPABILITY = 'edit_others_posts';

	/** Capability an agent principal's ceiling must admit for team authority. */
	public const PRINCIPAL_CAPABILITY = 'read';

	/** Event post type. */
	public const POST_TYPE = 'data_machine_events';

	/**
	 * Reads active venue term IDs for a user.
	 *
	 * @var callable|null
	 */
	private $venue_ids_reader;

	/**
	 * Reads active promoter term IDs for a user.
	 *
	 * @var callable|null
	 */
	private $promoter_ids_reader;

	/**
	 * @param callable|null $venue_ids_reader    fn( int $user_id ): int[]|\WP_Error. Defaults to the venue membership store.
	 * @param callable|null $promoter_ids_reader fn( int $user_id ): int[]|\WP_Error. Defaults to the promoter membership store.
	 */
	public function __construct( ?callable $venue_ids_reader = null, ?callable $promoter_ids_reader = null ) {
		$this->venue_ids_reader    = $venue_ids_reader;
		$this->promoter_ids_reader = $promoter_ids_reader;
	}

	/**
	 * Resolve the scope a user manages.
	 *
	 * @param int $user_id Represented WordPress user ID.
	 * @return array{user_id:int,unrestricted:bool,venue_ids:int[],promoter_ids:int[]}|\WP_Error
	 */
	public function for_user( int $user_id ) {
		if ( $user_id < 1 ) {
			return new \WP_Error( 'managed_events_authentication_required', __( 'Sign in to manage your events.', 'extrachill-events' ), array( 'status' => 401 ) );
		}

		if ( ! TeamAuthorization::principal_allows( $user_id, self::PRINCIPAL_CAPABILITY ) ) {
			return new \WP_Error( 'managed_events_principal_forbidden', __( 'This agent is not allowed to act on your events.', 'extrachill-events' ), array( 'status' => 403 ) );
		}

		$venue_ids = $this->read_ids( $this->venue_ids_reader ?? array( $this, 'default_venue_ids' ), $user_id );
		if ( is_wp_error( $venue_ids ) ) {
			return $venue_ids;
		}

		$promoter_ids = $this->read_ids( $this->promoter_ids_reader ?? array( $this, 'default_promoter_ids' ), $user_id );
		if ( is_wp_error( $promoter_ids ) ) {
			return $promoter_ids;
		}

		return array(
			'user_id'      => $user_id,
			'unrestricted' => TeamAuthorization::user_can( $user_id, self::UNRESTRICTED_CAPABILITY ),
			'venue_ids'    => $venue_ids,
			'promoter_ids' => $promoter_ids,
		);
	}

	/**
	 * Whether a resolved scope covers one event.
	 *
	 * @param array $scope    Scope from for_user().
	 * @param int   $event_id Event post ID.
	 * @return bool
	 */
	public function covers_event( array $scope, int $event_id ): bool {
		$post = get_post( $event_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}

		if ( ! empty( $scope['unrestricted'] ) ) {
			return true;
		}

		foreach ( array(
			'venue'    => $scope['venue_ids'] ?? array(),
			'promoter' => $scope['promoter_ids'] ?? array(),
		) as $taxonomy => $ids ) {
			if ( empty( $ids ) ) {
				continue;
			}
			if ( array_intersect( self::event_term_ids( $event_id, $taxonomy ), $ids ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Term IDs of one taxonomy assigned to an event.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $taxonomy venue or promoter.
	 * @return int[]
	 */
	public static function event_term_ids( int $event_id, string $taxonomy ): array {
		$terms = wp_get_post_terms( $event_id, $taxonomy );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		return array_values( array_unique( array_map( static fn( $term ) => (int) $term->term_id, $terms ) ) );
	}

	/**
	 * Normalize a reader's result into unique positive integers.
	 *
	 * @param callable $reader  ID reader.
	 * @param int      $user_id User ID.
	 * @return int[]|\WP_Error
	 */
	private function read_ids( callable $reader, int $user_id ) {
		$ids = $reader( $user_id );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		return array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ), static fn( int $id ) => $id > 0 ) ) );
	}

	/**
	 * Active venue memberships from the network team store.
	 *
	 * @param int $user_id User ID.
	 * @return int[]|\WP_Error
	 */
	private function default_venue_ids( int $user_id ) {
		if ( ! class_exists( VenueMembershipRepository::class ) || ! class_exists( '\\ExtraChill\\Users\\Teams\\TeamMembershipRepository' ) ) {
			return array();
		}

		return ( new VenueMembershipRepository() )->list_active_venue_ids_for_user( $user_id );
	}

	/**
	 * Active promoter memberships from the network team store.
	 *
	 * @param int $user_id User ID.
	 * @return int[]|\WP_Error
	 */
	private function default_promoter_ids( int $user_id ) {
		if ( ! class_exists( PromoterAuthorityRepository::class ) ) {
			return array();
		}

		$memberships = ( new PromoterAuthorityRepository() )->list_active_memberships_for_user( $user_id );
		if ( is_wp_error( $memberships ) ) {
			return $memberships;
		}

		return array_column( (array) $memberships, 'promoter_term_id' );
	}
}
