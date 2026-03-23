<?php
/**
 * Cache CLI Commands
 *
 * @package ExtraChill\CLI\Commands\Cache
 */

namespace ExtraChill\CLI\Commands\Cache;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WarmCommand {

	/**
	 * Warm badge count caches for the current site.
	 *
	 * Pre-computes taxonomy badge counts. This is the same operation
	 * that runs on cron every 4 hours. Run with --url to target a
	 * specific site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Warm events site badge counts
	 *     wp extrachill cache warm --url=events.extrachill.com
	 *
	 *     # Warm blog site badge counts
	 *     wp extrachill cache warm
	 *
	 * @subcommand warm
	 */
	public function warm( $args, $assoc_args ) {
		if ( ! function_exists( 'ec_badge_warmer_run' ) ) {
			WP_CLI::error( 'Badge count warmer not available. Is extrachill-multisite active?' );
		}

		WP_CLI::log( 'Warming badge count caches for ' . home_url() . '...' );

		$start  = microtime( true );
		$warmed = ec_badge_warmer_run();
		$elapsed = round( microtime( true ) - $start, 2 );

		if ( empty( $warmed ) ) {
			WP_CLI::log( 'No badge counts to warm on this site.' );
			return;
		}

		WP_CLI::success( sprintf(
			'Warmed %d cache entries in %ss.',
			count( $warmed ),
			$elapsed
		) );
	}

	/**
	 * Show badge count transient status for the current site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill cache status --url=events.extrachill.com
	 *     wp extrachill cache status
	 *
	 * @subcommand status
	 */
	public function status( $args, $assoc_args ) {
		$next = wp_next_scheduled( 'ec_warm_badge_counts' );
		if ( $next ) {
			$diff = $next - time();
			$mins = round( $diff / 60 );
			WP_CLI::log( sprintf( 'Next scheduled warm: %s (%s min from now)', gmdate( 'Y-m-d H:i:s', $next ), $mins ) );
		} else {
			WP_CLI::warning( 'No warm scheduled on this site.' );
		}

		$blog_id        = get_current_blog_id();
		$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
		$main_blog_id   = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;

		if ( $blog_id === $events_blog_id ) {
			$taxonomies = array( 'location', 'venue', 'artist', 'festival' );
			foreach ( $taxonomies as $taxonomy ) {
				$cached = get_transient( 'ec_upcoming_counts_' . $taxonomy );
				if ( false !== $cached ) {
					WP_CLI::log( sprintf( '  events/%s: WARM (%d terms)', $taxonomy, count( $cached ) ) );
				} else {
					WP_CLI::log( sprintf( '  events/%s: COLD', $taxonomy ) );
				}
			}

			$stats = get_transient( 'extrachill_calendar_stats' );
			WP_CLI::log( $stats ? '  events/calendar-stats: WARM' : '  events/calendar-stats: COLD' );
		} elseif ( $blog_id === $main_blog_id ) {
			// Blog homepage reads these via rest_do_request which uses switch_to_blog.
			// The transients live in the switched context — check them the same way.
			if ( $events_blog_id ) {
				switch_to_blog( $events_blog_id );
				$cached = get_transient( 'ec_upcoming_counts_location' );
				WP_CLI::log( $cached !== false
					? sprintf( '  blog/location-events: WARM (%d terms)', count( $cached ) )
					: '  blog/location-events: COLD' );
				restore_current_blog();
			}

			$wire_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'wire' ) : null;
			if ( $wire_blog_id ) {
				switch_to_blog( $wire_blog_id );
				$cached = get_transient( 'ec_wire_counts_festival' );
				WP_CLI::log( $cached !== false
					? sprintf( '  blog/wire-festivals: WARM (%d terms)', count( $cached ) )
					: '  blog/wire-festivals: COLD' );
				restore_current_blog();
			}
		} else {
			WP_CLI::log( 'No badge counts configured for this site.' );
		}
	}
}
