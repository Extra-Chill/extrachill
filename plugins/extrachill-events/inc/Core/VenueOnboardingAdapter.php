<?php
namespace ExtraChillEvents\Core;

use ExtraChill\Users\Teams\TeamOnboardingAdapter;

/** Taxonomy anchor and Events authorization for team onboarding. */
class VenueOnboardingAdapter implements TeamOnboardingAdapter {
	private $type;
	private $authorization;
	public function __construct( string $type = 'venue', ?TeamAuthorization $authorization = null ) {
		if ( ! in_array( $type, array( 'venue', 'promoter' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported onboarding team type.' );
		}
		$this->type = $type;
		$this->authorization = $authorization ?: new TeamAuthorization();
	}
	public function team_type(): string { return $this->type; }
	public function operations(): array { return 'venue' === $this->type ? array( 'invitations', 'claims' ) : array( 'invitations' ); }
	public function error_code( string $code ): string { return str_replace( 'team', 'venue', $code ); }
	public function public_record( array $record ): array {
		if ( 'venue' === $this->type ) {
			$record['venue_term_id'] = (int) $record['team_id'];
		}
		return $record;
	}
	public function authorize_locked( int $actor_user_id, int $team_id, array $members ) {
		return $this->authorization->authorize_locked( $actor_user_id, $this->type, $team_id, TeamAuthorization::ACTION_MANAGE_MEMBERS, $members );
	}
	public function check_team( int $team_id, bool $lock ) {
		global $wpdb;
		if ( $lock ) {
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d FOR UPDATE", $team_id ) );
			$valid = (int) $id === $team_id && '' === (string) $wpdb->last_error;
		} else {
			$term = get_term( $team_id, $this->type );
			$valid = $team_id > 0 && $term && ! is_wp_error( $term ) && $this->type === $term->taxonomy;
		}
		return $valid ? true : new \WP_Error( 'invalid_venue_membership_venue', __( 'A valid Events venue term is required.', 'extrachill-events' ), array( 'status' => 400 ) );
	}
}
