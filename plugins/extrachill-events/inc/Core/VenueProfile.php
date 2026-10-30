<?php
/**
 * Authorized composition over the canonical venue profile owner.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Adds Extra Chill authorization and audit policy to the DME venue contract. */
class VenueProfile {

	public const HISTORY_META_KEY = '_extrachill_venue_profile_history';

	/**
	 * Exact venue-membership authorization service.
	 *
	 * @var VenueAuthorization
	 */
	private $authorization;

	/**
	 * Construct the profile composition service.
	 *
	 * @param VenueAuthorization|null $authorization Optional authorization service.
	 */
	public function __construct( ?VenueAuthorization $authorization = null ) {
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
	}

	/**
	 * Read one canonical profile after exact active-membership authorization.
	 *
	 * @param int $venue_term_id Canonical venue term ID.
	 * @param int $actor_user_id Requesting user ID.
	 * @return array|\WP_Error
	 */
	public function get( int $venue_term_id, int $actor_user_id ) {
		$allowed = $this->authorize( $venue_term_id, $actor_user_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! function_exists( 'data_machine_events_get_venue_profile' ) ) {
			return $this->dependency_error();
		}

		return data_machine_events_get_venue_profile( $venue_term_id );
	}

	/**
	 * Apply canonical profile changes and append the consumer-owned audit record.
	 *
	 * Canonical persistence, sanitization, optimistic concurrency, location
	 * derivation, and transaction handling remain entirely in Data Machine Events.
	 *
	 * @param int    $venue_term_id     Canonical venue term ID.
	 * @param array  $changes           Bounded canonical profile changes.
	 * @param string $expected_revision DME revision observed by the caller.
	 * @param int    $actor_user_id     Requesting user ID.
	 * @return array|\WP_Error
	 */
	public function update( int $venue_term_id, array $changes, string $expected_revision, int $actor_user_id ) {
		$allowed = $this->authorize( $venue_term_id, $actor_user_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! function_exists( 'data_machine_events_update_venue_profile' ) ) {
			return $this->dependency_error();
		}

		$result = data_machine_events_update_venue_profile( $venue_term_id, $changes, $expected_revision );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$changed_fields = array_values(
			array_intersect(
				(array) ( $result['updated_fields'] ?? array() ),
				array( 'name', 'description', 'address', 'city', 'state', 'zip', 'country', 'phone', 'website', 'capacity' )
			)
		);
		if ( empty( $changed_fields ) ) {
			return $result;
		}

		$audit        = array(
			'version'           => 1,
			'previous_revision' => $expected_revision,
			'revision'          => (string) ( $result['revision'] ?? '' ),
			'actor_user_id'     => $actor_user_id,
			'changed_fields'    => $changed_fields,
			'occurred_at'       => gmdate( 'Y-m-d H:i:s' ),
		);
		$audit_result = add_term_meta( $venue_term_id, self::HISTORY_META_KEY, $audit );
		if ( is_wp_error( $audit_result ) || false === $audit_result ) {
			return new \WP_Error(
				'venue_profile_audit_failed',
				__( 'The venue profile was updated, but its audit record could not be saved.', 'extrachill-events' ),
				array(
					'status'             => 500,
					'mutation_committed' => true,
					'revision'           => $audit['revision'],
				)
			);
		}

		do_action( 'extrachill_events_venue_profile_updated', $venue_term_id, $actor_user_id, $result, $audit );
		return $result;
	}

	/**
	 * Enforce canonical-site context and exact active membership.
	 *
	 * @param int $venue_term_id Canonical venue term ID.
	 * @param int $actor_user_id Requesting user ID.
	 * @return true|\WP_Error
	 */
	private function authorize( int $venue_term_id, int $actor_user_id ) {
		if ( ! function_exists( 'ec_get_blog_id' ) || ! function_exists( 'get_current_blog_id' ) || (int) get_current_blog_id() !== (int) ec_get_blog_id( 'events' ) ) {
			return new \WP_Error( 'canonical_events_site_required', __( 'Venue profiles must be managed on the canonical Events site.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		return $this->authorization->authorize( $actor_user_id, $venue_term_id, VenueAuthorization::ACTION_ACCESS_VENUE );
	}

	/** Return a stable error when the canonical owner contract is unavailable. */
	private function dependency_error(): \WP_Error {
		return new \WP_Error( 'venue_profile_owner_unavailable', __( 'Canonical venue profile management is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
	}
}
