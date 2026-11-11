<?php
/**
 * Venue and promoter adapter for the network team onboarding lifecycle.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

use ExtraChill\Users\Teams\TeamOnboardingAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Taxonomy anchor, Events authorization and invitation copy for team onboarding. */
class VenueOnboardingAdapter implements TeamOnboardingAdapter {

	/** Team types this adapter serves, with their supported operations. */
	public const SUPPORT = array(
		'venue'    => array( 'invitations', 'claims' ),
		'promoter' => array( 'invitations' ),
	);

	/** Accept-route action shared by venue and promoter invitations. */
	public const ACCEPT_ACTION = 'ec_accept_venue_invitation';

	/** Team type. */
	private $type;

	/** Events authorization. */
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

	/** Venue and promoter share the historical `venue_*` public error codes. */
	public function error_code( string $code ): string {
		return str_replace( 'team', 'venue', $code );
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
		return $this->authorization->authorize_locked( $actor_user_id, $this->type, $team_id, TeamAuthorization::ACTION_MANAGE_MEMBERS, $members );
	}

	public function check_team( int $team_id, bool $lock ) {
		global $wpdb;
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

	/** Build the signed accept-route URL for one invitation. */
	public static function accept_url( string $type, array $invitation, string $token ): string {
		return add_query_arg(
			array(
				'action'        => self::ACCEPT_ACTION,
				'invitation_id' => $invitation['public_id'],
				'token'         => $token,
				'venue_term_id' => (int) ( $invitation['team_id'] ?? $invitation['team_term_id'] ?? 0 ),
				'team_type'     => $type,
				'is_owner'      => ! empty( $invitation['is_owner'] ) ? 1 : 0,
				'version'       => (int) $invitation['version'],
			),
			admin_url( 'admin-post.php' )
		);
	}
}
