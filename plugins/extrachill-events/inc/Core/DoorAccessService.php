<?php
/**
 * Door access: add a person to a venue or promoter team as a non-owner.
 *
 * Built for the door (extrachill#1244): an Extra Chill team member, a site
 * administrator, or someone already on the venue/promoter team gives a
 * person check-in access from chat with just an email. The account is
 * resolved through extrachill-users' canonical resolve-or-create primitive,
 * and the person joins the team as a non-owner active member.
 *
 * Ownership is structural authority and stays with the owner-only
 * membership services (VenueMembershipService, PromoterAuthorityService).
 * This service never creates an owner, and never changes the ownership or
 * status of an existing membership.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

use ExtraChill\Users\Teams\TeamMembershipRepository;

/** Resolves the account and team, authorizes the actor, and adds a non-owner member. */
final class DoorAccessService {
	/** Registration source recorded on accounts created here. */
	public const REGISTRATION_SOURCE = 'door_access';

	/** Near-match hints only consider accounts registered this recently. */
	public const NEAR_MATCH_WINDOW_DAYS = 14;

	/** Largest local-part edit distance reported as a near match. */
	public const NEAR_MATCH_MAX_DISTANCE = 2;

	/** Upper bound on recent same-domain accounts compared for near matches. */
	public const NEAR_MATCH_SCAN_LIMIT = 200;

	/** Most near-match hints returned. */
	public const NEAR_MATCH_RESULT_LIMIT = 5;

	public const ACTOR_ADMIN  = 'admin';
	public const ACTOR_STAFF  = 'staff';
	public const ACTOR_MEMBER = 'member';

	/** @var TeamAuthorization */
	private $authorization;

	/** @var TeamMembershipRepository */
	private $members;

	/** @var callable */
	private $resolve_account;

	/** @var bool */
	private $use_execution_principal;

	/**
	 * Build the service.
	 *
	 * @param TeamAuthorization|null        $authorization           Team policy (membership reads).
	 * @param TeamMembershipRepository|null $members                 Team membership store.
	 * @param callable|null                 $resolve_account         Account resolver; defaults to extrachill-users' resolve-account-by-email implementation.
	 * @param bool                          $use_execution_principal Whether an active agent principal caps authority.
	 */
	public function __construct( ?TeamAuthorization $authorization = null, ?TeamMembershipRepository $members = null, ?callable $resolve_account = null, bool $use_execution_principal = true ) {
		$this->authorization           = $authorization ? $authorization : new TeamAuthorization( null, null, $use_execution_principal );
		$this->members                 = $members ? $members : new TeamMembershipRepository();
		$this->resolve_account         = $resolve_account;
		$this->use_execution_principal = $use_execution_principal;
	}

	/**
	 * Resolve the target team from exactly one of event_id, venue_term_id, promoter_term_id.
	 *
	 * An event resolves to its venue team by default. Pass team_type=promoter
	 * to target the event's promoter team instead. The venue is the default
	 * because the door is the venue's: whoever works check-in works it for
	 * the room, whoever promoted the show.
	 *
	 * @param array $input Ability input.
	 * @return array{team_type:string,team_term_id:int,event_id:int}|\WP_Error
	 */
	public function resolve_team( array $input ) {
		$event_id    = absint( $input['event_id'] ?? 0 );
		$venue_id    = absint( $input['venue_term_id'] ?? 0 );
		$promoter_id = absint( $input['promoter_term_id'] ?? 0 );
		$given       = count( array_filter( array( $event_id, $venue_id, $promoter_id ) ) );
		if ( 1 !== $given ) {
			return new \WP_Error( 'door_access_target_required', __( 'Give exactly one of event_id, venue_term_id, or promoter_term_id.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$hint = sanitize_key( (string) ( $input['team_type'] ?? '' ) );
		if ( '' !== $hint && ! in_array( $hint, TeamMembershipRepository::TYPES, true ) ) {
			return new \WP_Error( 'door_access_invalid_team_type', __( 'team_type must be venue or promoter.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		if ( $venue_id ) {
			$team_type = 'venue';
			$term_id   = $venue_id;
		} elseif ( $promoter_id ) {
			$team_type = 'promoter';
			$term_id   = $promoter_id;
		} else {
			$team_type = '' !== $hint ? $hint : 'venue';
			$term_id   = $this->event_team_term( $event_id, $team_type );
			if ( is_wp_error( $term_id ) ) {
				return $term_id;
			}
		}

		if ( '' !== $hint && $hint !== $team_type ) {
			return new \WP_Error( 'door_access_team_type_mismatch', __( 'team_type does not match the given team.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$valid = $this->validate_team( $team_type, $term_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return array(
			'team_type'    => $team_type,
			'team_term_id' => $term_id,
			'event_id'     => $event_id,
		);
	}

	/**
	 * Decide whether an actor may grant door access on one team.
	 *
	 * Allowed: a site administrator (manage_options), an Extra Chill team
	 * member (the `access_events_admin` capability the `extra_chill_team`
	 * role grants; the same staff rule SubmissionTrustPolicy applies), or an
	 * active member of the target team. Admin and staff checks compose the
	 * active execution-principal ceiling exactly like TeamAuthorization; a
	 * member acting through an agent principal must also hold the Events
	 * access capability inside that ceiling, the same rule venue and promoter
	 * owner actions apply.
	 *
	 * @param int    $actor_user_id Acting network user.
	 * @param string $team_type     venue or promoter.
	 * @param int    $team_term_id  Team term ID.
	 * @return string|\WP_Error ACTOR_ADMIN, ACTOR_STAFF, ACTOR_MEMBER, or a denial.
	 */
	public function authorize( int $actor_user_id, string $team_type, int $team_term_id ) {
		if ( $actor_user_id < 1 || ! get_userdata( $actor_user_id ) ) {
			return $this->forbidden();
		}
		if ( TeamAuthorization::is_site_admin( $actor_user_id, $this->use_execution_principal ) ) {
			return self::ACTOR_ADMIN;
		}
		if ( TeamAuthorization::user_can( $actor_user_id, TeamAuthorization::ACCESS_CAPABILITY, $this->use_execution_principal ) ) {
			return self::ACTOR_STAFF;
		}

		$membership = $this->authorization->active_team_membership( $actor_user_id, $team_type, $team_term_id );
		if ( is_wp_error( $membership ) ) {
			return $membership;
		}
		if ( ! is_array( $membership ) || TeamAuthorization::STATUS_ACTIVE !== ( $membership['status'] ?? '' ) ) {
			return $this->forbidden();
		}
		if ( $this->use_execution_principal && ! TeamAuthorization::principal_allows( $actor_user_id, TeamAuthorization::ACCESS_CAPABILITY ) ) {
			return $this->forbidden();
		}

		return self::ACTOR_MEMBER;
	}

	/**
	 * Resolve the team and authorize the actor for one request.
	 *
	 * @param int   $actor_user_id Acting network user.
	 * @param array $input         Ability input.
	 * @return true|\WP_Error
	 */
	public function can_grant( int $actor_user_id, array $input ) {
		$team = $this->resolve_team( $input );
		if ( is_wp_error( $team ) ) {
			return $team;
		}
		$actor = $this->authorize( $actor_user_id, $team['team_type'], $team['team_term_id'] );

		return is_wp_error( $actor ) ? $actor : true;
	}

	/**
	 * Grant door access.
	 *
	 * @param int   $actor_user_id Acting network user, recorded as created_by_user_id.
	 * @param array $input         {email, event_id|venue_term_id|promoter_term_id, team_type?, user_login?}. Any is_owner key is ignored.
	 * @return array|\WP_Error
	 */
	public function grant( int $actor_user_id, array $input ) {
		$team = $this->resolve_team( $input );
		if ( is_wp_error( $team ) ) {
			return $team;
		}
		$actor = $this->authorize( $actor_user_id, $team['team_type'], $team['team_term_id'] );
		if ( is_wp_error( $actor ) ) {
			return $actor;
		}

		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Enter a valid email address.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$user_login    = trim( (string) ( $input['user_login'] ?? '' ) );
		$near_matches  = array();
		$account_email = $email;
		if ( '' !== $user_login ) {
			// Attach an account the host already knows, e.g. one the person
			// registered themselves after a typo'd email made another.
			$existing = get_user_by( 'login', $user_login );
			if ( ! $existing instanceof \WP_User ) {
				return new \WP_Error( 'door_access_user_not_found', __( 'No account has that username.', 'extrachill-events' ), array( 'status' => 404 ) );
			}
			$account_email = (string) $existing->user_email;
		} elseif ( ! get_user_by( 'email', $email ) ) {
			$near_matches = $this->near_matches( $email );
		}

		// Fail before creating an account the grant could never use.
		$ready = $this->team_accepts_members( $team['team_type'], $team['team_term_id'] );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$account = $this->resolve_account( $account_email );
		if ( is_wp_error( $account ) ) {
			return $account;
		}
		$user = get_userdata( (int) $account['user_id'] );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'door_access_account_missing', __( 'The account could not be loaded.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$membership = $this->add_member( $actor_user_id, $actor, $team['team_type'], $team['team_term_id'], (int) $user->ID );
		if ( is_wp_error( $membership ) ) {
			// Do not leave behind an account this call created for nothing.
			// An uncertain commit may have kept the membership, so keep the account.
			if ( ! empty( $account['created'] ) && 'door_access_commit_uncertain' !== $membership->get_error_code() && function_exists( 'extrachill_users_rollback_created_user' ) ) {
				extrachill_users_rollback_created_user( (int) $user->ID );
			}
			return $membership;
		}

		$claim_url = (string) ( $account['claim_url'] ?? '' );

		return array(
			'user_id'         => (int) $user->ID,
			'user_login'      => (string) $user->user_login,
			'email'           => (string) $user->user_email,
			'created_account' => ! empty( $account['created'] ),
			'membership'      => $membership,
			'claim_url'       => '' !== $claim_url ? $claim_url : null,
			'claim_throttled' => ! empty( $account['claim_throttled'] ),
			'team_type'       => $team['team_type'],
			'team_term_id'    => $team['team_term_id'],
			'near_matches'    => $near_matches,
		);
	}

	/**
	 * Recently registered accounts whose email is a near miss of this one.
	 *
	 * Same domain, local part within NEAR_MATCH_MAX_DISTANCE edits, registered
	 * in the last NEAR_MATCH_WINDOW_DAYS days. Hints only: never auto-picked.
	 *
	 * @param string $email Email with no exact account.
	 * @return array<int,array{user_id:int,user_login:string,email:string,registered:string,distance:int}>
	 */
	public function near_matches( string $email ): array {
		$at = strrpos( $email, '@' );
		if ( false === $at || ! function_exists( 'get_users' ) ) {
			return array();
		}
		$local  = strtolower( substr( $email, 0, $at ) );
		$domain = strtolower( substr( $email, $at + 1 ) );
		if ( '' === $local || '' === $domain ) {
			return array();
		}

		$candidates = get_users(
			array(
				'blog_id'        => 0,
				'search'         => '*@' . $domain,
				'search_columns' => array( 'user_email' ),
				'date_query'     => array(
					array(
						'after'     => gmdate( 'Y-m-d H:i:s', time() - self::NEAR_MATCH_WINDOW_DAYS * DAY_IN_SECONDS ),
						'inclusive' => true,
					),
				),
				'orderby'        => 'registered',
				'order'          => 'DESC',
				'number'         => self::NEAR_MATCH_SCAN_LIMIT,
				'count_total'    => false,
			)
		);

		$matches = array();
		foreach ( (array) $candidates as $candidate ) {
			$candidate_email = strtolower( (string) ( $candidate->user_email ?? '' ) );
			$candidate_at    = strrpos( $candidate_email, '@' );
			if ( false === $candidate_at || substr( $candidate_email, $candidate_at + 1 ) !== $domain ) {
				continue;
			}
			$distance = levenshtein( $local, substr( $candidate_email, 0, $candidate_at ) );
			if ( $distance < 1 || $distance > self::NEAR_MATCH_MAX_DISTANCE ) {
				continue;
			}
			$matches[] = array(
				'user_id'    => (int) $candidate->ID,
				'user_login' => (string) $candidate->user_login,
				'email'      => (string) $candidate->user_email,
				'registered' => (string) ( $candidate->user_registered ?? '' ),
				'distance'   => $distance,
			);
		}

		usort(
			$matches,
			static function ( array $a, array $b ): int {
				return array( $a['distance'], $b['registered'] ) <=> array( $b['distance'], $a['registered'] );
			}
		);

		return array_slice( $matches, 0, self::NEAR_MATCH_RESULT_LIMIT );
	}

	/**
	 * Unlocked pre-flight for rules add_member() re-checks under the lock.
	 *
	 * @param string $team_type venue or promoter.
	 * @param int    $term_id   Team term ID.
	 * @return true|\WP_Error
	 */
	private function team_accepts_members( string $team_type, int $term_id ) {
		global $wpdb;
		if ( 'venue' !== $team_type ) {
			return true;
		}
		$owners = $this->members->select(
			'venue',
			array(
				'term'     => $term_id,
				'status'   => TeamAuthorization::STATUS_ACTIVE,
				'is_owner' => true,
			),
			'id ASC',
			1
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'door_access_read_failed', __( 'The team could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
		}

		return $owners ? true : new \WP_Error( 'venue_membership_owner_required', __( 'The first active venue member must be an owner.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	/**
	 * Add the non-owner membership under the team lock; existing rows are never changed.
	 *
	 * @param int    $actor_user_id Recorded creator.
	 * @param string $actor         How the actor was authorized.
	 * @param string $team_type     venue or promoter.
	 * @param int    $term_id       Team term ID.
	 * @param int    $user_id       Member to add.
	 * @return array|\WP_Error Membership projection.
	 */
	private function add_member( int $actor_user_id, string $actor, string $team_type, int $term_id, int $user_id ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes door access grants.
			return new \WP_Error( 'door_access_transaction_failed', __( 'The door access change could not start.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$rows = $this->members->lock_team( $team_type, $term_id );
		if ( '' !== (string) $wpdb->last_error ) {
			$this->rollback();
			return new \WP_Error( 'door_access_read_failed', __( 'The team could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$memberships = $this->members->hydrate_rows( $team_type, $rows );
		if ( is_wp_error( $memberships ) ) {
			$this->rollback();
			return $memberships;
		}

		$existing         = null;
		$actor_is_member  = false;
		$has_active_owner = false;
		foreach ( $memberships as $membership ) {
			$active = TeamAuthorization::STATUS_ACTIVE === $membership['status'];
			if ( $membership['user_id'] === $user_id ) {
				$existing = $membership;
			}
			if ( $membership['user_id'] === $actor_user_id && $active ) {
				$actor_is_member = true;
			}
			if ( $membership['is_owner'] && $active ) {
				$has_active_owner = true;
			}
		}

		// A member-authorized actor must still be active under the lock.
		if ( self::ACTOR_MEMBER === $actor && ! $actor_is_member ) {
			$this->rollback();
			return $this->forbidden();
		}

		if ( is_array( $existing ) ) {
			$this->rollback();
			return $this->existing_result( $existing );
		}

		if ( 'venue' === $team_type && ! $has_active_owner ) {
			$this->rollback();
			return new \WP_Error( 'venue_membership_owner_required', __( 'The first active venue member must be an owner.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( 'promoter' === $team_type && count( $memberships ) >= PromoterAuthorityRepository::MAX_MEMBERS ) {
			$this->rollback();
			return new \WP_Error(
				'promoter_membership_limit_exceeded',
				__( 'This promoter organization has reached the supported membership limit.', 'extrachill-events' ),
				array(
					'status'  => 409,
					'maximum' => PromoterAuthorityRepository::MAX_MEMBERS,
				)
			);
		}

		$row = $this->members->new_row( $team_type, $term_id, $user_id, false, $actor_user_id, TeamAuthorization::STATUS_ACTIVE );
		if ( false === $this->members->insert( $team_type, $row ) ) {
			$this->rollback();
			// Lost a race with a concurrent grant for the same person.
			$winner = $this->members->get( $team_type, $term_id, $user_id );
			if ( is_array( $winner ) ) {
				return $this->existing_result( $winner );
			}
			return new \WP_Error( 'door_access_create_failed', __( 'The door access membership could not be created.', 'extrachill-events' ), array( 'status' => 500 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes the door access grant.
			return new \WP_Error( 'door_access_commit_uncertain', __( 'The door access change could not be confirmed.', 'extrachill-events' ), array( 'status' => 503 ) );
		}

		if ( 'promoter' === $team_type ) {
			do_action( 'extrachill_events_promoter_authority_changed', $term_id, 'membership_created' );
		}
		do_action( 'extrachill_events_door_access_granted', $team_type, $term_id, $user_id, $actor_user_id );

		$created = $this->members->get( $team_type, $term_id, $user_id );

		return $this->projection( is_array( $created ) ? $created : array_merge( $row, array( 'version' => 1 ) ), true );
	}

	/**
	 * Report an existing membership without changing it.
	 *
	 * Active members are a no-op. Invited and revoked rows are not silently
	 * activated: an invitation has its own accept flow, and a revoked member
	 * was removed on purpose.
	 *
	 * @param array $membership Hydrated membership.
	 * @return array|\WP_Error
	 */
	private function existing_result( array $membership ) {
		if ( TeamAuthorization::STATUS_ACTIVE === $membership['status'] ) {
			return $this->projection( $membership, false );
		}
		if ( TeamAuthorization::STATUS_INVITED === $membership['status'] ) {
			return new \WP_Error( 'door_access_invitation_pending', __( 'This person already has a pending team invitation. Accept or cancel it first.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		return new \WP_Error( 'door_access_membership_revoked', __( 'This person was removed from the team. A team owner has to restore them.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	/**
	 * Public membership shape.
	 *
	 * @param array $membership Membership row.
	 * @param bool  $created    Whether this call created it.
	 */
	private function projection( array $membership, bool $created ): array {
		return array(
			'status'             => (string) $membership['status'],
			'is_owner'           => (bool) $membership['is_owner'],
			'created'            => $created,
			'version'            => (int) $membership['version'],
			'created_by_user_id' => (int) $membership['created_by_user_id'],
		);
	}

	/**
	 * Resolve or create the account through extrachill-users.
	 *
	 * @param string $email Account email.
	 * @return array|\WP_Error {user_id, created, unclaimed, claim_url, claim_throttled}.
	 */
	private function resolve_account( string $email ) {
		$resolver = $this->resolve_account;
		if ( null === $resolver ) {
			if ( ! function_exists( 'extrachill_users_ability_resolve_account_by_email' ) ) {
				return new \WP_Error( 'door_access_account_unavailable', __( 'Account resolution is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
			}
			$resolver = 'extrachill_users_ability_resolve_account_by_email';
		}
		$result = call_user_func(
			$resolver,
			array(
				'email'               => $email,
				'registration_source' => self::REGISTRATION_SOURCE,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) || empty( $result['user_id'] ) ) {
			return new \WP_Error( 'door_access_account_failed', __( 'The account could not be resolved.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		return $result;
	}

	/**
	 * The event's single venue or promoter term.
	 *
	 * @param int    $event_id  Event post ID.
	 * @param string $team_type venue or promoter.
	 * @return int|\WP_Error
	 */
	private function event_team_term( int $event_id, string $team_type ) {
		$post = get_post( $event_id );
		if ( ! $post || 'data_machine_events' !== $post->post_type ) {
			return new \WP_Error( 'event_not_found', __( 'The event could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$terms = wp_get_post_terms( $event_id, $team_type );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		$terms = array_values( (array) $terms );
		if ( ! $terms ) {
			return new \WP_Error(
				'door_access_event_has_no_team',
				'venue' === $team_type
					? __( 'This event has no venue. Pass team_type=promoter to use its promoter team.', 'extrachill-events' )
					: __( 'This event has no promoter.', 'extrachill-events' ),
				array( 'status' => 404 )
			);
		}
		if ( count( $terms ) > 1 ) {
			return new \WP_Error( 'door_access_team_ambiguous', __( 'This event has more than one team of that type. Pass venue_term_id or promoter_term_id instead.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		return (int) $terms[0]->term_id;
	}

	/**
	 * Validate the team term and its storage.
	 *
	 * @param string $team_type venue or promoter.
	 * @param int    $term_id   Team term ID.
	 * @return true|\WP_Error
	 */
	private function validate_team( string $team_type, int $term_id ) {
		$term = get_term( $term_id, $team_type );
		if ( $term_id < 1 || ! $term || is_wp_error( $term ) || $team_type !== $term->taxonomy ) {
			return new \WP_Error( 'door_access_invalid_team', __( 'A valid venue or promoter is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( 'venue' === $team_type ) {
			return VenueSchema::is_ready() ? true : new \WP_Error( 'venue_membership_schema_unavailable', __( 'Venue membership storage is not ready.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( ! class_exists( PromoterAuthoritySchema::class ) || ! class_exists( PromoterAuthorityRepository::class ) || ! PromoterAuthoritySchema::is_ready() ) {
			return new \WP_Error( 'promoter_authority_schema_unavailable', __( 'Promoter authority storage is not ready.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$organization = $this->authorization->promoter_organization( $term_id );
		if ( is_wp_error( $organization ) ) {
			return $organization;
		}
		if ( ! is_array( $organization ) || PromoterAuthorityRepository::STATUS_ACTIVE !== $organization['status'] ) {
			return new \WP_Error( 'door_access_promoter_unverified', __( 'This promoter is not a verified organization yet.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		return true;
	}

	/** Roll back the current grant. */
	private function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back a failed door access grant.
	}

	/** Standard denial. */
	private function forbidden(): \WP_Error {
		return new \WP_Error( 'door_access_forbidden', __( 'You are not allowed to give door access for this team.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
