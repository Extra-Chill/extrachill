<?php
/**
 * Total-members metric provider.
 *
 * Wraps the existing footer-widget member count: count_users()['total_users']
 * cached on the community blog in the `total_members_count` transient (1 day).
 * Users are network-wide (shared user table), so count_users() returns the
 * whole-network member total. This provider reuses the SAME transient the
 * footer widget warms, so the two numbers never diverge.
 *
 * @package ExtraChillNetwork\NetworkStats\Providers
 * @since   1.19.0
 */

namespace ExtraChillNetwork\NetworkStats\Providers;

use ExtraChillNetwork\NetworkStats\AbstractMetricProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Total registered members across the network.
 */
class TotalMembersProvider extends AbstractMetricProvider {

	/**
	 * Construct the total-members provider.
	 */
	public function __construct() {
		parent::__construct( 'total_members', __( 'Total Members', 'extrachill-network' ), DAY_IN_SECONDS );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Reuses the footer widget's `total_members_count` transient on the
	 * community blog. Computes + warms it if cold, mirroring
	 * extrachill_users_display_online_stats().
	 */
	public function value() {
		$blog_id = $this->resolve_blog_id( 'community' );
		if ( null === $blog_id ) {
			return null;
		}

		$switched = false;
		if ( (int) get_current_blog_id() !== $blog_id && function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		try {
			$total = get_transient( 'total_members_count' );
			if ( false === $total ) {
				$user_count = count_users();
				$total      = isset( $user_count['total_users'] ) ? (int) $user_count['total_users'] : null;
				if ( null !== $total ) {
					set_transient( 'total_members_count', $total, DAY_IN_SECONDS );
				}
			}

			return null === $total ? null : (int) $total;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
}
