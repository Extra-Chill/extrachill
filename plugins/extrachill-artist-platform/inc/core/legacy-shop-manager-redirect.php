<?php
/**
 * Legacy /manage-shop/ redirect.
 *
 * The shop manager moved to the Shop site (`shop.extrachill.com/manage`).
 * Old links, bookmarks, emails and in-flight Stripe onboarding returns still
 * land on `artist.extrachill.com/manage-shop/`; send them to the new manager
 * with the artist the old page would have opened preselected.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where a /manage-shop/ visit goes now.
 *
 * @param int   $user_id Current user ID (0 when logged out).
 * @param array $query   Request query args; `stripe_refresh` is carried over.
 * @return string New manager URL, or '' when the Shop URL is unavailable.
 */
function ec_artist_platform_legacy_shop_manager_url( $user_id, array $query = array() ) {
	if ( ! function_exists( 'ec_get_shop_manager_url' ) ) {
		return '';
	}

	$seller    = null;
	$artist_id = ( $user_id > 0 && function_exists( 'ec_get_latest_artist_for_user' ) ) ? (int) ec_get_latest_artist_for_user( $user_id ) : 0;
	if ( $artist_id > 0 ) {
		$seller = array(
			'type' => 'artist',
			'id'   => $artist_id,
		);
	}

	$url = ec_get_shop_manager_url( $seller );
	if ( '' !== $url && ! empty( $query['stripe_refresh'] ) ) {
		$url = add_query_arg( 'stripe_refresh', '1', $url );
	}
	return $url;
}

/**
 * Whether the current request is the retired /manage-shop/ path.
 *
 * Matches the path as well as the page so the redirect keeps working after
 * the old page is removed.
 *
 * @return bool
 */
function ec_artist_platform_is_legacy_shop_manager_request() {
	global $wp;
	$path = ( $wp instanceof WP && is_string( $wp->request ) ) ? trim( $wp->request, '/' ) : '';
	return 'manage-shop' === $path || is_page( 'manage-shop' );
}

/** Redirect the retired artist-site shop manager to the Shop site. */
function ec_artist_platform_redirect_legacy_shop_manager() {
	if ( ! ec_artist_platform_is_legacy_shop_manager_request() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag carried to the new URL.
	$query = isset( $_GET['stripe_refresh'] ) ? array( 'stripe_refresh' => '1' ) : array();
	$url   = ec_artist_platform_legacy_shop_manager_url( get_current_user_id(), $query );
	if ( '' === $url ) {
		return;
	}

	// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Shop site host resolved from the network domain map.
	wp_redirect( $url, 302 );
	exit;
}
add_action( 'template_redirect', 'ec_artist_platform_redirect_legacy_shop_manager', 5 );
