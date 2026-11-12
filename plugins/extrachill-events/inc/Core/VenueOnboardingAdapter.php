<?php
/**
 * Venue and promoter adapter for the network team onboarding lifecycle.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

use ExtraChill\Users\Teams\TeamOnboardingAdapter;
use ExtraChill\Users\Teams\TeamMembershipRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Taxonomy anchor, Events authorization and invitation copy for team onboarding. */
class VenueOnboardingAdapter implements TeamOnboardingAdapter {

	/** @var array<string, mixed>|\WP_Error|null Organization read under the anchor lock. */
	private $locked_organization;

	/** Team types this adapter serves, with their supported operations. */
	public const SUPPORT = array(
		'venue'    => array( 'invitations', 'claims' ),
		'promoter' => array( 'invitations' ),
	);

	/** Accept-route action shared by venue and promoter invitations. */
	public const ACCEPT_ACTION = 'ec_accept_team_invitation';

	/** @var string Team type. */
	private $type;

	/** @var TeamAuthorization Events authorization. */
	private $authorization;

	public function __construct( string $type = 'venue', ?TeamAuthorization $authorization = null ) {
		if ( ! isset( self::SUPPORT[ $type ] ) ) {
			throw new \InvalidArgumentException( 'Unsupported onboarding team type.' );
		}
		$this->type          = $type;
		$this->authorization = $authorization ? $authorization : new TeamAuthorization();
	}

	/** Register both Events adapters with the users onboarding registry. */
	public static function register( array $adapters ): array {
		foreach ( array_keys( self::SUPPORT ) as $type ) {
			$adapters[ $type ] = new self( $type );
		}
		return $adapters;
	}

	public function team_type(): string {
		return $this->type;
	}

	public function operations(): array {
		return self::SUPPORT[ $this->type ];
	}

	/** Preserve the adapter's team type in public error codes. */
	public function error_code( string $code ): string {
		return str_replace( 'team', $this->type, $code );
	}

	public function public_record( array $record ): array {
		if ( 'venue' === $this->type && isset( $record['team_id'] ) ) {
			$record['venue_term_id'] = (int) $record['team_id'];
		}
		return $record;
	}

	public function registration_source(): string {
		return 'venue_invitation';
	}

	public function authorize( int $actor_user_id, int $team_id ) {
		return $this->authorization->authorize( $actor_user_id, $this->type, $team_id, TeamAuthorization::ACTION_MANAGE_MEMBERS );
	}

	public function authorize_locked( int $actor_user_id, int $team_id, array $members ) {
		if ( 'promoter' === $this->type ) {
			$organization = $this->locked_organization ?? ( new PromoterAuthorityRepository() )->get_organization( $team_id );
			if ( is_wp_error( $organization ) ) {
				return $organization;
			}
			if ( is_array( $organization ) && TeamMembershipRepository::STATUS_ACTIVE === $organization['status'] ) {
				if ( TeamAuthorization::is_site_admin( $actor_user_id ) ) {
					return true;
				}
				if ( TeamAuthorization::principal_allows( $actor_user_id, VenueAuthorization::ACCESS_CAPABILITY ) ) {
					foreach ( $members as $member ) {
						if ( $actor_user_id === $member['user_id'] && $member['is_owner'] && TeamMembershipRepository::STATUS_ACTIVE === $member['status'] ) {
							return true;
						}
					}
				}
			}
			return new \WP_Error( 'promoter_authority_forbidden', __( 'You are not authorized to perform this promoter action.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		return $this->authorization->authorize_locked( $actor_user_id, $this->type, $team_id, TeamAuthorization::ACTION_MANAGE_MEMBERS, $members );
	}

	public function check_team( int $team_id, bool $lock ) {
		global $wpdb;
		if ( $lock && 'promoter' === $this->type ) {
			$table                     = PromoterAuthoritySchema::organizations_table();
			$row                       = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE promoter_term_id = %d FOR UPDATE', $table, $team_id ), ARRAY_A ); // phpcs:ignore WordPress.DB -- Serialize the promoter organization before its members.
			$this->locked_organization = null;
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'promoter_membership_read_failed', __( 'Promoter authority could not be locked.', 'extrachill-events' ), array( 'status' => 500 ) );
			}
			$this->locked_organization = is_array( $row ) ? ( new PromoterAuthorityRepository() )->hydrate_organization( $row ) : null;
			return is_wp_error( $this->locked_organization ) ? $this->locked_organization : true;
		}
		if ( $lock ) {
			$id    = $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d FOR UPDATE", $team_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction anchor lock.
			$valid = (int) $id === $team_id && '' === (string) $wpdb->last_error;
		} else {
			$term  = get_term( $team_id, $this->type );
			$valid = $team_id > 0 && $term && ! is_wp_error( $term ) && $this->type === $term->taxonomy;
		}
		return $valid ? true : new \WP_Error( 'invalid_venue_membership_venue', __( 'A valid Events venue term is required.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	public function invitation_email( array $invitation, string $token ) {
		$team_id = (int) ( $invitation['team_id'] ?? $invitation['team_term_id'] ?? 0 );
		$term    = get_term( $team_id, $this->type );
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'venue_invitation_team_missing', __( 'The invited venue no longer exists.', 'extrachill-events' ) );
		}
		return array(
			/* translators: %s: Venue or promoter name. */
			'subject'      => sprintf( __( 'Invitation to manage %s', 'extrachill-events' ), $term->name ),
			/* translators: %s: Venue or promoter name. */
			'intro'        => sprintf( __( 'You have been invited to help manage %s on Extra Chill.', 'extrachill-events' ), $term->name ),
			'accept_label' => __( 'Accept venue invitation', 'extrachill-events' ),
			'from_name'    => 'Extra Chill Events',
			'accept_url'   => self::accept_url( $this->type, $invitation, $token ),
		);
	}

	public function accepted_url( int $team_id, bool $accepted ): string {
		$term   = get_term( $team_id, $this->type );
		$target = $term && ! is_wp_error( $term ) ? get_term_link( $term ) : home_url( '/' );
		if ( is_wp_error( $target ) ) {
			$target = home_url( '/' );
		}
		return add_query_arg( 'venue_invitation', $accepted ? 'accepted' : 'failed', $target );
	}

	/** Build the signed accept-route URL for one invitation. */
	public static function accept_url( string $type, array $invitation, string $token ): string {
		return add_query_arg(
			array(
				'action'        => self::ACCEPT_ACTION,
				'invitation_id' => $invitation['public_id'],
				'token'         => $token,
				'team_id'       => (int) ( $invitation['team_id'] ?? $invitation['team_term_id'] ?? 0 ),
				'team_type'     => $type,
				'is_owner'      => ! empty( $invitation['is_owner'] ) ? 1 : 0,
				'version'       => (int) $invitation['version'],
			),
			admin_url( 'admin-post.php' )
		);
	}
}
