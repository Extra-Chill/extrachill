<?php
/**
 * Event vendor request authorization.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

use function is_wp_error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Revalidates canonical event, exact venue, and coordinator authority. */
class VendorRequestAuthorization {

	/** @var VenueAuthorization */
	private $venues;

	public function __construct( ?VenueAuthorization $venues = null ) {
		$this->venues = $venues ? $venues : new VenueAuthorization();
	}

	/** Resolve one canonical event bound to exactly one venue. */
	public function event_context( int $event_id ) {
		$post = get_post( $event_id );
		if ( ! $post || 'data_machine_events' !== $post->post_type || 'trash' === $post->post_status ) {
			return new \WP_Error( 'invalid_vendor_request_event', __( 'A valid canonical event is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$venues = wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $venues ) || 1 !== count( (array) $venues ) ) {
			return new \WP_Error( 'invalid_vendor_request_venue', __( 'The event must have one canonical venue.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return array(
			'event_id'      => $event_id,
			'venue_term_id' => (int) reset( $venues ),
		);
	}

	/** Require current exact-venue organizer authority. */
	public function authorize_organizer( array $request, int $user_id ) {
		$context = $this->event_context( (int) $request['event_id'] );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( (int) $request['venue_term_id'] !== $context['venue_term_id'] ) {
			return $this->denied();
		}
		$allowed = $this->venues->authorize( $user_id, $context['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		return true === $allowed ? true : $this->denied();
	}

	/** Require the persisted coordinator and current venue authority. */
	public function authorize_coordinator( array $request, int $user_id ) {
		if ( $user_id < 1 || (int) $request['coordinator_user_id'] !== $user_id ) {
			return $this->denied();
		}
		return $this->authorize_organizer( $request, $user_id );
	}

	private function denied(): \WP_Error {
		return new \WP_Error( 'vendor_request_forbidden', __( 'This private vendor request is unavailable.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
