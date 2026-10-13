<?php
/** Trust policy for public event submissions. */

namespace ExtraChillEvents\Abilities;

defined( 'ABSPATH' ) || exit;

/** Determines whether a submitter account may bypass manual event review. */
final class SubmissionTrustPolicy {
	public static function trusts( int $user_id, bool $authenticated ): bool {
		if ( ! $authenticated || $user_id <= 0 || '1' === (string) get_user_meta( $user_id, 'ec_unclaimed', true ) ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( $user && in_array( 'extra_chill_team', (array) $user->roles, true ) ) {
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
