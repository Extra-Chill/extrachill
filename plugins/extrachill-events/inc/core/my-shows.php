<?php
/**
 * My Shows — Concert History Page
 *
 * Registers /my-shows/ route on the events site. Auth-gated page where
 * logged-in users can view their concert history, stats, and leaderboards.
 *
 * @package ExtraChillEvents
 * @since 0.18.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register My Shows rewrite rule and query var.
 */
function ec_events_my_shows_rewrite() {
	if ( ! ec_is_events_site() ) {
		return;
	}

	add_rewrite_rule( '^my-shows/?$', 'index.php?ec_my_shows=1', 'top' );
}
add_action( 'init', 'ec_events_my_shows_rewrite' );

/**
 * Register ec_my_shows query var.
 *
 * @param array $vars Existing query vars.
 * @return array Modified query vars.
 */
function ec_events_my_shows_query_vars( $vars ) {
	$vars[] = 'ec_my_shows';
	return $vars;
}
add_filter( 'query_vars', 'ec_events_my_shows_query_vars' );

/**
 * Load My Shows template when query var is set.
 *
 * @param string $template Default template path.
 * @return string My Shows template path or default.
 */
function ec_events_my_shows_template( $template ) {
	if ( ! get_query_var( 'ec_my_shows' ) ) {
		return $template;
	}

	if ( ! ec_is_events_site() ) {
		return $template;
	}

	// Redirect to login if not authenticated.
	if ( ! is_user_logged_in() ) {
		$login_url = function_exists( 'ec_get_site_url' )
			? ec_get_site_url( 'events' ) . '/login/'
			: wp_login_url();

		wp_safe_redirect( $login_url . '?redirect_to=' . rawurlencode( home_url( '/my-shows/' ) ) );
		exit;
	}

	return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/my-shows.php';
}
add_filter( 'template_include', 'ec_events_my_shows_template' );

/**
 * Set page title for My Shows.
 *
 * @param array $title_parts Title parts array.
 * @return array Modified title parts.
 */
function ec_events_my_shows_title( $title_parts ) {
	if ( ! get_query_var( 'ec_my_shows' ) ) {
		return $title_parts;
	}

	$title_parts['title'] = __( 'My Shows', 'extrachill-events' );
	return $title_parts;
}
add_filter( 'document_title_parts', 'ec_events_my_shows_title' );

/**
 * Add My Shows link to the secondary header nav (logged-in users only).
 *
 * @param array $items Secondary header items.
 * @return array Modified items.
 */
function ec_events_my_shows_nav_item( $items ) {
	if ( ! ec_is_events_site() ) {
		return $items;
	}

	if ( ! is_user_logged_in() ) {
		return $items;
	}

	$items[] = array(
		'url'      => home_url( '/my-shows/' ),
		'label'    => __( 'My Shows', 'extrachill-events' ),
		'priority' => 5,
	);

	return $items;
}
add_filter( 'extrachill_secondary_header_items', 'ec_events_my_shows_nav_item' );

/**
 * Enqueue My Shows page assets.
 */
function ec_events_my_shows_assets() {
	if ( ! get_query_var( 'ec_my_shows' ) ) {
		return;
	}

	$css_path = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/my-shows.css';
	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'extrachill-events-my-shows',
			EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/my-shows.css',
			array(),
			filemtime( $css_path ),
			'all'
		);
	}

	$js_path = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/js/my-shows.js';
	if ( file_exists( $js_path ) ) {
		wp_enqueue_script(
			'extrachill-events-my-shows',
			EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/my-shows.js',
			array( 'wp-api-fetch' ),
			filemtime( $js_path ),
			true
		);

		wp_localize_script(
			'extrachill-events-my-shows',
			'ecMyShows',
			array(
				'userId'   => get_current_user_id(),
				'eventsUrl' => function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'events' ) : home_url(),
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'ec_events_my_shows_assets' );
