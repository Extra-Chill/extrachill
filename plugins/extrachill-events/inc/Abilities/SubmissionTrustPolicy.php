<?php
/**
 * Who may skip moderation on public submissions.
 *
 * Trust is expressed through WordPress capabilities, never role names, so
 * the role owner (extrachill-users) decides who holds them:
 *
 * - `manage_options`: site administrators (and network super admins).
 * - `access_events_admin` (VenueAuthorization::ACCESS_CAPABILITY): the
 *   events staff capability, granted today by the Extra Chill Team role.
 *
 * @package ExtraChillEvents
 */

namespace ExtraChillEvents\Abilities;

defined( 'ABSPATH' ) || exit;

final class SubmissionTrustPolicy {
	/** Capabilities that mark a user as events staff. */
	public const STAFF_CAPABILITIES = array( 'manage_options', 'access_events_admin' );

	/**
	 * Events staff: their submissions publish or activate without review.
	 *
	 * @param int $user_id User ID.
	 */
	public static function isStaff( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		foreach ( self::STAFF_CAPABILITIES as $capability ) {
			if ( user_can( $user_id, $capability ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- access_events_admin is granted by the team role.
				return true;
			}
		}
		return false;
	}

	/**
	 * Single-event trust: staff, or a claimed account with a prior published event.
	 *
	 * @param int  $user_id       User ID.
	 * @param bool $authenticated Whether the request is authenticated as that user.
	 */
	public static function trusts( int $user_id, bool $authenticated ): bool {
		if ( ! $authenticated || $user_id <= 0 || '1' === (string) get_user_meta( $user_id, 'ec_unclaimed', true ) ) {
			return false;
		}

		if ( self::isStaff( $user_id ) ) {
			return true;
		}

		return (bool) get_posts(
			array(
				'post_type'      => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_datamachine_submitted_by',
				'meta_value'     => (string) $user_id,
			)
		);
	}
}
