<?php
/**
 * Public event and calendar experience registration.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Loads the established public Events feature modules once. */
final class PublicExperienceProvider {

	/**
	 * Whether public modules have loaded.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/** Register public UI, routing, discovery, and concert tracking features. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/events-by-term-taxonomy-context.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/cache-groups.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/page-cache.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/nav.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/priority-venue-ordering.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/priority-event-ordering.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/location-meta.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/archive-map.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/location-map.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/venue-map.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/artist-map.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/location-seo.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/account-market.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/local-scene-digest.php';
		extrachill_events_init_local_scene_digest();
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/near-me.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/discovery-pages.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/router-pages.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/vendor-request-workspace.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/booking-console.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/location-normalizer.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/calendar-stats.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/admin/priority-venues.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/admin/priority-events.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/single-event/breadcrumbs.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/single-event/related-events.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/single-event/network-bridge.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/single-event/share-button.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/home/actions.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/concert-tracking-integration.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/my-shows.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/my-shows-scope-token.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/my-shows-calendar-filter.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/my-shows-map-filter.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/bootstrap.php';
	}
}
