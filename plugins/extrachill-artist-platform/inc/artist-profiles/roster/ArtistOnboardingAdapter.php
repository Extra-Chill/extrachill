<?php
/** Artist boundaries for network team invitations. */

namespace ExtraChillArtistPlatform;

use ExtraChill\Users\Teams\TeamOnboardingAdapter;

defined( 'ABSPATH' ) || exit;

class ArtistOnboardingAdapter implements TeamOnboardingAdapter {
	public static function register( array $adapters ): array {
		$adapters['artist'] = new self();
		return $adapters;
	}

	public function team_type(): string { return 'artist'; }
	public function operations(): array { return array( 'invitations' ); }
	public function error_code( string $code ): string { return str_replace( 'team', 'artist', $code ); }
	public function public_record( array $record ): array { return $record; }
	public function registration_source(): string { return 'artist_invitation'; }

	public function check_team( int $team_id, bool $lock ) {
		global $wpdb;
		$blog_id = (int) ec_get_blog_id( 'artist' );
		$valid   = $blog_id > 0 && $team_id > 0;
		if ( $valid && $lock ) {
			$table = $wpdb->get_blog_prefix( $blog_id ) . 'posts';
			$id    = $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID = %d FOR UPDATE', $table, $team_id ) );
			$valid = (int) $id === $team_id && '' === (string) $wpdb->last_error;
		}
		if ( $valid ) {
			switch_to_blog( $blog_id );
			try {
				$post  = get_post( $team_id );
				$valid = $post && 'artist_profile' === $post->post_type && 'publish' === $post->post_status;
			} finally {
				restore_current_blog();
			}
		}
		return $valid ? true : new \WP_Error( 'invalid_artist_membership_artist', __( 'A published artist profile is required.', 'extrachill-artist-platform' ), array( 'status' => 400 ) );
	}

	private function denied() {
		return new \WP_Error( 'permission_denied', __( 'You do not have permission to manage members for this artist.', 'extrachill-artist-platform' ), array( 'status' => 403 ) );
	}

	public function authorize( int $actor_user_id, int $team_id ) {
		return ec_can_manage_artist( $actor_user_id, $team_id ) ? true : $this->denied();
	}

	public function authorize_locked( int $actor_user_id, int $team_id, array $members ) {
		if ( $actor_user_id > 0 && user_can( $actor_user_id, 'manage_options' ) ) {
			return true;
		}
		foreach ( $members as $member ) {
			if ( $actor_user_id > 0 && (int) $member['user_id'] === $actor_user_id && 'active' === $member['status'] ) {
				return true;
			}
		}
		return $this->denied();
	}

	public function accepted_url( int $team_id, bool $accepted ): string {
		switch_to_blog( ec_get_blog_id( 'artist' ) );
		try {
			$target = get_permalink( $team_id );
			return add_query_arg( 'artist_invitation', $accepted ? 'accepted' : 'failed', $target ? $target : home_url( '/' ) );
		} finally {
			restore_current_blog();
		}
	}

	public function invitation_email( array $invitation, string $token ) {
		$id    = (int) $invitation['team_id'];
		$valid = $this->check_team( $id, false );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		switch_to_blog( ec_get_blog_id( 'artist' ) );
		try {
			$name    = get_the_title( $id );
			$inviter = get_userdata( (int) ( $invitation['invited_by_user_id'] ?? 0 ) );
			return array(
				/* translators: 1: Artist name, 2: Site name. */
				'subject'      => sprintf( __( 'You\'re invited to join %1$s on %2$s!', 'extrachill-artist-platform' ), $name, get_bloginfo( 'name' ) ),
				/* translators: 1: Inviter name, 2: Artist name, 3: Site name. */
				'intro'        => sprintf( __( '%1$s has invited you to join the artist “%2$s” on %3$s.', 'extrachill-artist-platform' ), $inviter ? $inviter->display_name : __( 'An artist member', 'extrachill-artist-platform' ), $name, get_bloginfo( 'name' ) ),
				'accept_label' => __( 'Accept invitation', 'extrachill-artist-platform' ),
				'from_name'    => 'Extra Chill Community',
				'accept_url'   => add_query_arg( array(
					'action'        => 'ec_accept_team_invitation',
					'team_type'     => 'artist',
					'team_id'       => $id,
					'invitation_id' => $invitation['public_id'],
					'token'         => $token,
					'is_owner'      => empty( $invitation['is_owner'] ) ? 0 : 1,
					'version'       => (int) $invitation['version'],
				), admin_url( 'admin-post.php' ) ),
			);
		} finally {
			restore_current_blog();
		}
	}
}
add_filter( 'extrachill_users_team_onboarding_adapters', array( ArtistOnboardingAdapter::class, 'register' ) );
