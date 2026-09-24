<?php
/**
 * Rig-level compatibility shim for this scenario -- NOT a product fix.
 *
 * Extra Chill Network is architecturally a multisite plugin and calls
 * `switch_to_blog()` unconditionally in at least 80 call sites across 28
 * files with no `is_multisite()` / `function_exists()` guard -- confirmed
 * by running this exact journey first without this shim and hitting a hard
 * PHP fatal (`Call to undefined function switch_to_blog()`,
 * inc/community-activity/community-activity.php:39) that 500'd every
 * single front-end page render, including the event page itself. This is a
 * single-origin WP Codebox fixture (the sanctioned shape for this journey
 * -- see tests/wp-codebox/README.md), so `switch_to_blog()` and
 * `restore_current_blog()` are core WordPress functions that simply do not
 * exist outside multisite.
 *
 * The real defect is filed against extrachill-network with the concrete
 * repro; it is not fixed here. This mu-plugin only keeps *this scenario's*
 * front end from fataling on every page load so the RSVP journey itself --
 * the actual thing being evaluated -- can run. In a true single-site
 * install there is only ever one blog to "switch" to, so a no-op is not a
 * misleading approximation of that constraint, only a way to survive it.
 *
 * Loaded from `/wordpress/wp-content/mu-plugins/` (must-use, always active,
 * loaded before regular plugins) so it is present for every page request --
 * a one-off `wordpress.run-php` definition would not survive past that
 * single eval into the browser's own page-load requests.
 *
 * @package ExtraChillEvents
 */

if ( ! function_exists( 'switch_to_blog' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- shimming a WordPress core multisite function absent from this single-site runtime; $new_blog_id is required to match switch_to_blog()'s real signature, and is unused because there is only ever one blog to "switch" to.
	function switch_to_blog( $new_blog_id ) {
		return true;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- see switch_to_blog() above.
		return true;
	}
}

/*
 * Extra Chill Network's canonical blog-ID map (inc/core/blog-ids.php) hard-
 * codes EC_BLOG_ID_EVENTS = 7 via `if ( ! defined(...) ) { define(...) }` --
 * a guard that exists precisely so a consumer can override it earlier.
 * This single-site fixture's only blog is 1, and `ec_is_events_site()`
 * (extrachill-events/inc/core/bootstrap.php) gates the entire front-end
 * attendance-button composition on `get_current_blog_id() === EC_BLOG_ID_EVENTS`.
 * Left unset, that guard silently no-ops the button on every page -- not a
 * product bug (production is always multisite with a real, distinct events
 * blog), but a real single-site-fixture gap this rig needs to close to
 * exercise real front-end RSVP behavior at all. Mu-plugins load before
 * regular plugins, so this define() wins the existing guard.
 */
if ( ! defined( 'EC_BLOG_ID_EVENTS' ) ) {
	define( 'EC_BLOG_ID_EVENTS', 1 );
}
