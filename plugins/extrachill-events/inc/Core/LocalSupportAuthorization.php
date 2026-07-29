<?php
/**
 * Local support identity and authorization policy.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates canonical event bindings and exact organizer/artist authority. */
class LocalSupportAuthorization {

	/** @var VenueAuthorization */
	private $venues;

	public function __construct( ?VenueAuthorization $venues = null ) {
		$this->venues = $venues ? $venues : new VenueAuthorization();
	}

	/** Resolve a valid canonical event and its one exact venue. */
	public function event_context( int $event_id ) {
		$post = get_post( $event_id );
		if ( ! $post || 'data_machine_events' !== $post->post_type || 'trash' === $post->post_status ) {
			return new \WP_Error( 'invalid_local_support_event', __( 'A valid canonical event is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$venues = wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $venues ) || 1 !== count( (array) $venues ) ) {
			return new \WP_Error( 'invalid_local_support_event_venue', __( 'The event must have one canonical venue.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$venue_id = (int) reset( $venues );
		$venue    = get_term( $venue_id, 'venue' );
		if ( ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error( 'invalid_local_support_event_venue', __( 'The event venue binding is invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return array(
			'event_id'      => $event_id,
			'venue_term_id' => $venue_id,
		);
	}

	/** Authorize and validate the organizer identity against the exact event. */
	public function authorize_organizer( array $request, int $user_id ) {
		$context = $this->event_context( (int) $request['event_id'] );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( (int) $request['venue_term_id'] !== $context['venue_term_id'] ) {
			return $this->denied();
		}
		if ( 'venue' === $request['organizer_type'] ) {
			if ( (int) $request['organizer_id'] !== $context['venue_term_id'] ) {
				return $this->denied();
			}
			return $this->venues->authorize( $user_id, $context['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		}
		if ( 'artist' !== $request['organizer_type'] ) {
			return $this->denied();
		}
		$artist_id = (int) $request['organizer_id'];
		$attached  = $this->artist_attached_to_event( $context['event_id'], $artist_id );
		if ( true !== $attached ) {
			return is_wp_error( $attached ) ? $attached : $this->denied();
		}
		return $this->authorize_artist( $artist_id, $user_id );
	}

	/** Authorize one canonical artist manager through its bound profile. */
	public function authorize_artist( int $artist_term_id, int $user_id ) {
		$profile_id = $this->artist_profile_id( $artist_term_id );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		if ( ! function_exists( 'ec_get_artists_for_user' ) || ! in_array( $profile_id, array_map( 'intval', ec_get_artists_for_user( $user_id ) ), true ) ) {
			return $this->denied();
		}
		return true;
	}

	/** Determine whether a canonical artist is explicitly attached to the event. */
	public function artist_attached_to_event( int $event_id, int $artist_term_id ) {
		if ( ! function_exists( 'extrachill_events_resolve_artist_term' ) ) {
			return new \WP_Error( 'local_support_artist_mapping_unavailable', __( 'Canonical artist mapping is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$mapped = extrachill_events_resolve_artist_term( $artist_term_id );
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}
		$artists = wp_get_object_terms( $event_id, 'artist', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $artists ) ) {
			return new \WP_Error( 'local_support_event_artists_unavailable', __( 'Event artist bindings could not be validated.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return in_array( (int) $mapped['term_id'], array_map( 'intval', (array) $artists ), true );
	}

	/** Resolve and verify a canonical term's bidirectional Artist Platform profile. */
	private function artist_profile_id( int $artist_term_id ) {
		$main_blog_id   = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
		$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
		if ( $main_blog_id < 1 || $artist_blog_id < 1 || $artist_term_id < 1 ) {
			return new \WP_Error( 'invalid_local_support_artist', __( 'A valid canonical artist is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		switch_to_blog( $main_blog_id );
		try {
			$term       = get_term( $artist_term_id, 'artist' );
			$profile_id = $term && ! is_wp_error( $term ) ? absint( get_term_meta( $artist_term_id, '_artist_profile_id', true ) ) : 0;
		} finally {
			restore_current_blog();
		}
		if ( $profile_id < 1 ) {
			return new \WP_Error( 'invalid_local_support_artist', __( 'The canonical artist has no bound profile.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		switch_to_blog( $artist_blog_id );
		try {
			$profile = get_post( $profile_id );
			$bound   = absint( get_post_meta( $profile_id, '_artist_term_id', true ) );
		} finally {
			restore_current_blog();
		}
		if ( ! $profile || 'artist_profile' !== $profile->post_type || 'publish' !== $profile->post_status || $bound !== $artist_term_id ) {
			return new \WP_Error( 'invalid_local_support_artist', __( 'The artist profile binding is invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return $profile_id;
	}

	/** Return a non-enumerating denial. */
	private function denied(): \WP_Error {
		return new \WP_Error( 'local_support_forbidden', __( 'You are not authorized for this local support request.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
