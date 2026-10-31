<?php
/**
 * Self-scoped promoter workspace projection.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Builds typed identity and collaboration context without expanding authority. */
final class PromoterWorkspace {
	public const MAX_IDENTITIES = 600;

	/**
	 * Promoter organization persistence.
	 *
	 * @var PromoterAuthorityRepository
	 */
	private $promoters;
	/**
	 * Promoter venue grant persistence.
	 *
	 * @var PromoterVenueGrantRepository
	 */
	private $grants;
	/**
	 * Venue membership persistence.
	 *
	 * @var VenueMembershipRepository
	 */
	private $venues;
	/**
	 * Promoter organization policy.
	 *
	 * @var PromoterAuthorization
	 */
	private $promoter_authorization;
	/**
	 * Delegated venue action policy.
	 *
	 * @var PromoterVenueAuthorization
	 */
	private $promoter_venue_authorization;
	/**
	 * Direct venue policy.
	 *
	 * @var VenueAuthorization
	 */
	private $venue_authorization;

	/**
	 * Whether capability checks compose the active execution principal.
	 *
	 * @var bool
	 */
	private $use_execution_principal;

	/**
	 * Construct from the canonical authorization repositories.
	 *
	 * @param PromoterAuthorityRepository|null  $promoters Promoter persistence override.
	 * @param PromoterVenueGrantRepository|null $grants    Grant persistence override.
	 * @param VenueMembershipRepository|null    $venues    Venue persistence override.
	 * @param bool                              $use_execution_principal Whether to apply an active agent principal.
	 */
	public function __construct(
		?PromoterAuthorityRepository $promoters = null,
		?PromoterVenueGrantRepository $grants = null,
		?VenueMembershipRepository $venues = null,
		bool $use_execution_principal = true
	) {
		$this->promoters                    = $promoters ? $promoters : new PromoterAuthorityRepository();
		$this->grants                       = $grants ? $grants : new PromoterVenueGrantRepository();
		$this->venues                       = $venues ? $venues : new VenueMembershipRepository();
		$this->use_execution_principal      = $use_execution_principal;
		$this->promoter_authorization       = new PromoterAuthorization( $this->promoters, $use_execution_principal );
		$this->promoter_venue_authorization = new PromoterVenueAuthorization( $this->promoters, $this->grants, $use_execution_principal );
		$this->venue_authorization          = new VenueAuthorization( $this->venues );
	}

	/** List only identities currently manageable by the effective actor. */
	public function identities() {
		return $this->identities_for_user( PromoterAuthorization::effective_user_id() );
	}

	/**
	 * List identities for an explicitly selected browser/internal WordPress actor.
	 *
	 * @param int $user_id Authenticated WordPress user ID.
	 */
	public function identities_for_user( int $user_id ) {
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return $this->forbidden();
		}
		$identities = array();
		$venue_ids  = $this->venues->list_active_venue_ids_for_user( $user_id );
		if ( is_wp_error( $venue_ids ) ) {
			return $venue_ids;
		}
		$is_admin = PromoterAuthorization::user_can( $user_id, 'manage_options', $this->use_execution_principal );
		if ( $is_admin ) {
			$administrator_venue_ids = $this->venues->list_active_venue_ids();
			if ( is_wp_error( $administrator_venue_ids ) ) {
				return $administrator_venue_ids;
			}
			$venue_ids = array_values( array_unique( array_merge( $venue_ids, $administrator_venue_ids ) ) );
			sort( $venue_ids );
		}
		foreach ( $venue_ids as $venue_term_id ) {
			$term = get_term( $venue_term_id, 'venue' );
			if ( ! $term || is_wp_error( $term ) || 'venue' !== $term->taxonomy ) {
				continue;
			}
			$membership = $this->venues->get_active( $venue_term_id, $user_id );
			if ( ! is_array( $membership ) && ! $is_admin ) {
				continue;
			}
			$permissions = array();
			foreach ( VenueAuthorization::actions() as $action ) {
				$allowed = true === $this->venue_authorization->authorize( $user_id, $venue_term_id, $action );
				if ( in_array( $action, array( VenueAuthorization::ACTION_ACCESS_VENUE, VenueAuthorization::ACTION_MANAGE_FINANCES ), true ) ) {
					$allowed = $allowed && is_array( $membership ) && PromoterAuthorization::user_can( $user_id, VenueAuthorization::ACCESS_CAPABILITY, $this->use_execution_principal );
				} elseif ( VenueAuthorization::ACTION_MANAGE_MEMBERS === $action && ! is_array( $membership ) ) {
					$allowed = $allowed && $is_admin;
				}
				if ( $allowed ) {
					$permissions[] = $action;
				}
			}
			$identities[] = array(
				'reference'   => 'venue:' . $venue_term_id,
				'type'        => 'venue',
				'id'          => (int) $venue_term_id,
				'name'        => (string) $term->name,
				'is_owner'    => is_array( $membership ) && (bool) $membership['is_owner'],
				'permissions' => $permissions,
			);
		}

		$memberships = $this->promoters->list_active_memberships_for_user( $user_id );
		if ( is_wp_error( $memberships ) ) {
			return $memberships;
		}
		foreach ( $memberships as $membership ) {
			$promoter_term_id = (int) $membership['promoter_term_id'];
			if ( true !== $this->promoter_authorization->authorize( $user_id, $promoter_term_id, PromoterAuthorization::ACTION_ACCESS_PROMOTER ) ) {
				continue;
			}
			$term = get_term( $promoter_term_id, 'promoter' );
			if ( ! $term || is_wp_error( $term ) || 'promoter' !== $term->taxonomy ) {
				continue;
			}
			$identities[] = array(
				'reference'   => 'promoter:' . $promoter_term_id,
				'type'        => 'promoter',
				'id'          => $promoter_term_id,
				'name'        => (string) $term->name,
				'is_owner'    => (bool) $membership['is_owner'],
				'permissions' => array( PromoterAuthorization::ACTION_ACCESS_PROMOTER ),
			);
		}
		if ( count( $identities ) > self::MAX_IDENTITIES ) {
			return new \WP_Error(
				'promoter_workspace_identity_limit_exceeded',
				__( 'The manageable identity list exceeds its supported bound.', 'extrachill-events' ),
				array(
					'status'  => 409,
					'maximum' => self::MAX_IDENTITIES,
				)
			);
		}
		$user = get_userdata( $user_id );
		return array(
			'actor'      => array(
				'id'   => $user_id,
				'name' => (string) ( $user->display_name ?? $user->user_login ?? '' ),
			),
			'identities' => $identities,
		);
	}

	/**
	 * Resolve one caller-selected typed identity without fallback.
	 *
	 * @param string $selected_reference Typed venue or promoter reference.
	 */
	public function resolve( string $selected_reference ) {
		return $this->resolve_for_user( PromoterAuthorization::effective_user_id(), $selected_reference );
	}

	/**
	 * Resolve context for an explicitly selected browser/internal WordPress actor.
	 *
	 * @param int    $user_id            Authenticated WordPress user ID.
	 * @param string $selected_reference Typed venue or promoter reference.
	 */
	public function resolve_for_user( int $user_id, string $selected_reference ) {
		$listed = $this->identities_for_user( $user_id );
		if ( is_wp_error( $listed ) ) {
			return $listed;
		}
		$result = array(
			'actor'                  => $listed['actor'],
			'identities'             => $listed['identities'],
			'selection'              => array(
				'reference' => $selected_reference,
				'state'     => '' === $selected_reference ? 'empty' : 'denied',
			),
			'promoter'               => null,
			'venue'                  => null,
			'granted_venues'         => array(),
			'promoter_relationships' => array(),
		);
		if ( '' === $selected_reference ) {
			return $result;
		}
		if ( ! preg_match( '/^(venue|promoter):([1-9][0-9]{0,9})$/', $selected_reference, $matches ) ) {
			return new \WP_Error( 'invalid_promoter_workspace_identity', __( 'A valid typed managed identity is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$type                        = $matches[1];
		$term_id                     = (int) $matches[2];
		$result['selection']['type'] = $type;
		$result['selection']['id']   = $term_id;
		$current                     = null;
		foreach ( $listed['identities'] as $identity ) {
			if ( $selected_reference === $identity['reference'] ) {
				$current = $identity;
				break;
			}
		}
		if ( ! $current ) {
			$result['selection']['state'] = $this->is_stale( $listed['actor']['id'], $type, $term_id ) ? 'stale' : 'denied';
			return $result;
		}
		$result['selection']['state'] = 'active';
		if ( 'venue' === $type ) {
			$result['venue'] = $current;
			if ( ! $current['is_owner'] ) {
				return $result;
			}
			$relationships = $this->grants->list_for_venue( $term_id );
			if ( is_wp_error( $relationships ) ) {
				return $relationships;
			}
			foreach ( $relationships as $relationship ) {
				$promoter = get_term( (int) $relationship['promoter_term_id'], 'promoter' );
				if ( ! $promoter || is_wp_error( $promoter ) || 'promoter' !== $promoter->taxonomy ) {
					continue;
				}
				$result['promoter_relationships'][] = array(
					'promoter_term_id' => (int) $relationship['promoter_term_id'],
					'promoter_name'    => (string) $promoter->name,
					'action'           => (string) $relationship['action'],
					'action_label'     => $this->action_label( (string) $relationship['action'] ),
					'status'           => (string) $relationship['status'],
				);
			}
			return $result;
		}

		$user_id = (int) $listed['actor']['id'];
		if ( true !== $this->promoter_authorization->authorize( $user_id, $term_id, PromoterAuthorization::ACTION_ACCESS_PROMOTER ) ) {
			$result['selection']['state'] = 'denied';
			return $result;
		}
		$result['promoter'] = array_merge( $current, array( 'link_page' => $this->link_page( $term_id ) ) );
		$venue_ids          = $this->promoter_venue_authorization->effective_venue_ids( $user_id, $term_id, PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT );
		if ( is_wp_error( $venue_ids ) ) {
			return $venue_ids;
		}
		foreach ( $venue_ids as $venue_term_id ) {
			$venue = get_term( $venue_term_id, 'venue' );
			if ( ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
				continue;
			}
			$result['granted_venues'][] = array(
				'id'           => (int) $venue_term_id,
				'name'         => (string) $venue->name,
				'action'       => PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT,
				'action_label' => $this->action_label( PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT ),
			);
		}
		return $result;
	}

	/**
	 * Determine whether a once-valid exact relationship is no longer current.
	 *
	 * @param int    $user_id Network user ID.
	 * @param string $type    Managed identity type.
	 * @param int    $term_id Exact taxonomy term ID.
	 */
	private function is_stale( int $user_id, string $type, int $term_id ): bool {
		$term = get_term( $term_id, $type );
		if ( ! $term || is_wp_error( $term ) || $type !== $term->taxonomy ) {
			return true;
		}
		$membership = 'venue' === $type ? $this->venues->get( $term_id, $user_id ) : $this->promoters->get_membership( $term_id, $user_id );
		if ( ! is_array( $membership ) ) {
			return false;
		}
		if ( 'venue' === $type ) {
			return VenueAuthorization::STATUS_ACTIVE !== $membership['status'];
		}
		$organization = $this->promoters->get_organization( $term_id );
		return PromoterAuthorityRepository::STATUS_ACTIVE !== $membership['status'] || ! is_array( $organization ) || PromoterAuthorityRepository::STATUS_ACTIVE !== $organization['status'];
	}

	/**
	 * Return Link Page state only when its coordinated runtime is available.
	 *
	 * @param int $promoter_term_id Exact promoter term ID.
	 */
	private function link_page( int $promoter_term_id ): array {
		if ( ! class_exists( PromoterLinkPages::class ) || ! function_exists( 'ec_get_link_page_id_for_owner' ) ) {
			return array(
				'status'         => 'unavailable',
				'management_url' => '',
			);
		}
		$reference = PromoterLinkPages::owner_reference( $promoter_term_id );
		$page_id   = is_wp_error( $reference ) ? $reference : ec_get_link_page_id_for_owner( $reference );
		if ( is_wp_error( $page_id ) ) {
			return array(
				'status'         => 'unavailable',
				'management_url' => '',
			);
		}
		return array(
			'status'         => $page_id ? 'available' : 'not_provisioned',
			'management_url' => PromoterLinkPages::management_url( $promoter_term_id ),
		);
	}

	/**
	 * Human label for the only delegated action currently supported.
	 *
	 * @param string $action Delegated action key.
	 */
	private function action_label( string $action ): string {
		return PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT === $action ? __( 'Organize local support', 'extrachill-events' ) : '';
	}

	/** Build a non-enumerating self-scope denial. */
	private function forbidden(): \WP_Error {
		return new \WP_Error( 'promoter_workspace_forbidden', __( 'You are not authorized to access this managed workspace.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
