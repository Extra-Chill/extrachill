<?php
/**
 * Online-users metric provider.
 *
 * Canonical home for the network-wide "online now" count. Counts community-blog
 * users whose `last_active` user meta falls within the last 15 minutes, read
 * directly from the shared usermeta table in the community blog context.
 *
 * This provider OWNS the query (it no longer wraps ec_get_online_users_count()
 * in extrachill-users — that would be circular now that the count function is a
 * thin shim over this primitive). The engine's per-metric transient is the
 * single cache for this number; the extrachill-users activity recorder busts it
 * via ec_network_stats_forget('online_users') when a user becomes active.
 *
 * @package ExtraChillNetwork\NetworkStats\Providers
 * @since   1.19.0
 */

namespace ExtraChillNetwork\NetworkStats\Providers;

use ExtraChillNetwork\NetworkStats\AbstractMetricProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Network-wide "online now" count.
 */
class OnlineUsersProvider extends AbstractMetricProvider {

	/**
	 * Activity window: users active within this many seconds count as online.
	 */
	const ACTIVITY_WINDOW = 15 * MINUTE_IN_SECONDS;

	/**
	 * Construct the online-users provider.
	 */
	public function __construct() {
		parent::__construct( 'online_users', __( 'Online Now', 'extrachill-network' ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Counts community-blog users with a `last_active` timestamp inside the
	 * activity window. Returns null (NOT 0) when the community blog cannot be
	 * resolved, so the engine honestly reports "not available" rather than a
	 * fabricated zero.
	 *
	 * @return int|null Online-user count, or null if the community blog is unavailable.
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
			global $wpdb;

			$time_threshold = time() - self::ACTIVITY_WINDOW;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'last_active' AND meta_value > %d",
					$time_threshold
				)
			);

			return (int) $count;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
}
